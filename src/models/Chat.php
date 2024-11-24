<?php

class Chat {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function create($auditUuid) {
        $uuid = $this->generateUuid();
        $stmt = $this->db->prepare("
            INSERT INTO chats (uuid, audit_uuid, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([$uuid, $auditUuid]);
        return $uuid;
    }

    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
} 