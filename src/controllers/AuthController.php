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
            echo json_encode(['error' => 'E-mail je povinný']);
            return;
        }

        $stmt = $this->db->prepare("SELECT id, email, name FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Uživatel nenalezen']);
            return;
        }

        // Generate a unique token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // Store the token
        $stmt = $this->db->prepare("INSERT INTO magic_link_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $token, $expiresAt]);

        // Send magic link email (Czech, with logo and clean template, white background)
        $frontendUrl = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
        $loginUrl = "{$frontendUrl}/admin/login?token={$token}";
        $logoUrl = 'https://auditbot.cz/wp-content/uploads/2024/12/Frame-11-2.png';

        $htmlBody = "
            <div style='background:#fff;padding:40px 0;font-family:sans-serif;'>
                <div style='max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:32px 32px 24px 32px;box-shadow:0 2px 8px rgba(0,0,0,0.04);'>
                    <div style='text-align:center;margin-bottom:32px;'>
                        <img src='{$logoUrl}' alt='AuditBot' style='height:48px;margin-bottom:16px;'>
                    </div>
                    <h2 style='color:#222;margin-bottom:16px;'>Přihlášení do AuditBotu</h2>
                    <p style='color:#222;margin-bottom:24px;'>Dobrý den, {$user['name']},<br><br>Klikněte na tlačítko níže pro přihlášení do svého účtu:</p>
                    <div style='text-align:center;margin-bottom:24px;'>
                        <a href='{$loginUrl}' style='background-color:#4CAF50;color:#fff;padding:12px 32px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px;display:inline-block;'>Přihlásit se</a>
                    </div>
                    <p style='color:#222;margin-bottom:8px;'>Tento odkaz vyprší za 15 minut.</p>
                    <p style='color:#888;font-size:13px;'>Pokud jste o přihlášení nežádali, tento e-mail ignorujte.</p>
                </div>
            </div>
        ";

        $this->postmark->sendEmail(
            $user['email'],
            'Přihlašovací odkaz do AuditBotu',
            $htmlBody
        );

        echo json_encode([
            'message' => 'Přihlašovací odkaz byl odeslán na váš e-mail.'
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

    public function register() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['email']) || !isset($data['name']) || !isset($data['company'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Email, jméno a společnost jsou povinné']);
            return;
        }

        // Check if user already exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            http_response_code(400);
            echo json_encode(['error' => 'Uživatel s tímto e-mailem již existuje']);
            return;
        }

        // Create or get organization
        $orgStmt = $this->db->prepare("SELECT id FROM organizations WHERE name = ?");
        $orgStmt->execute([$data['company']]);
        $organization = $orgStmt->fetch(PDO::FETCH_ASSOC);
        if ($organization) {
            $organizationId = $organization['id'];
        } else {
            $orgInsert = $this->db->prepare("INSERT INTO organizations (name) VALUES (?)");
            $orgInsert->execute([$data['company']]);
            $organizationId = $this->db->lastInsertId();
        }

        // Generate a unique token for email verification
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // Create user
        $stmt = $this->db->prepare("
            INSERT INTO users (name, email, position, company, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$data['name'], $data['email'], $data['position'] ?? null, $data['company']]);
        $userId = $this->db->lastInsertId();

        // Link user to organization
        $linkStmt = $this->db->prepare("INSERT INTO users_organization (user, organization) VALUES (?, ?)");
        $linkStmt->execute([$userId, $organizationId]);

        // Set user permission as adminorg
        $permStmt = $this->db->prepare("INSERT INTO users_permission (user, permission) VALUES (?, 'adminorg')");
        $permStmt->execute([$userId]);

        // Store verification token
        $stmt = $this->db->prepare("
            INSERT INTO magic_link_tokens (user_id, token, expires_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, $token, $expiresAt]);

        // Send verification email (Czech, with logo and clean template, white background)
        $frontendUrl = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
        $verifyUrl = "{$frontendUrl}/verify-email?token={$token}";
        $logoUrl = 'https://auditbot.cz/wp-content/uploads/2024/12/Frame-11-2.png';

        $htmlBody = "
            <div style='background:#fff;padding:40px 0;font-family:sans-serif;'>
                <div style='max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:32px 32px 24px 32px;box-shadow:0 2px 8px rgba(0,0,0,0.04);'>
                    <div style='text-align:center;margin-bottom:32px;'>
                        <img src='{$logoUrl}' alt='AuditBot' style='height:48px;margin-bottom:16px;'>
                    </div>
                    <h2 style='color:#222;margin-bottom:16px;'>Vítejte v AuditBotu</h2>
                    <p style='color:#222;margin-bottom:24px;'>Dobrý den, {$data['name']},<br><br>Děkujeme za registraci. Pro ověření vaší e-mailové adresy klikněte na tlačítko níže:</p>
                    <div style='text-align:center;margin-bottom:24px;'>
                        <a href='{$verifyUrl}' style='background-color:#4CAF50;color:#fff;padding:12px 32px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px;display:inline-block;'>Ověřit e-mail</a>
                    </div>
                    <p style='color:#222;margin-bottom:8px;'>Tento odkaz vyprší za 24 hodin.</p>
                    <p style='color:#888;font-size:13px;'>Pokud jste si tento účet nevytvořili, tento e-mail ignorujte.</p>
                </div>
            </div>
        ";

        $this->postmark->sendEmail(
            $data['email'],
            'Ověření e-mailu pro AuditBot',
            $htmlBody
        );

        echo json_encode([
            'message' => 'Registrace proběhla úspěšně. Pro dokončení prosím ověřte svůj e-mail.'
        ]);
    }

    public function verifyEmail() {
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

        // Generate JWT token (always provide username, fallback to email)
        $token = $this->jwt->generateToken([
            'id' => $tokenData['user_id'],
            'email' => $tokenData['email'],
            'username' => $tokenData['username'] ?? $tokenData['email']
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
} 