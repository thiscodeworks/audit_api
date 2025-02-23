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

        $stmt = $this->db->prepare("SELECT id, username, password, name FROM users WHERE username = ?");
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
                'name' => $user['name']
            ]
        ]);
    }

    public function me() {
        $rawHeaders = getallheaders();
        error_log('Raw headers: ' . print_r($rawHeaders, true));
        
        $headers = array_change_key_case($rawHeaders, CASE_UPPER);
        error_log('Uppercase headers: ' . print_r($headers, true));
        
        if (!isset($headers['AUTHORIZATION'])) {
            error_log('Authorization header not found in: ' . implode(', ', array_keys($headers)));
            http_response_code(401);
            echo json_encode(['error' => 'No authorization token provided']);
            return;
        }

        $token = str_replace('Bearer ', '', $headers['AUTHORIZATION']);
        $decoded = $this->jwt->validateToken($token);
        
        if (!$decoded) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            return;
        }

        $stmt = $this->db->prepare("SELECT id, username, name, created_at FROM users WHERE id = ?");
        $stmt->execute([$decoded->data->id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        // Get user global permissions
        $permStmt = $this->db->prepare("SELECT permission FROM users_permission WHERE user = ?");
        $permStmt->execute([$user['id']]);
        $permissions = $permStmt->fetchAll(PDO::FETCH_COLUMN);

        // Get user organizations
        $orgStmt = $this->db->prepare("
            SELECT o.id, o.name 
            FROM organizations o 
            JOIN users_organization uo ON o.id = uo.organization 
            WHERE uo.user = ?
        ");
        $orgStmt->execute([$user['id']]);
        $organizations = $orgStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'data' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'name' => $user['name'],
                'created_at' => $user['created_at'],
                'permissions' => [
                    'global' => $permissions,
                    'organizations' => $organizations
                ]
            ]
        ]);
    }
} 