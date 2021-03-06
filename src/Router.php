<?php

namespace Jasny;

/**
 * Route pretty URLs to correct controller
 * 
 * Wildcards:
 *  ?          Single character
 *  #          One or more digits
 *  *          One or more characters
 *  **         Any number of subdirs
 *  [abc]      Match character 'a', 'b' or 'c'
 *  [a-z]      Match character 'a' to 'z'
 *  {png,gif}  Match 'png' or 'gif'
 * 
 * Escape characters using URL encode (so %5B for '[')
 */
class Router
{
    /**
     * Specific routes
     * @var array 
     */
    protected $routes = [];

    /**
     * Method for route
     * @var string 
     */
    protected $method;

    /**
     * Webroot subdir from DOCUMENT_ROOT.
     * @var string
     */
    protected $base;

    /**
     * URL to route
     * @var string 
     */
    protected $url;

    /**
     * Variables from matched route (cached)
     * @var object 
     */
    protected $route;
    
    
    /**
     * Class constructor
     * 
     * @param array $routes  Array with route objects
     */
    public function __construct($routes=null)
    {
        if (isset($routes)) $this->setRoutes($routes);
    }
    
    /**
     * Set the routes
     * 
     * @param array $routes  Array with route objects
     * @return Router
     */
    public function setRoutes($routes)
    {
        if (is_object($routes)) $routes = get_object_vars($routes);
        
        foreach ($routes as &$route) {
            if ($route instanceof \Closure) $route = (object)['fn' => $route];
        }
        
        $this->routes = $routes;
        $this->route = null;
        
        return $this;
    }

    /**
     * Add routes to existing list
     * 
     * @param array  $routes  Array with route objects
     * @param string $root    Specify the root dir for routes
     * @return Router
     */
    public function addRoutes($routes, $root=null)
    {
        if (is_object($routes)) $routes = get_object_vars($routes);
        
        foreach ($routes as $path=>$route) {
            if (!empty($root)) $path = $root . $path;
            
            if (isset($this->routes[$path])) {
                trigger_error("Route $path is already defined.", E_USER_WARNING);
                continue;
            }
            
            if ($route instanceof \Closure) $route = (object)['fn' => $route];
            
            $this->routes[$path] = $route;
        }
        
        return $this;
    }
    
    /**
     * Get a list of all routes
     * 
     * @return object
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    
    /**
     * Set the method to route
     * 
     * @param string $method
     * @return Router
     */
    public function setMethod($method)
    {
        $this->method = $method;
        $this->route = null;

        return $this;
    }

    /**
     * Get the method to route.
     * Defaults to REQUEST_METHOD, which can be overwritten by $_POST['_method'].
     * 
     * @return string
     */
    public function getMethod()
    {
        if (!isset($this->method)) $this->method = Request::getMethod();
        return $this->method;
    }
    
    
    /**
     * Set the webroot subdir from DOCUMENT_ROOT.
     * 
     * @param string $dir
     * @return Router
     */
    public function setBase($dir)
    {
        $this->base = rtrim($dir, '/');
        $this->route = null;

        return $this;
    }

    /**
     * Get the webroot subdir from DOCUMENT_ROOT.
     * 
     * @return string
     */
    public function getBase()
    {
        return $this->base;
    }

    /**
     * Add a base path to the URL if the webroot isn't the same as the webservers document root
     * 
     * @param string $url
     * @return string
     */
    public function rebase($url)
    {
        return ($this->getBase() ?: '/') . ltrim($url, '/');
    }


    /**
     * Set the URL to route
     * 
     * @param string $url
     * @return Router
     */
    public function setUrl($url)
    {
        $this->url = $url;
        $this->route = null;

        return $this;
    }

    /**
     * Get the URL to route.
     * Defaults to REQUEST_URI.
     * 
     * @return string
     */
    public function getUrl()
    {
        if (!isset($this->url)) $this->url = urldecode(preg_replace('/\?.*$/', '', $_SERVER['REQUEST_URI']));
        return $this->url;
    }

    /**
     * Split the URL and return a part
     * 
     * @param int $i  Part number, starts at 1
     * @return string
     */
    public function getUrlPart($i)
    {
        $parts = $this->splitUrl($this->getUrl());
        return $parts[$i - 1];
    }
    
    
    /**
     * Check if the router has been used.
     * 
     * @return boolean
     */
    public function isUsed()
    {
        return isset($this->route);
    }


    /**
     * Get a matching route.
     * 
     * @return object
     */
    public function getRoute()
    {
        if (isset($this->route)) return $this->route;

        $method = $this->getMethod();
        $url = $this->getUrl();
        
        if ($this->getBase()) {
            $url = '/' . preg_replace('~^' . preg_quote(trim($this->getBase(), '/'), '~') . '~', '', ltrim($url, '/'));
        }

        $match = $this->findRoute($method, $url);

        if ($match) {
            $this->route = $this->bind($this->routes[$match], $this->splitUrl($url));
            $this->route->route = $match;
        } else {
            $this->route = false;
        }

        return $this->route;
    }

    /**
     * Get a property of the matching route.
     * 
     * @param string $prop  Property name
     * @return mixed
     */
    public function get($prop)
    {
        $route = $this->getRoute();
        return isset($route->$prop) ? $route->$prop : null;
    }
    
    
    /**
     * Execute the action of the given route.
     * 
     * @param object $route
     * @param object $overwrite
     * @return boolean|mixed  Whatever the controller returns or true on success
     */
    public function routeTo($route, $overwrite=[])
    {
        if (!is_object($route)) {
            $match = $this->findRoute(null, $route);
            if (!isset($match) || !isset($this->routes[$match])) return false;
            $route = $this->routes[$match];
        }

        foreach ($overwrite as $key=>$value) {
            $route->$key = $value;
        }
        
        if (isset($route->controller)) return $this->routeToController($route);
        if (isset($route->fn)) return $this->routeToCallback($route);
        if (isset($route->file)) return $this->routeToFile($route);
        
        $warn = "Failed to route using '{$route->route}': Neither 'controller', 'fn' or 'file' is set";
        trigger_error($warn, E_USER_WARNING);
        
        return false;
    }

    /**
     * Route to controller action
     * 
     * @param object $route
     * @return mixed|boolean
     */
    protected function routeToController($route)
    {
        $class = $this->getControllerClass($route->controller);
        $method = $this->getActionMethod(isset($route->action) ? $route->action : null);
        
        if (!class_exists($class)) return false;

        $controller = new $class($this);
        if (!is_callable([$controller, $method])) return false;

        if (isset($route->args)) {
            $args = $route->args;
        } elseif (method_exists($controller, $method)) {
            $args = static::getFunctionArgs($route, new \ReflectionMethod($controller, $method));
        }

        $ret = call_user_func_array([$controller, $method], $args);
        return isset($ret) ? $ret : true;
    }
    
    /**
     * Route to a callback function
     * 
     * @param object $route
     * @return mixed|boolean
     */
    protected function routeToCallback($route)
    {
        if (!is_callable($route->fn)) {
            trigger_error("Failed to route using '{$route->route}': Invalid callback.", E_USER_WARNING);
            return false;
        }
        
        if (isset($route->args)) {
            $args = $route->args;
        } elseif (is_array($route->fn)) {
            $args = static::getFunctionArgs($route, new \ReflectionMethod($route->fn[0], $route->fn[1]));
        } elseif (function_exists($route->fn)) {
            $args = static::getFunctionArgs($route, new \ReflectionFunction($route->fn));
        }
        
        return call_user_func_array($route->fn, $args);
    }
    

    /**
     * Execute the action.
     * 
     * @todo Check if route would be available for other HTTP methods to respond with a 405
     * 
     * @return mixed  Whatever the controller returns
     */
    public function execute()
    {
        $route = $this->getRoute();
        if ($route) $ret = $this->routeTo($route);
        
        $httpCode = 404; // or 405?
        
        if (!isset($ret) || $ret === false) return $this->notFound(null, $httpCode);
        return $ret;
    }

    
    /**
     * Redirect to another page
     * 
     * @param string $url 
     * @param int    $httpCode  301 (Moved Permanently), 303 (See Other) or 307 (Temporary Redirect)
     */
    public function redirect($url, $httpCode=303)
    {
        if (ob_get_level() > 1) ob_end_clean();
        
        if ($url[0] === '/' && substr($url, 0, 2) !== '//') $url = $this->rebase($url);
        
        http_response_code((int)$httpCode);
        header("Location: $url");
        
        header('Content-Type: text/html');
        echo 'You are being redirected to <a href="' . $url . '">' . $url . '</a>';
    }

    /**
     * Give a 400 Bad Request response
     * 
     * @param string $message
     * @param int    $httpCode  Alternative HTTP status code, eg. 406 (Not Acceptable)
     * @param mixed  $..        Additional arguments are passed to action        
     */
    public function badRequest($message, $httpCode=400)
    {
        if (!$this->routeTo(400, ['args'=>func_get_args()])) {
            self::outputError($httpCode, $message);
        }
    }

    /**
     * Route to 401, otherwise result in a 403 forbidden.
     * Note: While the 401 route is used, we don't respond with a 401 http status code.
     */
    public function requireLogin()
    {
        $this->routeTo(401) || $this->forbidden();
    }
    
    /**
     * Give a 403 Forbidden response and exit
     * 
     * @param string $message
     * @param int    $httpCode  Alternative HTTP status code
     * @param mixed  $..        Additional arguments are passed to action        
     */
    public function forbidden($message=null, $httpCode=403)
    {
        if (ob_get_level() > 1) ob_end_clean();
        
        if (!$this->routeTo(403, ['args'=>func_get_args()])) {
            if (!isset($message)) $message = "Sorry, you are not allowed to view this page";
            self::outputError($httpCode, $message);
        }
    }
    
    /**
     * Give a 404 Not Found response
     * 
     * @param string $message
     * @param int    $httpCode  Alternative HTTP status code, eg. 410 (Gone)
     * @param mixed  $..        Additional arguments are passed to action        
     */
    public function notFound($message=null, $httpCode=404)
    {
        if (ob_get_level() > 1) ob_end_clean();

        if (!$this->routeTo(404, ['args'=>func_get_args()])) {
            if (!isset($message)) $message = $httpCode === 405 ?
                "Sorry, this action isn't supported" :
                "Sorry, this page does not exist";
            
            self::outputError($httpCode, $message);
        }
    }

    /**
     * Give a 5xx Server Error response
     * 
     * @param string     $message
     * @param int|string $httpCode  HTTP status code, eg. "500 Internal Server Error" or 503
     * @param mixed      $..        Additional arguments are passed to action        
     */
    public function error($message=null, $httpCode=500)
    {
        if (ob_get_level() > 1) ob_end_clean();
        
        if (!$this->routeTo(500, ['args'=>func_get_args()])) {
            if (!isset($message)) $message = "Sorry, an unexpected error occured";
            self::outputError($httpCode, $message);
        }
    }
    
    
    /**
     * Get the arguments for a function from a route using reflection
     * 
     * @param object $route
     * @param \ReflectionFunctionAbstract $refl
     * @return array
     */
    protected static function getFunctionArgs($route, \ReflectionFunctionAbstract $refl)
    {
        $args = [];
        $params = $refl->getParameters();

        foreach ($params as $param) {
            $key = $param->name;

            if (property_exists($route, $key)) {
                $value = $route->{$key};
            } else {
                if (!$param->isOptional()) {
                    $fn = $refl instanceof \ReflectionMethod
                        ? $refl->getDeclaringClass()->getName() . ':' . $refl->getName()
                        : $refl->getName();
                    
                    trigger_error("Missing argument '$key' for $fn()", E_USER_WARNING);
                }
                
                $value = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
            }

            $args[$key] = $value;
        }
        
        return $args;
    }
    
    /**
     * Get the class name of the controller
     * 
     * @param string $controller
     * @return string
     */
    protected static function getControllerClass($controller)
    {
        return \Jasny\studlycase($controller) . 'Controller';
    }
    
    /**
     * Get the method name of the action
     * 
     * @param string $action
     * @return string
     */
    protected static function getActionMethod($action)
    {
        return \Jasny\camelcase($action) . 'Action';
    }
    
    
    // Proxy methods for Jasny\DB\Request. Allows overloading for customized Request class.
    
    /**
     * Get the output format.
     * Tries 'Content-Type' response header, otherwise uses 'Accept' request header.
     * 
     * @param string $as  'short' or 'mime'
     * @return string
     */
    protected static function getOutputFormat($as)
    {
        return Request::getOutputFormat($as);
    }
    
    /**
     * Output an HTTP error
     * 
     * @param int           $httpCode  HTTP status code
     * @param string|object $message
     * @param string        $format    The output format (auto detect by default)
     */
    protected static function outputError($httpCode, $message, $format=null)
    {
        return Request::outputError($httpCode, $message, $format);
    }
}
