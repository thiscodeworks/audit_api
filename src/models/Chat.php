<?php

class Chat {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAll() {
        try {
            // Get current user's organizations
            error_log("Chat::getAll - Starting to fetch user organizations");
            $userData = AuthMiddleware::getAuthenticatedUser();
            $userId = $userData->id;
            error_log("Chat::getAll - User ID: " . $userId);
            
            // Get user's organizations
            $stmt = $this->db->prepare("
                SELECT organization 
                FROM users_organization 
                WHERE user = ?
            ");
            $stmt->execute([$userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $userOrgs = array_column($results, 'organization');
            error_log("Chat::getAll - User organizations: " . json_encode($userOrgs));

            $sql = "WITH user_messages AS (
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
                c.*,
                a.company_name,
                u.name as username,
                u.email as user_email,
                (SELECT COUNT(*) FROM messages m WHERE m.chat_uuid = c.uuid AND (m.is_hidden = 0 OR m.is_hidden IS NULL)) as message_count,
                (SELECT created_at 
                FROM messages 
                WHERE chat_uuid = c.uuid 
                ORDER BY created_at DESC 
                LIMIT 1) as last_message_at,
                an.id as analyze_id,
                an.sentiment,
                an.summary,
                an.keyfindings,
                an.goal_fulfill
            FROM chats c
            LEFT JOIN audits a ON c.audit_uuid = a.uuid
            LEFT JOIN users u ON c.user = u.id
            LEFT JOIN `analyze` an ON an.chat = c.id
            INNER JOIN user_messages um ON um.chat_uuid = c.uuid
            WHERE 1=1";

            // Add organizations filter
            if (!empty($userOrgs)) {
                $placeholders = str_repeat('?,', count($userOrgs) - 1) . '?';
                $sql .= " AND a.organization IN ($placeholders)";
            }

            $sql .= " ORDER BY c.created_at DESC";
            
            error_log("Chat::getAll - SQL Query: " . $sql);
            error_log("Chat::getAll - Query parameters: " . json_encode($userOrgs));

            $stmt = $this->db->prepare($sql);
            
            if (!empty($userOrgs)) {
                if (!$stmt->execute($userOrgs)) {
                    $error = $stmt->errorInfo();
                    error_log("Chat::getAll - Execute failed: " . json_encode($error));
                    throw new Exception("Failed to execute query: " . $error[2]);
                }
            } else {
                if (!$stmt->execute()) {
                    $error = $stmt->errorInfo();
                    error_log("Chat::getAll - Execute failed: " . json_encode($error));
                    throw new Exception("Failed to execute query: " . $error[2]);
                }
            }
            
            $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Chat::getAll - Number of chats found: " . count($chats));

            // Format the response
            return array_map(function($chat) {
                $formattedChat = [
                    'uuid' => $chat['uuid'],
                    'audit_uuid' => $chat['audit_uuid'],
                    'company_name' => $chat['company_name'],
                    'user' => [
                        'name' => $chat['username'] ?? null,
                        'email' => $chat['user_email'] ?? null
                    ],
                    'stats' => [
                        'messages' => (int)$chat['message_count'],
                        'goal_fulfill' => (int)($chat['goal_fulfill'] ?? 0)
                    ],
                    'created_at' => $chat['created_at'],
                    'updated_at' => $chat['updated_at'],
                    'last_message_at' => $chat['last_message_at'],
                    'state' => $chat['state'],
                    'has_analysis' => isset($chat['analyze_id']),
                    'analysis' => [
                        'summary' => $chat['summary'] ?? '',
                        'keyfindings' => $chat['keyfindings'] ?? ''
                    ]
                ];

                // Transform sentiment if analysis exists
                if (isset($chat['sentiment']) && isset($chat['analyze_id'])) {
                    $sentimentValue = (int)$chat['sentiment'];
                    if ($sentimentValue >= 70) {
                        $formattedChat['sentiment'] = 'positive';
                    } else if ($sentimentValue >= 40) {
                        $formattedChat['sentiment'] = 'neutral';
                    } else {
                        $formattedChat['sentiment'] = 'negative';
                    }
                } else {
                    $formattedChat['sentiment'] = 'neutral';
                }

                return $formattedChat;
            }, $chats);
        } catch (PDOException $e) {
            error_log("Chat::getAll - PDO Error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            throw new Exception("Database error while fetching chats: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("Chat::getAll - General Error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            throw $e;
        }
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
            error_log("Chat::create - Starting chat creation with auditUuid: " . $auditUuid . ", userId: " . $userId);
            
            $uuid = $this->generateUuid();
            error_log("Chat::create - Generated UUID: " . $uuid);
            
            $stmt = $this->db->prepare("
                INSERT INTO chats (uuid, audit_uuid, user)
                VALUES (?, ?, ?)
            ");
            
            error_log("Chat::create - Executing insert with values: uuid=" . $uuid . ", auditUuid=" . $auditUuid . ", userId=" . $userId);
            
            if (!$stmt->execute([$uuid, $auditUuid, $userId])) {
                $error = $stmt->errorInfo();
                error_log("Chat::create - Execute failed: " . json_encode($error));
                throw new Exception("Failed to execute query: " . $error[2]);
            }
            
            error_log("Chat::create - Successfully created chat");
            return $uuid;
        } catch (PDOException $e) {
            error_log("Chat::create - PDO Error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            throw new Exception("Error creating chat: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("Chat::create - General Error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function generateUuid() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
} 