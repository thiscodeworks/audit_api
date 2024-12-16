<?php

class Audit {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll() {
        $stmt = $this->db->prepare("
            SELECT * FROM audits 
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $uuid = $this->generateUuid();
        
        $stmt = $this->db->prepare("
            INSERT INTO audits (
                uuid, 
                company_name, 
                employee_count_limit, 
                description, 
                ai_system, 
                ai_prompt, 
                audit_data
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $uuid,
            $data['company_name'],
            $data['employee_count_limit'],
            $data['description'],
            $data['ai_system'],
            $data['ai_prompt'],
            $data['audit_data']
        ]);

        return $uuid;
    }

    public function getByUuid($uuid) {
        $stmt = $this->db->prepare("SELECT * FROM audits WHERE uuid = ?");
        $stmt->execute([$uuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function exists($uuid) {
        $stmt = $this->db->prepare("SELECT 1 FROM audits WHERE uuid = ?");
        $stmt->execute([$uuid]);
        return $stmt->fetch() !== false;
    }

    public function delete($uuid) {
        // First delete related chats and messages
        $this->db->beginTransaction();
        
        try {
            // Delete messages from related chats
            $stmt = $this->db->prepare("
                DELETE messages FROM messages 
                INNER JOIN chats ON messages.chat_uuid = chats.uuid 
                WHERE chats.audit_uuid = ?
            ");
            $stmt->execute([$uuid]);

            // Delete related chats
            $stmt = $this->db->prepare("DELETE FROM chats WHERE audit_uuid = ?");
            $stmt->execute([$uuid]);

            // Delete the audit
            $stmt = $this->db->prepare("DELETE FROM audits WHERE uuid = ?");
            $stmt->execute([$uuid]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update($uuid, $data) {
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $fields[] = "{$key} = ?";
            $values[] = $value;
        }
        
        $values[] = $uuid;
        
        $stmt = $this->db->prepare("
            UPDATE audits 
            SET " . implode(', ', $fields) . "
            WHERE uuid = ?
        ");

        return $stmt->execute($values);
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