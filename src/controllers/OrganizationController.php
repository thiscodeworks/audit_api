<?php

require_once __DIR__ . '/../models/Organization.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/JWTHandler.php';

class OrganizationController {
    private $jwt;

    public function __construct() {
        $this->jwt = new JWTHandler();
    }

    public function list() {
        $headers = getallheaders();
        $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;
        
        if (!$token) {
            http_response_code(401);
            echo json_encode(['error' => 'No authorization token provided']);
            return;
        }

        $decoded = $this->jwt->validateToken($token);
        if (!$decoded) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            return;
        }

        // Get organizations for user
        $organizations = Organization::getForUser($decoded->data->id);
        
        echo json_encode([
            'organizations' => $organizations
        ]);
    }
} 