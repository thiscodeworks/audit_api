<?php

require_once __DIR__ . '/../config/database.php';

class User {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public static function findByAuthToken($authToken) {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT 
                u.id,
                u.username,
                u.name,
                u.position,
                u.created_at,
                u.updated_at,
                up.permission,
                o.name as organization_name
            FROM users u
            LEFT JOIN users_permission up ON up.user = u.id
            LEFT JOIN users_organization uo ON uo.user = u.id
            LEFT JOIN organizations o ON o.id = uo.organization
            WHERE u.auth_token = :authToken
            LIMIT 1
        ");
        $stmt->execute(['authToken' => $authToken]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAll() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    u.id,
                    u.username,
                    u.name,
                    u.email,
                    u.position,
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
                    u.email,
                    u.position,
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

    public function create($data) {
        try {
            $this->db->beginTransaction();

            // Insert into users table
            $stmt = $this->db->prepare("
                INSERT INTO users (name, email, position, username, password, created_at, updated_at)
                VALUES (:name, :email, :position, :username, :password, NOW(), NOW())
            ");

            $stmt->execute([
                'name' => $data['name'],
                'email' => $data['email'],
                'position' => $data['position'],
                'username' => $data['username'] ?: $data['email'],
                'password' => $data['password'] ? password_hash($data['password'], PASSWORD_DEFAULT) : null
            ]);

            $userId = $this->db->lastInsertId();

            // Insert into users_organization table
            $stmt = $this->db->prepare("
                INSERT INTO users_organization (user, organization)
                VALUES (:user, :organization)
            ");

            $stmt->execute([
                'user' => $userId,
                'organization' => $data['organization']
            ]);

            // Insert into users_permission table
            $stmt = $this->db->prepare("
                INSERT INTO users_permission (user, permission)
                VALUES (:user, :permission)
            ");

            $stmt->execute([
                'user' => $userId,
                'permission' => $data['permission']
            ]);

            $this->db->commit();
            return $userId;

        } catch (PDOException $e) {
            $this->db->rollBack();
            throw new Exception("Error creating user: " . $e->getMessage());
        }
    }
} 