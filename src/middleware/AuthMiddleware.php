<?php
require_once __DIR__ . '/../utils/JWTHandler.php';

class AuthMiddleware {
    private $jwt;

    public function __construct() {
        $this->jwt = new JWTHandler();
    }

    public function authenticate() {
        $headers = array_change_key_case(getallheaders(), CASE_UPPER);
        
        if (!isset($headers['AUTHORIZATION'])) {
            http_response_code(401);
            echo json_encode(['error' => 'No authorization token provided']);
            return false;
        }

        $token = str_replace('Bearer ', '', $headers['AUTHORIZATION']);
        $decoded = $this->jwt->validateToken($token);
        
        if (!$decoded) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            return false;
        }

        return true;
    }
} 