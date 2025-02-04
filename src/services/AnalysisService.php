<?php
require_once __DIR__ . '/../models/Analysis.php';
require_once __DIR__ . '/../services/AnthropicService.php';
require_once __DIR__ . '/../utils/Env.php';
require_once __DIR__ . '/../services/PusherService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class AnalysisService {
    private $analysis;
    private $anthropic;

    public function __construct() {
        $this->analysis = new Analysis();
        $this->anthropic = new AnthropicService();
    }

    public function analyzeSingleChat($chatUuid) {
        try {
            // Get chat messages
            $messages = $this->analysis->getChatMessages($chatUuid);
            
            if (empty($messages)) {
                return [
                    'success' => false,
                    'error' => 'No messages found for this chat'
                ];
            }

            // Check if there's at least one user message
            $hasUserMessage = false;
            foreach ($messages as $msg) {
                if ($msg['role'] === 'user') {
                    $hasUserMessage = true;
                    break;
                }
            }

            if (!$hasUserMessage) {
                return [
                    'success' => false,
                    'error' => 'Chat must have at least one user message to be analyzed'
                ];
            }
            
            // Prepare messages for analysis
            $conversation = "";
            foreach ($messages as $msg) {
                $role = $msg['role'] === 'user' ? 'Customer' : 'Assistant';
                $conversation .= "{$role}: {$msg['content']}\n\n";
            }

            // Create analysis prompt
            $prompt = "Analyze the following customer service conversation and provide your analysis in JSON format with the following structure:
{
    \"sentiment\": [number between 0-100],
    \"summary\": [brief summary in Czech language],
    \"findings\": [key findings and insights as a single Czech sentence]
}

The sentiment score should be 0-100 where:
- 0-30: Velmi negativní
- 31-45: Negativní
- 46-55: Neutrální
- 56-75: Pozitivní
- 76-100: Velmi pozitivní

Provide the summary and findings in Czech language. The findings should be a single coherent sentence that captures all important insights.

Conversation:
" . $conversation . "

Respond ONLY with the JSON object, no additional text.";

            // Get analysis from Anthropic
            $response = $this->anthropic->analyze($prompt);
            
            // Parse JSON response
            $analysis = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Error parsing Anthropic response: " . json_last_error_msg());
                error_log("Raw response: " . $response);
                return [
                    'success' => false,
                    'error' => 'Failed to parse analysis response'
                ];
            }

            // Get chat ID from UUID
            $stmt = Database::getInstance()->prepare("SELECT id FROM chats WHERE uuid = ?");
            $stmt->execute([$chatUuid]);
            $chat = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$chat) {
                return [
                    'success' => false,
                    'error' => 'Chat not found'
                ];
            }

            // Save analysis
            $this->analysis->create(
                $chat['id'],
                $analysis['sentiment'],
                $analysis['summary'],
                $analysis['findings']
            );

            return [
                'success' => true,
                'data' => [
                    'sentiment' => $analysis['sentiment'],
                    'summary' => $analysis['summary'],
                    'findings' => $analysis['findings']
                ]
            ];
        } catch (Exception $e) {
            error_log("Error analyzing chat {$chatUuid}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function analyzePendingChats() {
        try {
            $chats = $this->analysis->getChatsNeedingAnalysis();
            
            if (empty($chats)) {
                return [
                    'success' => true,
                    'message' => 'No chats need analysis at this time'
                ];
            }

            $chat = $chats[0]; // Get the first (and only) chat
            
            try {
                // Get chat messages
                $messages = $this->analysis->getChatMessages($chat['chat_uuid']);
                
                // Prepare messages for analysis
                $conversation = "";
                foreach ($messages as $msg) {
                    $role = $msg['role'] === 'user' ? 'Customer' : 'Assistant';
                    $conversation .= "{$role}: {$msg['content']}\n\n";
                }

                // Create analysis prompt
                $prompt = "Analyze the following customer service conversation and provide your analysis in JSON format with the following structure:
{
    \"sentiment\": [number between 0-100],
    \"summary\": [brief summary in Czech language],
    \"findings\": [key findings and insights as a single Czech sentence]
}

The sentiment score should be 0-100 where:
- 0-30: Velmi negativní
- 31-45: Negativní
- 46-55: Neutrální
- 56-75: Pozitivní
- 76-100: Velmi pozitivní

Provide the summary and findings in Czech language. The findings should be a single coherent sentence that captures all important insights.

Conversation:
" . $conversation . "

Respond ONLY with the JSON object, no additional text.";

                // Get analysis from Anthropic
                $response = $this->anthropic->analyze($prompt);
                
                // Parse JSON response
                $analysis = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("Error parsing Anthropic response: " . json_last_error_msg());
                    error_log("Raw response: " . $response);
                    return [
                        'success' => false,
                        'error' => 'Failed to parse analysis response'
                    ];
                }

                // Save analysis
                $this->analysis->create(
                    $chat['chat_id'],
                    $analysis['sentiment'],
                    $analysis['summary'],
                    $analysis['findings']
                );

                return [
                    'success' => true,
                    'data' => [
                        'chat_uuid' => $chat['chat_uuid'],
                        'sentiment' => $analysis['sentiment'],
                        'summary' => $analysis['summary'],
                        'findings' => $analysis['findings']
                    ]
                ];
            } catch (Exception $e) {
                error_log("Error analyzing chat {$chat['chat_uuid']}: " . $e->getMessage());
                return [
                    'success' => false,
                    'error' => "Error analyzing chat {$chat['chat_uuid']}: " . $e->getMessage()
                ];
            }
        } catch (Exception $e) {
            error_log("Error in analysis service: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getChatDetail($chatUuid) {
        try {
            return $this->analysis->getChatDetail($chatUuid);
        } catch (Exception $e) {
            error_log("Error getting chat detail: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function findAuditByCode($code) {
        try {
            return $this->analysis->findAuditByCode($code);
        } catch (Exception $e) {
            error_log("Error finding audit by code: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getDashboardStats() {
        try {
            $db = Database::getInstance();
            
            // Get current user's ID from AuthMiddleware
            $userData = AuthMiddleware::getAuthenticatedUser();
            $userId = $userData->id;
            
            // Verify user exists
            $userQuery = "SELECT id FROM users WHERE id = ?";
            $userStmt = $db->prepare($userQuery);
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }

            // Get user's organizations
            $orgsQuery = "SELECT organization FROM users_organization WHERE user = ?";
            $orgsStmt = $db->prepare($orgsQuery);
            $orgsStmt->execute([$userId]);
            $organizations = $orgsStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($organizations)) {
                throw new Exception('User not associated with any organization');
            }

            $orgPlaceholders = str_repeat('?,', count($organizations) - 1) . '?';
            
            // Get running audits (audits with open chats) for user's organizations
            $runningAuditsQuery = "SELECT COUNT(DISTINCT a.id) as count 
                                 FROM audits a 
                                 JOIN chats c ON a.uuid = c.audit_uuid 
                                 WHERE c.state = 'open'
                                 AND a.organization IN ($orgPlaceholders)";
            $stmt = $db->prepare($runningAuditsQuery);
            $stmt->execute($organizations);
            $runningAudits = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Get total assigned users for these organizations
            $activeUsersQuery = "SELECT COUNT(DISTINCT ua.user) as count 
                               FROM users_audit ua 
                               JOIN audits a ON ua.audit = a.id
                               WHERE a.organization IN ($orgPlaceholders)";
            $stmt = $db->prepare($activeUsersQuery);
            $stmt->execute($organizations);
            $activeUsers = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Get engaged users (users who have sent at least one message) for these organizations
            $engagedUsersQuery = "SELECT COUNT(DISTINCT u.id) as count 
                                FROM users u 
                                JOIN chats c ON c.user = u.id 
                                JOIN messages m ON m.chat_uuid = c.uuid 
                                JOIN audits a ON c.audit_uuid = a.uuid
                                WHERE m.role = 'user'
                                AND a.organization IN ($orgPlaceholders)";
            $stmt = $db->prepare($engagedUsersQuery);
            $stmt->execute($organizations);
            $engagedUsers = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Calculate engagement rate
            $engagementRate = $activeUsers > 0 ? round(($engagedUsers / $activeUsers) * 100, 2) : 0;

            // Get completed audits (all chats in finished state) for these organizations
            $completedAuditsQuery = "SELECT COUNT(DISTINCT a.id) as count 
                                   FROM audits a 
                                   JOIN chats c ON a.uuid = c.audit_uuid 
                                   WHERE c.state = 'finished'
                                   AND a.organization IN ($orgPlaceholders)";
            $stmt = $db->prepare($completedAuditsQuery);
            $stmt->execute($organizations);
            $completedAudits = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Get messages per day for last 14 days for these organizations
            $messagesQuery = "SELECT DATE(m.created_at) as date, COUNT(*) as count 
                            FROM messages m
                            JOIN chats c ON m.chat_uuid = c.uuid
                            JOIN audits a ON c.audit_uuid = a.uuid
                            WHERE m.role = 'user' 
                            AND m.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 14 DAY)
                            AND a.organization IN ($orgPlaceholders)
                            GROUP BY DATE(m.created_at) 
                            ORDER BY date";
            $stmt = $db->prepare($messagesQuery);
            $stmt->execute($organizations);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get completed audits per day for last 14 days for these organizations
            $auditsCompletedQuery = "SELECT DATE(c.updated_at) as date, COUNT(DISTINCT a.id) as count 
                                   FROM chats c
                                   JOIN audits a ON c.audit_uuid = a.uuid
                                   WHERE c.state = 'finished' 
                                   AND c.updated_at >= DATE_SUB(CURRENT_DATE, INTERVAL 14 DAY)
                                   AND a.organization IN ($orgPlaceholders)
                                   GROUP BY DATE(c.updated_at) 
                                   ORDER BY date";
            $stmt = $db->prepare($auditsCompletedQuery);
            $stmt->execute($organizations);
            $auditsCompleted = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'data' => [
                    'summary' => [
                        'running_audits' => $runningAudits,
                        'active_users' => $activeUsers,
                        'engagement_rate' => $engagementRate,
                        'completed_audits' => $completedAudits
                    ],
                    'charts' => [
                        'messages_per_day' => $messages,
                        'audits_completed_per_day' => $auditsCompleted
                    ]
                ]
            ];
        } catch (Exception $e) {
            error_log("Error getting dashboard stats: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
} 