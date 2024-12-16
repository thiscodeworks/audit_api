<?php
require_once __DIR__ . '/../src/utils/Env.php';

// Load environment variables
try {
    Env::load(__DIR__ . '/../.env');
} catch (Exception $e) {
    error_log("Notice: " . $e->getMessage());
}

header('Content-Type: application/json');

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/routes/Router.php';
require_once __DIR__ . '/../src/controllers/AuditController.php';
require_once __DIR__ . '/../src/controllers/ChatController.php';
require_once __DIR__ . '/../src/controllers/MessageController.php';
require_once __DIR__ . '/../src/controllers/AuthController.php';
require_once __DIR__ . '/../src/middleware/AuthMiddleware.php';

$router = new Router();
$authMiddleware = new AuthMiddleware();

// Auth route
$router->post('/admin/auth', 'AuthController@login');

// Protect all /admin/* routes except /admin/auth
if (str_starts_with($_SERVER['REQUEST_URI'], '/admin/') && $_SERVER['REQUEST_URI'] !== '/admin/auth') {
    if (!$authMiddleware->authenticate()) {
        exit();
    }
}

// Audit routes
$router->get('/audit/list', 'AuditController@list');
$router->post('/audit/post', 'AuditController@create');
$router->get('/audit/{uuid}/get', 'AuditController@get');
$router->delete('/audit/{uuid}/delete', 'AuditController@delete');
$router->put('/audit/{uuid}/edit', 'AuditController@edit');
$router->post('/audit/{uuid}/start', 'AuditController@start');

// Chat routes
$router->get('/chat/list', 'ChatController@list');
$router->get('/chat/{uuid}/get', 'ChatController@get');
$router->post('/message/{uuid}/send', 'MessageController@send');

$router->handleRequest();