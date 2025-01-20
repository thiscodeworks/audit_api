<?php
require_once __DIR__ . '/../utils/JWTHandler.php';

class AuthController {
    private $db;
    private $jwt;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->jwt = new JWTHandler();
    }

    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['username']) || !isset($data['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Username and password are required']);
            return;
        }

        $stmt = $this->db->prepare("SELECT id, username, password, name, company FROM users WHERE username = ?");
        $stmt->execute([$data['username']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($data['password'], $user['password'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }

        // Generate JWT token
        $token = $this->jwt->generateToken([
            'id' => $user['id'],
            'username' => $user['username']
        ]);

        echo json_encode([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'name' => $user['name'],
                'company' => $user['company']
            ]
        ]);
    }

    public function me() {
        $headers = getallheaders();
        
        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(['error' => 'No authorization token provided']);
            return;
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $decoded = $this->jwt->validateToken($token);
        
        if (!$decoded) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            return;
        }

        $stmt = $this->db->prepare("SELECT id, username, name, company, created_at FROM users WHERE id = ?");
        $stmt->execute([$decoded->data->id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        echo json_encode([
            'data' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'name' => $user['name'],
                'company' => $user['company'],
                'created_at' => $user['created_at']
            ]
        ]);
    }
} 