<?php
class Router {
    private $routes = [];
    private $params = [];

    public function get($path, $handler) {
        $this->routes['GET'][$path] = $handler;
    }

    public function post($path, $handler) {
        $this->routes['POST'][$path] = $handler;
    }

    public function put($path, $handler) {
        $this->routes['PUT'][$path] = $handler;
    }

    public function delete($path, $handler) {
        $this->routes['DELETE'][$path] = $handler;
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        error_log("Request Method: " . $method);
        error_log("Request Path: " . $path);
        error_log("Available Routes: " . print_r($this->routes, true));

        if ($method === 'OPTIONS') {
            header("HTTP/1.1 200 OK");
            exit();
        }

        foreach ($this->routes[$method] ?? [] as $route => $handler) {
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $route);
            $pattern = str_replace('/', '\/', $pattern);
            
            error_log("Checking pattern: ^" . $pattern . "$");
            
            if (preg_match('/^' . $pattern . '$/', $path, $matches)) {
                $this->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                list($controller, $action) = explode('@', $handler);
                
                error_log("Match found! Controller: $controller, Action: $action");
                
                $controllerInstance = new $controller();
                call_user_func_array([$controllerInstance, $action], [$this->params]);
                return;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
    }
} 