<?php
require_once __DIR__ . '/../utils/JWTHandler.php';
require_once __DIR__ . '/../services/PostmarkService.php';

class AuthController {
    private $db;
    private $jwt;
    private $postmark;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->jwt = new JWTHandler();
        $this->postmark = new PostmarkService();
    }

    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['email'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Email is required']);
            return;
        }

        $stmt = $this->db->prepare("SELECT id, email, name FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        // Generate a unique token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // Store the token
        $stmt = $this->db->prepare("INSERT INTO magic_link_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $token, $expiresAt]);

        // Send magic link email
        $frontendUrl = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
        $loginUrl = "{$frontendUrl}/admin/login?token={$token}";

        $htmlBody = "
            <h2>Login to AuditBot</h2>
            <p>Hello {$user['name']},</p>
            <p>Click the link below to log in to your account:</p>
            <p><a href='{$loginUrl}' style='background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Login to AuditBot</a></p>
            <p>This link will expire in 15 minutes.</p>
            <p>If you didn't request this login, please ignore this email.</p>
        ";

        $this->postmark->sendEmail(
            $user['email'],
            'Your AuditBot Login Link',
            $htmlBody
        );

        echo json_encode([
            'message' => 'Login link sent to your email'
        ]);
    }

    public function verifyToken() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['token'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Token is required']);
            return;
        }

        // Get and validate the token
        $stmt = $this->db->prepare("
            SELECT t.*, u.id as user_id, u.email, u.name, u.username 
            FROM magic_link_tokens t
            JOIN users u ON t.user_id = u.id
            WHERE t.token = ? AND t.expires_at > NOW() AND t.used = 0
        ");
        $stmt->execute([$data['token']]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenData) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            return;
        }

        // Mark token as used
        $stmt = $this->db->prepare("UPDATE magic_link_tokens SET used = 1 WHERE id = ?");
        $stmt->execute([$tokenData['id']]);

        // Generate JWT token
        $token = $this->jwt->generateToken([
            'id' => $tokenData['user_id'],
            'email' => $tokenData['email'],
            'username' => $tokenData['username'] ?? $tokenData['email'] // Use email as username if username is not set
        ]);

        echo json_encode([
            'token' => $token,
            'user' => [
                'id' => $tokenData['user_id'],
                'email' => $tokenData['email'],
                'name' => $tokenData['name']
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

        $stmt = $this->db->prepare("SELECT id, email, name, created_at FROM users WHERE id = ?");
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
                'email' => $user['email'],
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