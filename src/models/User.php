<?php

class User {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    u.id,
                    u.username,
                    u.name,
                    u.company,
                    u.created_at,
                    u.updated_at,
                    up.permission,
                    o.name as organization_name,
                    (
                        SELECT COUNT(DISTINCT c.id)
                        FROM chats c
                        WHERE c.user = u.id
                    ) as total_chats,
                    (
                        SELECT COUNT(DISTINCT m.id)
                        FROM chats c
                        JOIN messages m ON m.chat_uuid = c.uuid
                        WHERE c.user = u.id
                    ) as total_messages
                FROM users u
                LEFT JOIN users_permission up ON up.user = u.id
                LEFT JOIN users_organization uo ON uo.user = u.id
                LEFT JOIN organizations o ON o.id = uo.organization
                GROUP BY u.id
                ORDER BY u.name
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error fetching users: " . $e->getMessage());
        }
    }

    public function getById($id) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    u.id,
                    u.username,
                    u.name,
                    u.position,
                    u.company,
                    u.created_at,
                    u.updated_at,
                    up.permission,
                    o.name as organization_name
                FROM users u
                LEFT JOIN users_permission up ON up.user = u.id
                LEFT JOIN users_organization uo ON uo.user = u.id
                LEFT JOIN organizations o ON o.id = uo.organization
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error fetching user: " . $e->getMessage());
        }
    }
} 