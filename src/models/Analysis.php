<?php

class Analysis {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getLastAnalysis($chatId) {
        try {
            $stmt = $this->db->prepare("
                SELECT a.*, c.uuid as chat_uuid
                FROM `analyze` a
                JOIN chats c ON c.id = a.chat
                WHERE a.chat = ?
                ORDER BY a.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$chatId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error fetching analysis: " . $e->getMessage());
        }
    }

    public function create($chatId, $sentiment, $summary, $keyfindings) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO `analyze` (chat, sentiment, summary, keyfindings, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            return $stmt->execute([$chatId, $sentiment, $summary, $keyfindings]);
        } catch (PDOException $e) {
            throw new Exception("Error creating analysis: " . $e->getMessage());
        }
    }

    public function getChatsNeedingAnalysis() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    c.id as chat_id,
                    c.uuid as chat_uuid,
                    MAX(m.created_at) as last_message_at,
                    (
                        SELECT created_at 
                        FROM `analyze` 
                        WHERE chat = c.id 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ) as last_analysis_at,
                    COUNT(CASE WHEN m.role = 'user' THEN 1 END) as user_message_count
                FROM chats c
                JOIN messages m ON m.chat_uuid = c.uuid
                WHERE c.state = 'open'
                GROUP BY c.id
                HAVING (
                    (last_analysis_at IS NULL AND user_message_count > 0)
                    OR 
                    (last_message_at > last_analysis_at AND user_message_count > 0)
                )
                ORDER BY COALESCE(last_analysis_at, '1970-01-01') ASC, last_message_at ASC
                LIMIT 1
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error fetching chats for analysis: " . $e->getMessage());
        }
    }

    public function getChatMessages($chatUuid) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    m.content,
                    m.role,
                    m.created_at
                FROM messages m
                WHERE m.chat_uuid = ?
                AND m.is_hidden = 0
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$chatUuid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Error fetching chat messages: " . $e->getMessage());
        }
    }

    public function getChatDetail($chatUuid) {
        try {
            // Handle if UUID comes as array
            $uuid = is_array($chatUuid) ? $chatUuid['uuid'] : $chatUuid;

            // Get chat information with user data
            $stmt = $this->db->prepare("
                SELECT 
                    c.id,
                    c.uuid,
                    c.state,
                    c.created_at as chat_created_at,
                    c.updated_at as chat_updated_at,
                    u.id as user_id,
                    u.username
                FROM chats c
                LEFT JOIN users u ON u.id = c.user
                WHERE c.uuid = :uuid
            ");
            $stmt->execute(['uuid' => $uuid]);
            $chatInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$chatInfo) {
                throw new Exception("Chat not found");
            }

            // Get all analyses for this chat
            $stmt = $this->db->prepare("
                SELECT 
                    sentiment,
                    summary,
                    keyfindings,
                    created_at as analysis_created_at
                FROM `analyze`
                WHERE chat = :chat_id
                ORDER BY created_at DESC
            ");
            $stmt->execute(['chat_id' => $chatInfo['id']]);
            $analyses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get all messages for this chat
            $stmt = $this->db->prepare("
                SELECT 
                    uuid as message_uuid,
                    content,
                    role,
                    is_hidden,
                    created_at as message_created_at
                FROM messages
                WHERE chat_uuid = :chat_uuid
                ORDER BY created_at ASC
            ");
            $stmt->execute(['chat_uuid' => $uuid]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate some statistics
            $messageCount = count($messages);
            $userMessageCount = 0;
            $assistantMessageCount = 0;
            foreach ($messages as $msg) {
                if ($msg['role'] === 'user') {
                    $userMessageCount++;
                } else if ($msg['role'] === 'assistant') {
                    $assistantMessageCount++;
                }
            }

            return [
                'success' => true,
                'data' => [
                    'chat' => $chatInfo,
                    'analyses' => $analyses,
                    'messages' => $messages,
                    'statistics' => [
                        'total_messages' => $messageCount,
                        'user_messages' => $userMessageCount,
                        'assistant_messages' => $assistantMessageCount,
                        'total_analyses' => count($analyses)
                    ]
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function findAuditByCode($code) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    a.uuid as audit_uuid
                FROM users_audit ua
                JOIN audits a ON a.id = ua.audit
                WHERE ua.code = :code
                LIMIT 1
            ");
            $stmt->execute(['code' => $code]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return [
                    'success' => false,
                    'error' => 'Code not found'
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'uuid' => $result['audit_uuid']
                ]
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'error' => 'Error finding audit: ' . $e->getMessage()
            ];
        }
    }
} 