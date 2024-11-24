<?php

class Chat {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getByUuid($uuid) {
        $uuid = is_array($uuid) ? $uuid['uuid'] : $uuid;
        
        // Get chat info
        $stmt = $this->db->prepare("
            SELECT c.*, 
                   m.uuid as message_uuid,
                   m.content as message_content,
                   m.role as message_role,
                   m.created_at as message_created_at,
                   m.is_hidden as message_is_hidden
            FROM chats c
            LEFT JOIN messages m ON c.uuid = m.chat_uuid
            WHERE c.uuid = ?
            ORDER BY m.created_at ASC
        ");
        
        $stmt->execute([$uuid]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            return null;
        }

        // Format the response
        $chat = [
            'uuid' => $results[0]['uuid'],
            'audit_uuid' => $results[0]['audit_uuid'],
            'created_at' => $results[0]['created_at'],
            'updated_at' => $results[0]['updated_at'],
            'messages' => []
        ];

        // Add messages if they exist
        foreach ($results as $row) {
            if ($row['message_uuid'] && !$row['message_is_hidden']) {
                $chat['messages'][] = [
                    'uuid' => $row['message_uuid'],
                    'content' => $row['message_content'],
                    'role' => $row['message_role'],
                    'created_at' => $row['message_created_at']
                ];
            }
        }

        return $chat;
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