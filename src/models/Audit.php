<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../utils/JWTHandler.php';

class Audit {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll() {
        try {
            // Get current user's organization from JWT
            $userOrg = $this->getCurrentUserOrganization();

            $sql = "
                SELECT 
                    a.id,
                    a.uuid,
                    a.audit_name,
                    a.company_name,
                    a.employee_count_limit,
                    a.description,
                    a.ai_system,
                    a.type,
                    a.status,
                    a.created_at,
                    a.updated_at,
                    o.name as organization_name,
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
                LEFT JOIN organizations o ON o.id = a.organization
                LEFT JOIN chats c ON c.audit_uuid = a.uuid
                LEFT JOIN messages m ON m.chat_uuid = c.uuid";

            // Add organization filter if user has an organization
            if ($userOrg) {
                $sql .= " WHERE a.organization = ?";
            }

            $sql .= " GROUP BY a.id ORDER BY a.created_at DESC";

            $stmt = $this->db->prepare($sql);

            if ($userOrg) {
                $stmt->execute([$userOrg]);
            } else {
                $stmt->execute();
            }

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
                    o.name as organization_name,
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
                LEFT JOIN organizations o ON o.id = a.organization
                LEFT JOIN chats c ON c.audit_uuid = a.uuid
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
            // Get current user's organization from JWT
            $userOrg = $this->getCurrentUserOrganization();

            $sql = "
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
                WHERE a.uuid = ?";

            // Add organization filter if user has an organization
            if ($userOrg) {
                $sql .= " AND a.organization = ?";
            }

            $sql .= " GROUP BY a.id";

            $stmt = $this->db->prepare($sql);
            
            if ($userOrg) {
                $stmt->execute([$uuid, $userOrg]);
            } else {
                $stmt->execute([$uuid]);
            }

            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Mock sentiment data (in real app, this would come from analysis)
            if ($stats) {
                $stats['sentiment'] = [
                    'positive' => 45,
                    'neutral' => 35,
                    'negative' => 20
                ];
            }

            return $stats;
        } catch (PDOException $e) {
            throw new Exception("Error fetching audit stats: " . $e->getMessage());
        }
    }

    public function getUsers($uuid) {
        try {
            // Get current user's organization from JWT
            $userOrg = $this->getCurrentUserOrganization();

            $sql = "
                WITH audit_data AS (
                    SELECT 
                        a.id as audit_id,
                        a.type,
                        a.uuid,
                        a.organization
                    FROM audits a
                    WHERE a.uuid = ?";

            if ($userOrg) {
                $sql .= " AND a.organization = ?";
            }

            $sql .= "),
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
                    ua.code,
                    ua.view,
                    ua.invite,
                    ua.push,
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
                LEFT JOIN users_audit ua ON ua.user = u.id AND ua.audit = (SELECT audit_id FROM audit_data LIMIT 1)
                LEFT JOIN chats c ON c.user = u.id AND c.audit_uuid = ?
                LEFT JOIN messages m ON m.chat_uuid = c.uuid
                GROUP BY u.id, ua.code, ua.view, ua.invite, ua.push
                ORDER BY u.name";

            $stmt = $this->db->prepare($sql);
            
            if ($userOrg) {
                $stmt->execute([$uuid, $userOrg, $uuid, $uuid]);
            } else {
                $stmt->execute([$uuid, $uuid, $uuid]);
            }

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error fetching audit users: " . $e->getMessage());
        }
    }

    public function getChats($uuid) {
        try {
            // Get current user's organization
            $userOrg = $this->getCurrentUserOrganization();

            $sql = "
                WITH user_messages AS (
                    SELECT 
                        chat_uuid,
                        COUNT(*) as user_message_count
                    FROM messages 
                    WHERE role = 'user'
                        AND (is_hidden = 0 OR is_hidden IS NULL)
                    GROUP BY chat_uuid
                    HAVING COUNT(*) > 0
                )
                SELECT 
                    c.id,
                    c.uuid as chat_uuid,
                    u.name as username,
                    COUNT(DISTINCT CASE WHEN (m.is_hidden = 0 OR m.is_hidden IS NULL) THEN m.id ELSE NULL END) as message_count,
                    c.created_at,
                    c.state,
                    a.id as analyze_id,
                    a.sentiment,
                    a.goal_fulfill,
                    a.summary,
                    a.keyfindings,
                    MAX(CASE WHEN (m.is_hidden = 0 OR m.is_hidden IS NULL) THEN m.created_at ELSE NULL END) as last_message_at
                FROM audits au
                JOIN chats c ON c.audit_uuid = au.uuid
                LEFT JOIN users u ON u.id = c.user
                -- Join with user messages to ensure at least one user message exists
                INNER JOIN user_messages um ON um.chat_uuid = c.uuid
                LEFT JOIN messages m ON m.chat_uuid = c.uuid
                LEFT JOIN `analyze` a ON a.chat = c.id
                WHERE au.uuid = ?";

            // Add organization filter if user has an organization
            if ($userOrg) {
                $sql .= " AND au.organization = ?";
            }

            $sql .= " GROUP BY 
                    c.id, 
                    c.uuid,
                    u.name,
                    c.created_at,
                    c.state,
                    a.id,
                    a.sentiment,
                    a.goal_fulfill,
                    a.summary,
                    a.keyfindings
                ORDER BY c.created_at DESC";

            $stmt = $this->db->prepare($sql);
            
            if ($userOrg) {
                $stmt->execute([$uuid, $userOrg]);
            } else {
                $stmt->execute([$uuid]);
            }

            $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Transform sentiment from number to string if analysis exists
            foreach ($chats as &$chat) {
                if (isset($chat['sentiment']) && $chat['analyze_id']) {
                    // Convert 0-100 scale to sentiment categories
                    $sentimentValue = (int)$chat['sentiment'];
                    if ($sentimentValue >= 70) {
                        $chat['sentiment'] = 'positive';
                    } else if ($sentimentValue >= 40) {
                        $chat['sentiment'] = 'neutral';
                    } else {
                        $chat['sentiment'] = 'negative';
                    }
                } else {
                    // If no analysis exists yet, set default values
                    $chat['sentiment'] = 'neutral';
                    $chat['goal_fulfill'] = 0;
                    $chat['summary'] = '';
                    $chat['keyfindings'] = '';
                }
            }

            return $chats;
        } catch (PDOException $e) {
            error_log("Error in getChats: " . $e->getMessage() . "\nSQL: " . $sql);
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
                SELECT id, user, blocked
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
                'user_id' => $userAudit['user'],
                'blocked' => $userAudit['blocked']
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

    private function getUserOrganization($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT organization 
                FROM users_organization 
                WHERE user = ?
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['organization'] : null;
        } catch (PDOException $e) {
            throw new Exception("Error getting user organization: " . $e->getMessage());
        }
    }

    private function getCurrentUserOrganization() {
        $headers = array_change_key_case(getallheaders(), CASE_UPPER);
        $token = isset($headers['AUTHORIZATION']) ? str_replace('Bearer ', '', $headers['AUTHORIZATION']) : null;
        
        if ($token) {
            $jwt = new JWTHandler();
            $decoded = $jwt->validateToken($token);
            return $decoded ? $this->getUserOrganization($decoded->data->id) : null;
        }
        return null;
    }

    public function getAvailableUsers($uuid) {
        try {
            // Get the audit details to get the organization
            $stmt = $this->db->prepare("
                SELECT id, organization FROM audits WHERE uuid = ?
            ");
            $stmt->execute([$uuid]);
            $audit = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$audit) {
                return null;
            }

            // Get users from the same organization who are not assigned to this audit
            $stmt = $this->db->prepare("
                SELECT 
                    u.id,
                    u.name,
                    u.email,
                    u.position
                FROM users u
                JOIN users_organization uo ON uo.user = u.id
                WHERE uo.organization = ?
                AND NOT EXISTS (
                    SELECT 1 
                    FROM users_audit ua 
                    WHERE ua.user = u.id 
                    AND ua.audit = ?
                )
                ORDER BY u.name
            ");
            $stmt->execute([$audit['organization'], $audit['id']]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            throw new Exception("Error fetching available users: " . $e->getMessage());
        }
    }

    public function assignUser($uuid, $userId) {
        try {
            // Get the audit ID
            $stmt = $this->db->prepare("SELECT id, type FROM audits WHERE uuid = ?");
            $stmt->execute([$uuid]);
            $audit = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$audit) {
                return ['error' => 'Audit not found'];
            }

            // Check if the audit is of type 'assign'
            if ($audit['type'] !== 'assign') {
                return ['error' => 'This audit does not support user assignments'];
            }

            // Check if user exists
            $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            if (!$stmt->fetch()) {
                return ['error' => 'User not found'];
            }

            // Check if user is already assigned
            $stmt = $this->db->prepare("
                SELECT 1 FROM users_audit 
                WHERE user = ? AND audit = ?
            ");
            $stmt->execute([$userId, $audit['id']]);
            if ($stmt->fetch()) {
                return ['error' => 'User is already assigned to this audit'];
            }

            // Generate a unique 6-digit code
            do {
                $code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $stmt = $this->db->prepare("
                    SELECT 1 FROM users_audit 
                    WHERE code = ? AND audit = ?
                ");
                $stmt->execute([$code, $audit['id']]);
            } while ($stmt->fetch());

            // Create the assignment
            $stmt = $this->db->prepare("
                INSERT INTO users_audit (user, audit, code)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $audit['id'], $code]);

            return [
                'success' => true,
                'code' => $code
            ];

        } catch (PDOException $e) {
            error_log("Error assigning user: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw new Exception("Error assigning user: " . $e->getMessage());
        }
    }

    public function updateUserEmailStatus($auditId, $userId, $type) {
        try {
            $field = $type === 'invitation' ? 'invite' : 'push';
            
            $stmt = $this->db->prepare("
                UPDATE users_audit 
                SET {$field} = 1 
                WHERE audit = ? AND user = ?
            ");
            
            return $stmt->execute([$auditId, $userId]);
        } catch (PDOException $e) {
            error_log("Error updating user email status: " . $e->getMessage());
            throw new Exception("Error updating user email status");
        }
    }
}