<?php
require_once __DIR__ . '/Env.php';

class JWTHandler {
    private $secret_key;
    private $issuer;
    private $audience;

    public function __construct() {
        $this->secret_key = Env::get('JWT_SECRET', 'your-secret-key-here');
        $this->issuer = Env::get('JWT_ISSUER', 'auditbot-api');
        $this->audience = Env::get('JWT_AUDIENCE', 'auditbot-client');
    }

    public function generateToken($user) {
        $issued_at = time();
        $expiration = $issued_at + (60 * 60 * 24); // 24 hours expiration
        
        $payload = [
            "iss" => $this->issuer,
            "aud" => $this->audience,
            "iat" => $issued_at,
            "exp" => $expiration,
            "data" => [
                "id" => $user['id'],
                "username" => $user['username']
            ]
        ];

        return $this->encode($payload);
    }

    public function validateToken($token) {
        try {
            $decoded = $this->decode($token);
            
            if ($decoded->exp < time()) {
                return false;
            }
            
            return $decoded;
        } catch(Exception $e) {
            return false;
        }
    }

    private function encode($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $header = $this->base64UrlEncode($header);
        
        $payload = json_encode($payload);
        $payload = $this->base64UrlEncode($payload);
        
        $signature = hash_hmac('sha256', "$header.$payload", $this->secret_key, true);
        $signature = $this->base64UrlEncode($signature);
        
        return "$header.$payload.$signature";
    }

    private function decode($token) {
        list($header, $payload, $signature) = explode('.', $token);
        
        $valid_signature = hash_hmac('sha256', "$header.$payload", $this->secret_key, true);
        $valid_signature = $this->base64UrlEncode($valid_signature);
        
        if ($signature !== $valid_signature) {
            throw new Exception('Invalid signature');
        }
        
        return json_decode($this->base64UrlDecode($payload));
    }

    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
} 