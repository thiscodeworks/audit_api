<?php
require_once __DIR__ . '/../src/utils/Env.php';

// Load environment variables
try {
    Env::load(__DIR__ . '/../.env');
} catch (Exception $e) {
    // If .env doesn't exist in production, we'll use system environment variables
    error_log("Notice: " . $e->getMessage());
}

header('Content-Type: application/json');

require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/routes/Router.php';
require_once __DIR__ . '/../src/controllers/AuditController.php';

$router = new Router();

$router->get('/audit/{uuid}/get', 'AuditController@get');
$router->post('/audit/{uuid}/start', 'AuditController@start');

$router->handleRequest(); 