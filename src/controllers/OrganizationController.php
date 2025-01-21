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
        $headers = array_change_key_case(getallheaders(), CASE_UPPER);
        $token = str_replace('Bearer ', '', $headers['AUTHORIZATION']);
        $decoded = $this->jwt->decodeToken($token); // Just decode without validation since middleware already validated
        
        // Get organizations for user
        $organizations = Organization::getForUser($decoded->data->id);
        
        echo json_encode([
            'organizations' => $organizations
        ]);
    }
} 