<?php

class Chat {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll() {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   a.company_name,
                   u.name as user_name,
                   u.email as user_email,
                   (SELECT COUNT(*) FROM messages m WHERE m.chat_uuid = c.uuid AND m.is_hidden = 0) as message_count,
                   (SELECT created_at 
                    FROM messages 
                    WHERE chat_uuid = c.uuid 
                    ORDER BY created_at DESC 
                    LIMIT 1) as last_message_at
            FROM chats c
            LEFT JOIN audits a ON c.audit_uuid = a.uuid
            LEFT JOIN users u ON c.user = u.id
            ORDER BY c.created_at DESC
        ");
        
        $stmt->execute();
        $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format the response
        return array_map(function($chat) {
            return [
                'uuid' => $chat['uuid'],
                'audit_uuid' => $chat['audit_uuid'],
                'company_name' => $chat['company_name'],
                'user' => [
                    'name' => $chat['user_name'] ?? null,
                    'email' => $chat['user_email'] ?? null
                ],
                'message_count' => (int)$chat['message_count'],
                'last_message_at' => $chat['last_message_at'],
                'created_at' => $chat['created_at'],
                'updated_at' => $chat['updated_at']
            ];
        }, $chats);
    }
    
    public function getByUuid($uuid) {
        try {
            error_log("Chat::getByUuid - Starting query for UUID: " . $uuid);
            
            $stmt = $this->db->prepare("
                SELECT 
                    c.id,
                    c.uuid,
                    c.audit_uuid,
                    c.user,
                    c.created_at,
                    c.updated_at,
                    c.state,
                    COALESCE(u.name, '') as username,
                    COALESCE(u.email, '') as user_email,
                    COALESCE(a.company_name, '') as company_name
                FROM chats c
                LEFT JOIN users u ON c.user = u.id
                LEFT JOIN audits a ON c.audit_uuid = a.uuid
                WHERE c.uuid = ?
            ");
            
            if (!$stmt->execute([$uuid])) {
                $error = $stmt->errorInfo();
                error_log("Chat::getByUuid - Execute failed: " . json_encode($error));
                throw new Exception("Failed to execute query: " . $error[2]);
            }
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            error_log("Chat::getByUuid - Query result: " . json_encode($result));
            
            if (!$result) {
                error_log("Chat::getByUuid - No chat found for UUID: " . $uuid);
                return null;
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Chat::getByUuid - PDO Error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            throw new Exception("Database error while fetching chat: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("Chat::getByUuid - General Error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    public function create($auditUuid, $userId = null) {
        try {
            $uuid = $this->generateUuid();
            
            $stmt = $this->db->prepare("
                INSERT INTO chats (uuid, audit_uuid, user)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$uuid, $auditUuid, $userId]);
            
            return $uuid;
        } catch (PDOException $e) {
            throw new Exception("Error creating chat: " . $e->getMessage());
        }
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