<?php
// Development error handling
if (getenv('APP_ENV') === 'local' || getenv('APP_ENV') === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Set error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require_once __DIR__ . '/../src/utils/Env.php';

// Load environment variables
try {
    Env::load(__DIR__ . '/../.env');
} catch (Exception $e) {
    error_log("Notice: " . $e->getMessage());
}

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../src/config/database.php';
require_once __DIR__ . '/../src/routes/Router.php';
require_once __DIR__ . '/../src/controllers/AuditController.php';
require_once __DIR__ . '/../src/controllers/ChatController.php';
require_once __DIR__ . '/../src/controllers/MessageController.php';
require_once __DIR__ . '/../src/controllers/AuthController.php';
require_once __DIR__ . '/../src/controllers/UserController.php';
require_once __DIR__ . '/../src/controllers/AnalyzeController.php';
require_once __DIR__ . '/../src/controllers/OrganizationController.php';
require_once __DIR__ . '/../src/middleware/AuthMiddleware.php';

$router = new Router();
$authMiddleware = new AuthMiddleware();

// Register all routes
function registerRoutes($router) {
    // Auth routes
    $router->post('/auth/login', 'AuthController@login', false); // Public route
    $router->get('/auth/me', 'AuthController@me');

    // User routes
    $router->get('/users', 'UserController@list');
    $router->post('/users', 'UserController@create');

    // Organization routes
    $router->get('/organizations', 'OrganizationController@list');

    // Audit routes
    $router->get('/audits', 'AuditController@list');
    $router->get('/audit/{uuid}/stats', 'AuditController@stats');
    $router->get('/audit/{uuid}/users', 'AuditController@users');
    $router->get('/audit/{uuid}/chats', 'AuditController@chats');
    $router->get('/audit/{uuid}/available-users', 'AuditController@getAvailableUsers');
    $router->post('/audit/{uuid}/assign', 'AuditController@assignUser');
    $router->post('/audit/post', 'AuditController@create');
    $router->get('/audit/{uuid}/get', 'AuditController@get', false);
    $router->delete('/audit/{uuid}/delete', 'AuditController@delete');
    $router->put('/audit/{uuid}/edit', 'AuditController@edit');
    $router->post('/audit/{uuid}/start', 'AuditController@start',false);
    $router->post('/audit/find', 'AuditController@find', false);
    $router->post('/audit/{uuid}/mail', 'AuditController@mail');

    // Chat routes
    $router->get('/chat/list', 'ChatController@list');
    $router->get('/chat/{uuid}/get', 'ChatController@get',false);
    $router->post('/message/{uuid}/send', 'MessageController@send',false);

    // Analysis endpoints
    $router->get('/analyze/run', 'AnalyzeController@run',false);
    $router->post('/analyze/chat/{uuid}', 'AnalyzeController@analyzeChat',false);
    $router->get('/analyze/chat/{uuid}/detail', 'AnalyzeController@getChatDetail');
    $router->get('/analyze/dashboard', 'AnalyzeController@getDashboardStats');
}

// Register all routes
registerRoutes($router);

// Check if the current route requires authentication
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$currentMethod = $_SERVER['REQUEST_METHOD'];

// Check if route requires authentication
if ($router->requiresAuth($currentMethod, $currentPath)) {
    if (!$authMiddleware->authenticate()) {
        exit();
    }
}

// Handle the request
$router->handleRequest();