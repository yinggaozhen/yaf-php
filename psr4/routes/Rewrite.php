<?php

namespace Yaf\Route;

use const YAF\ERR\TYPE_ERROR;
use Yaf\Request_Abstract;
use Yaf\Route_Interface;
use Yaf\Router;

/**
 * @link https://www.php.net/manual/en/class.yaf-route-rewrite.php
 */
final class Rewrite implements Route_Interface
{
    /**
     * @var string
     */
    protected $_route;

    protected $_default;

    protected $_verify;

    /**
     * Rewrite constructor.
     *
     * @link https://www.php.net/manual/en/yaf-route-rewrite.construct.php
     *
     * @param string $match
     * @param array $route
     * @param array|null $verify
     * @throws \Exception
     */
    public function __construct($match, array $route, array $verify = null)
    {
        if (!is_string($match) || empty($match)) {
            yaf_trigger_error(TYPE_ERROR, "Expects a valid string match as the first parameter");
            return false;
        }

        if ($verify && !is_array($verify)) {
            yaf_trigger_error(TYPE_ERROR, "Expects an array as third parameter");
            return false;
        }

        $this->_route = $match;
        $this->_default = $route;

        if (!$verify) {
            $this->_verify = null;
        } else {
            $this->_verify = $verify;
        }
    }

    /**
     * @link https://www.php.net/manual/en/yaf-route-rewrite.route.php
     *
     * @param null|Request_Abstract $request
     * @return bool
     */
    public function route($request)
    {
        if (!$request || !is_object($request) || !($request instanceof Request_Abstract)) {
            trigger_error(sprintf('Expect a %s iniInstance', get_class($request)), E_USER_WARNING);
            return false;
        }

        return (bool) ($this->_rewriteRoute($request));
    }

    /**
     * @link https://www.php.net/manual/en/yaf-route-rewrite.assemble.php
     *
     * @param array $info
     * @param array|null $query
     * @return null|string
     */
    public function assemble(array $info, array $query = null)
    {
        if ($str = $this->_assemble($info, $query)) {
            return $str;
        }

        return null;
    }

    // ================================================== 内部方法 ==================================================

    /**
     * @param array $info
     * @param array|null $query
     * @return null|string
     */
    private function _assemble($info, $query)
    {
        $query_str = $wildcard = '';
        $uri = $match = $this->_route;

        foreach (explode(Route_Interface::YAF_ROUTER_URL_DELIMIETER, $match) as $seg) {
            if (empty($seg)) {
                continue;
            }

            if ($seg[0] == '*') {
                foreach ($info as $key => $zv) {
                    if (!$key) {
                        continue;
                    }

                    if (is_string($zv)) {
                        $wildcard .= substr($key, 1) . Route_Interface::YAF_ROUTER_URL_DELIMIETER;
                        $wildcard .= $zv;
                        $wildcard .= Route_Interface::YAF_ROUTER_URL_DELIMIETER;
                    }
                }

                $uri = str_replace('*', $wildcard, $uri);
            }

            if ($seg[0] == ':' && isset($info[$seg])) {
                $uri = str_replace($seg, $info[$seg], $uri);
                unset($info[$seg]);
            }
        }

        if ($query && is_array($query)) {
            $query_str .= '?' . http_build_query($query);
        }

        return $uri . $query_str;
    }

    private function _rewriteRoute(Request_Abstract $request)
    {
        $zuri = $request->getRequestUri();
        $baseUri = $request->getBaseUri();

        if ($baseUri && is_string($baseUri) && stripos($zuri, $baseUri) !== false) {
            $requestUri = substr($zuri, strlen($baseUri));
        } else {
            $requestUri = $zuri;
        }

        $args = null;
        if (!$this->_rewriteMatch($requestUri, $args)) {
            return 0;
        } else {
            $routes = $this->_default;

            $module = $routes['module'] ?? null;
            if (isset($module) && is_string($module)) {
                if ($module[0] != ':') {
                    $request->setModuleName($module);
                } else {
                    $m = $args[substr($module, 1)] ?? null;
                    if (isset($m) && is_string($m)) {
                        $request->setModuleName($m);
                    }
                }
            }

            $controller = $routes['controller'] ?? null;
            if (isset($controller) && is_string($controller)) {
                if ($controller[0] != ':') {
                    $request->setControllerName($controller);
                } else {
                    $c = $args[substr($controller, 1)] ?? null;
                    if (isset($c) && is_string($c)) {
                        $request->setControllerName($c);
                    }
                }
            }

            $action = $routes['action'] ?? null;
            if (isset($action) && is_string($action)) {
                if ($action[0] != ':') {
                    $request->setActionName($action);
                } else {
                    $a = $args[substr($action, 1)] ?? null;
                    if (isset($a) && is_string($a)) {
                        $request->setActionName($a);
                    }
                }
            }

            Request_Abstract::_setParamsMulti($request, $args);
        }

        return 1;
    }

    /**
     * @param string $uri
     * @param $result
     * @return int
     */
    private function _rewriteMatch($uri, &$result)
    {
        $pattern = '';

        if (!strlen($uri)) {
            return 0;
        }

        $match = $this->_route;

        $pattern .= Route_Interface::YAF_ROUTE_REGEX_DILIMITER . '^';
        foreach (explode(Route_Interface::YAF_ROUTER_URL_DELIMIETER, $match) as $seg) {
            if (strlen($seg)) {
                $pattern .= Route_Interface::YAF_ROUTER_URL_DELIMIETER;

                if ($seg[0] == '*') {
                    $pattern .= "(?P<__yaf_route_rest>.*)";
                    break;
                }

                if ($seg[0] == ':') {
                    $pattern .= "(?P<" . substr($seg, 1) . ">[^" . Route_Interface::YAF_ROUTER_URL_DELIMIETER . "]+)";
                } else {
                    $pattern .= $seg;
                }
            }
        }
        $pattern .= Route_Interface::YAF_ROUTE_REGEX_DILIMITER . 'i';

        $matched = preg_match_all($pattern, $uri, $matches);
        if (!$matched) {
            return 0;
        }

        foreach ($matches as $key => $pzval) {
            // 只遍历字符串
            if (is_numeric($key) || empty($key)) {
                continue;
            }

            if ($key == '__yaf_route_rest') {
                $args = null;
                Router::_parseParameters($pzval[0], $args);
                $result = array_merge($result, $args);
            } else {
                $result[$key] = $pzval[0];
            }
        }

        return 1;
    }
}
