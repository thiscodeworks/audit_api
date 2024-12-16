<?php

class AuthController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['username']) || !isset($data['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Username and password are required']);
            return;
        }

        $stmt = $this->db->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->execute([$data['username']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($data['password'], $user['password'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }

        $token = bin2hex(random_bytes(32));
        
        // Store token in database (you might want to add expiration)
        $stmt = $this->db->prepare("UPDATE users SET auth_token = ? WHERE id = ?");
        $stmt->execute([$token, $user['id']]);

        echo json_encode(['token' => $token]);
    }
} 