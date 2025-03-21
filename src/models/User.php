<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/JWTHandler.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../utils/Env.php';

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
            // Get current user's organizations
            $userData = AuthMiddleware::getAuthenticatedUser();
            $userId = $userData->id;
            
            // Get user's organizations
            $stmt = $this->db->prepare("
                SELECT organization 
                FROM users_organization 
                WHERE user = ?
            ");
            $stmt->execute([$userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $userOrgs = array_column($results, 'organization');
            error_log("User organizations: " . json_encode($userOrgs));

            $sql = "
                SELECT 
                    u.id,
                    u.username,
                    u.name,
                    u.email,
                    u.position,
                    u.created_at,
                    u.updated_at,
                    u.phone,
                    up.permission,
                    GROUP_CONCAT(DISTINCT o.id) as organization_ids,
                    GROUP_CONCAT(DISTINCT o.name) as organization_names,
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
                JOIN users_organization uo ON uo.user = u.id
                JOIN organizations o ON o.id = uo.organization";

            // Add organizations filter if user has organizations
            if (!empty($userOrgs)) {
                $placeholders = str_repeat('?,', count($userOrgs) - 1) . '?';
                $sql .= " WHERE o.id IN ($placeholders)";
            }

            $sql .= " GROUP BY u.id ORDER BY u.name";

            error_log("SQL Query: " . $sql);
            error_log("Parameters: " . json_encode($userOrgs));

            $stmt = $this->db->prepare($sql);

            if (!empty($userOrgs)) {
                $stmt->execute($userOrgs);
            } else {
                $stmt->execute();
            }

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Query results: " . json_encode($results));

            // Format the organizations as arrays
            foreach ($results as &$user) {
                if (!isset($user['organization_ids']) || !isset($user['organization_names'])) {
                    error_log("Missing organization data for user: " . json_encode($user));
                    continue;
                }
                $user['organizations'] = array_map(function($id, $name) {
                    return ['id' => $id, 'name' => $name];
                }, 
                explode(',', $user['organization_ids']), 
                explode(',', $user['organization_names']));

                // Remove the concatenated fields
                unset($user['organization_ids']);
                unset($user['organization_names']);
            }

            return $results;
        } catch (PDOException $e) {
            error_log("PDO Error in getAll: " . $e->getMessage());
            error_log("SQL State: " . $e->getCode());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw new Exception("Error fetching users: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("General Error in getAll: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
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
                    u.phone,
                    u.created_at,
                    u.updated_at,
                    up.permission,
                    o.name as organization_name,
                    ua.blocked
                FROM users u
                LEFT JOIN users_permission up ON up.user = u.id
                LEFT JOIN users_organization uo ON uo.user = u.id
                LEFT JOIN organizations o ON o.id = uo.organization
                LEFT JOIN users_audit ua ON ua.user = u.id
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

            // Insert into users_organization table for each organization
            $stmt = $this->db->prepare("
                INSERT INTO users_organization (user, organization)
                VALUES (:user, :organization)
            ");

            foreach ($data['organizations'] as $organizationId) {
                $stmt->execute([
                    'user' => $userId,
                    'organization' => $organizationId
                ]);
            }

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

    public function createPublicUser($email, $name, $position, $phone = null) {
        try {
            $this->db->beginTransaction();

            // Insert into users table
            $stmt = $this->db->prepare("
                INSERT INTO users (name, email, position, phone, created_at, updated_at)
                VALUES (:name, :email, :position, :phone, NOW(), NOW())
            ");

            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'position' => $position,
                'phone' => $phone
            ]);

            $userId = $this->db->lastInsertId();
            $this->db->commit();
            return $userId;

        } catch (PDOException $e) {
            $this->db->rollBack();
            throw new Exception("Error creating public user: " . $e->getMessage());
        }
    }

    public function createAnonymousUser($userData = []) {
        try {
            $this->db->beginTransaction();

            // Parse optional user data
            $name = $userData['name'] ?? 'Anonymous User';
            $email = $userData['email'] ?? null;
            $position = $userData['position'] ?? null;
            $phone = $userData['phone'] ?? null;
            
            // Insert into users table with optional fields
            $stmt = $this->db->prepare("
                INSERT INTO users (name, email, position, phone, created_at, updated_at)
                VALUES (:name, :email, :position, :phone, NOW(), NOW())
            ");

            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'position' => $position,
                'phone' => $phone
            ]);

            $userId = $this->db->lastInsertId();
            $this->db->commit();
            return $userId;

        } catch (PDOException $e) {
            $this->db->rollBack();
            throw new Exception("Error creating anonymous user: " . $e->getMessage());
        }
    }
} 