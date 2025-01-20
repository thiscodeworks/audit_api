<?php

class Audit {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    a.id,
                    a.uuid,
                    a.company_name,
                    a.employee_count_limit,
                    a.description,
                    a.ai_system,
                    a.type,
                    a.created_at,
                    a.updated_at,
                    u.name as creator_name,
                    u.company as creator_company,
                    COUNT(DISTINCT c.id) as total_chats,
                    COUNT(DISTINCT m.id) as total_messages,
                    CASE 
                        WHEN a.type = 'assign' THEN (
                            SELECT COUNT(DISTINCT ua.user)
                            FROM users_audit ua
                            WHERE ua.audit = a.id
                        )
                        ELSE NULL
                    END as total_assigned_users,
                    CASE 
                        WHEN a.type = 'assign' THEN (
                            SELECT COUNT(DISTINCT c2.user)
                            FROM chats c2
                            WHERE c2.audit_uuid = a.uuid
                        )
                        ELSE COUNT(DISTINCT c.user)
                    END as total_active_users
                FROM audits a
                LEFT JOIN chats c ON c.audit_uuid = a.uuid
                LEFT JOIN users u ON u.id = c.user
                LEFT JOIN messages m ON m.chat_uuid = c.uuid
                GROUP BY a.id
                ORDER BY a.created_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error fetching audits: " . $e->getMessage());
        }
    }

    public function getByUuid($uuid) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    a.*,
                    u.name as creator_name,
                    u.company as creator_company,
                    COUNT(DISTINCT c.id) as total_chats,
                    COUNT(DISTINCT m.id) as total_messages,
                    CASE 
                        WHEN a.type = 'assign' THEN (
                            SELECT COUNT(DISTINCT ua.user)
                            FROM users_audit ua
                            WHERE ua.audit = a.id
                        )
                        ELSE NULL
                    END as total_assigned_users,
                    CASE 
                        WHEN a.type = 'assign' THEN (
                            SELECT COUNT(DISTINCT c2.user)
                            FROM chats c2
                            WHERE c2.audit_uuid = a.uuid
                        )
                        ELSE COUNT(DISTINCT c.user)
                    END as total_active_users,
                    CASE 
                        WHEN a.type = 'assign' THEN (
                            SELECT GROUP_CONCAT(DISTINCT ua.code)
                            FROM users_audit ua
                            WHERE ua.audit = a.id
                        )
                        ELSE NULL
                    END as access_codes
                FROM audits a
                LEFT JOIN chats c ON c.audit_uuid = a.uuid
                LEFT JOIN users u ON u.id = c.user
                LEFT JOIN messages m ON m.chat_uuid = c.uuid
                WHERE a.uuid = ?
                GROUP BY a.id
            ");
            $stmt->execute([$uuid]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error fetching audit: " . $e->getMessage());
        }
    }

    public function getStats($uuid) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    a.*,
                    COUNT(DISTINCT c.id) as total_chats,
                    COUNT(DISTINCT m.id) as total_messages,
                    CASE 
                        WHEN a.type = 'assign' THEN (
                            SELECT COUNT(DISTINCT ua.user)
                            FROM users_audit ua
                            WHERE ua.audit = a.id
                        )
                        ELSE NULL
                    END as total_assigned_users,
                    CASE 
                        WHEN a.type = 'assign' THEN (
                            SELECT COUNT(DISTINCT c2.user)
                            FROM chats c2
                            WHERE c2.audit_uuid = a.uuid
                        )
                        ELSE COUNT(DISTINCT c.user)
                    END as total_active_users
                FROM audits a
                LEFT JOIN chats c ON c.audit_uuid = a.uuid
                LEFT JOIN messages m ON m.chat_uuid = c.uuid
                WHERE a.uuid = ?
                GROUP BY a.id
            ");
            $stmt->execute([$uuid]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Mock sentiment data (in real app, this would come from analysis)
            $stats['sentiment'] = [
                'positive' => 45,
                'neutral' => 35,
                'negative' => 20
            ];

            return $stats;
        } catch (PDOException $e) {
            throw new Exception("Error fetching audit stats: " . $e->getMessage());
        }
    }

    public function getUsers($uuid) {
        try {
            $stmt = $this->db->prepare("
                WITH audit_data AS (
                    SELECT 
                        a.id as audit_id,
                        a.type,
                        a.uuid
                    FROM audits a
                    WHERE a.uuid = ?
                ),
                audit_users AS (
                    -- Get assigned users
                    SELECT 
                        ua.user as user_id,
                        'assigned' as source
                    FROM audit_data ad
                    JOIN users_audit ua ON ua.audit = ad.audit_id
                    WHERE ad.type = 'assign'
                    
                    UNION
                    
                    -- Get users who created chats (for both types)
                    SELECT 
                        c.user as user_id,
                        'chat' as source
                    FROM audit_data ad
                    JOIN chats c ON c.audit_uuid = ad.uuid
                    WHERE c.user IS NOT NULL
                )
                SELECT 
                    u.id,
                    u.name,
                    u.email as email,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM chats c 
                            WHERE c.audit_uuid = ? AND c.user = u.id
                        ) THEN 'completed'
                        ELSE 'pending'
                    END as state,
                    COUNT(DISTINCT c.id) as chat_count,
                    COUNT(DISTINCT m.id) as message_count
                FROM audit_users au
                JOIN users u ON u.id = au.user_id
                LEFT JOIN chats c ON c.user = u.id AND c.audit_uuid = ?
                LEFT JOIN messages m ON m.chat_uuid = c.uuid
                GROUP BY u.id
                ORDER BY u.name
            ");
            $stmt->execute([$uuid, $uuid, $uuid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error fetching audit users: " . $e->getMessage());
        }
    }

    public function getChats($uuid) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    c.uuid as chat_uuid,
                    u.name as username,
                    COUNT(m.id) as message_count,
                    c.created_at
                FROM chats c
                LEFT JOIN users u ON u.id = c.user
                LEFT JOIN messages m ON m.chat_uuid = c.uuid
                WHERE c.audit_uuid = ?
                GROUP BY c.id
                ORDER BY c.created_at DESC
            ");
            $stmt->execute([$uuid]);
            $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add mock data for sentiment and goal fulfillment
            foreach ($chats as &$chat) {
                $chat['sentiment'] = array_rand(['positive' => 1, 'neutral' => 1, 'negative' => 1]);
                $chat['goal_fulfill'] = rand(0, 100);
            }

            return $chats;
        } catch (PDOException $e) {
            throw new Exception("Error fetching audit chats: " . $e->getMessage());
        }
    }

    public function validateAuditAccess($uuid, $code = null) {
        try {
            // First get the audit to check its type and id
            $stmt = $this->db->prepare("
                SELECT id, type, uuid 
                FROM audits 
                WHERE uuid = ?
            ");
            $stmt->execute([$uuid]);
            $audit = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$audit) {
                return ['valid' => false, 'error' => 'Audit not found'];
            }

            // For public audits, no further validation needed
            if ($audit['type'] !== 'assign') {
                return ['valid' => true, 'audit' => $audit];
            }

            // For assigned audits, code is required
            if (!$code) {
                return ['valid' => false, 'error' => 'Code is required for assigned audit'];
            }

            // Check if code exists and is valid for this audit
            $stmt = $this->db->prepare("
                SELECT id, user
                FROM users_audit 
                WHERE audit = ? AND code = ?
            ");
            $stmt->execute([$audit['id'], $code]);
            $userAudit = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userAudit) {
                return ['valid' => false, 'error' => 'Invalid code for this audit'];
            }

            // Update the view status
            $stmt = $this->db->prepare("
                UPDATE users_audit 
                SET view = 1 
                WHERE audit = ? AND code = ?
            ");
            $stmt->execute([$audit['id'], $code]);

            return [
                'valid' => true, 
                'audit' => $audit,
                'user_id' => $userAudit['user']
            ];
        } catch (PDOException $e) {
            throw new Exception("Error validating audit access: " . $e->getMessage());
        }
    }

    public function findByCode($code) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    a.uuid
                FROM users_audit ua
                JOIN audits a ON a.id = ua.audit
                WHERE ua.code = ?
                LIMIT 1
            ");
            $stmt->execute([$code]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error finding audit by code: " . $e->getMessage());
            throw new Exception("Error finding audit by code");
        }
    }

    public function getUserAccessCode($auditId, $userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT code
                FROM users_audit
                WHERE audit = ? AND user = ?
                LIMIT 1
            ");
            $stmt->execute([$auditId, $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['code'] : null;
        } catch (PDOException $e) {
            error_log("Error getting user access code: " . $e->getMessage());
            return null;
        }
    }
}