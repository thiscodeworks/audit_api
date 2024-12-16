<?php

class AuthMiddleware {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function authenticate() {
        $headers = getallheaders();
        
        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(['error' => 'No authorization token provided']);
            return false;
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        
        $stmt = $this->db->prepare("SELECT id FROM users WHERE auth_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token']);
            return false;
        }

        return true;
    }
} 