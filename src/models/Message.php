<?php

class Message {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function create($chatUuid, $content, $role, $isHidden = false) {
        $uuid = $this->generateUuid();
        $stmt = $this->db->prepare("
            INSERT INTO messages (uuid, chat_uuid, content, role, is_hidden, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([$uuid, $chatUuid, $content, $role, $isHidden ? 1 : 0]);
        return $uuid;
    }

    public function getChatHistory($chatUuid) {
        $stmt = $this->db->prepare("
            SELECT content, role 
            FROM messages 
            WHERE chat_uuid = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$chatUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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