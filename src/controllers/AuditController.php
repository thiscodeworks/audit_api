<?php

require_once __DIR__ . '/../models/Audit.php';
require_once __DIR__ . '/../models/Chat.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/PostmarkService.php';

class AuditController {
    private $audit;
    private $chat;
    private $user;
    private $postmark;

    public function __construct() {
        $this->audit = new Audit();
        $this->chat = new Chat();
        $this->user = new User();
        $this->postmark = new PostmarkService();
    }

    public function start($params) {
        try {
            $uuid = $params['uuid'];
            
            // Get data from request body
            $data = json_decode(file_get_contents('php://input'), true);
            $code = $data['code'] ?? null;
            
            // Validate the audit access
            $validation = $this->audit->validateAuditAccess($uuid, $code);
            if (!$validation['valid']) {
                http_response_code(401);
                echo json_encode(['error' => $validation['error']]);
                return;
            }

            // Create new chat with user ID if this is an assigned audit
            $userId = $validation['user_id'] ?? null;
            $chatUuid = $this->chat->create($uuid, $userId);
            
            echo json_encode(['data' => ['uuid' => $chatUuid]]);
        } catch (Exception $e) {
            error_log("Error in AuditController@start: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    public function get($params) {
        try {
            $uuid = $params['uuid'];
            
            // Get the code from query parameters
            parse_str($_SERVER['QUERY_STRING'], $query);
            $code = $query['code'] ?? null;
            
            // Validate the audit access
            $validation = $this->audit->validateAuditAccess($uuid, $code);
            if (!$validation['valid']) {
                http_response_code(401);
                echo json_encode(['error' => $validation['error']]);
                return;
            }

            $audit = $this->audit->getByUuid($uuid);
            
            if (!$audit) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found']);
                return;
            }

            // Set auth type based on validation
            $audit["auth_type"] = $audit['type'] === 'assign' ? 'code' : 'public';

            // Remove sensitive/unnecessary fields
            unset($audit['access_codes']);
            unset($audit['total_assigned_users']);
            unset($audit['total_active_users']);
            unset($audit['total_chats']);
            unset($audit['total_messages']);
            unset($audit['ai_prompt']);
            unset($audit['ai_system']);
            unset($audit['id']);
            unset($audit['audit_data']);
            unset($audit['created_at']);
            unset($audit['updated_at']);
            unset($audit['creator_name']);

            echo json_encode(["data" => $audit]);
        } catch (Exception $e) {
            error_log("Error in AuditController@get: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function list() {
        try {
            $audits = $this->audit->getAll();
            
            // Format the response
            $formattedAudits = array_map(function($audit) {
                $isAssignType = $audit['type'] === 'assign';
                
                $baseAudit = [
                    'id' => $audit['id'],
                    'uuid' => $audit['uuid'],
                    'type' => $audit['type'],
                    'company_name' => $audit['company_name'],
                    'employee_count_limit' => (int)$audit['employee_count_limit'],
                    'description' => $audit['description'],
                    'ai_system' => $audit['ai_system'],
                    'creator' => [
                        'name' => $audit['creator_name'],
                        'company' => $audit['creator_company']
                    ],
                    'stats' => [
                        'total_chats' => (int)$audit['total_chats'],
                        'total_messages' => (int)$audit['total_messages'],
                        'total_active_users' => (int)$audit['total_active_users']
                    ],
                    'created_at' => $audit['created_at'],
                    'updated_at' => $audit['updated_at']
                ];

                // Add assigned users count only for assign type
                if ($isAssignType) {
                    $baseAudit['stats']['total_assigned_users'] = (int)$audit['total_assigned_users'];
                }

                return $baseAudit;
            }, $audits);

            echo json_encode([
                'data' => $formattedAudits,
                'total' => count($formattedAudits)
            ]);
        } catch (Exception $e) {
            error_log("Error in AuditController@list: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function stats($params) {
        try {
            $uuid = $params['uuid'];
            $stats = $this->audit->getStats($uuid);
            
            if (!$stats) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found']);
                return;
            }

            $isAssignType = $stats['type'] === 'assign';
            $totalAssigned = $isAssignType ? (int)$stats['total_assigned_users'] : (int)$stats['total_active_users'];
            $totalFilled = (int)$stats['total_active_users'];
            
            echo json_encode([
                'data' => [
                    'type' => $stats['type'],
                    'total_users' => $totalAssigned,
                    'total_filled' => $totalFilled,
                    'percentage_filled' => $totalAssigned > 0 ? round(($totalFilled / $totalAssigned) * 100) : 0,
                    'total_chats' => (int)$stats['total_chats'],
                    'total_messages' => (int)$stats['total_messages'],
                    'sentiment_distribution' => $stats['sentiment']
                ]
            ]);
        } catch (Exception $e) {
            error_log("Error in AuditController@stats: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function users($params) {
        try {
            $uuid = $params['uuid'];
            $users = $this->audit->getUsers($uuid);
            
            $formattedUsers = array_map(function($user) {
                return [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'state' => $user['state'],
                    'stats' => [
                        'chats' => (int)$user['chat_count'],
                        'messages' => (int)$user['message_count']
                    ]
                ];
            }, $users);

            echo json_encode([
                'data' => $formattedUsers,
                'total' => count($formattedUsers)
            ]);
        } catch (Exception $e) {
            error_log("Error in AuditController@users: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function chats($params) {
        try {
            $uuid = $params['uuid'];
            $chats = $this->audit->getChats($uuid);
            
            $formattedChats = array_map(function($chat) {
                return [
                    'uuid' => $chat['chat_uuid'],
                    'username' => $chat['username'],
                    'sentiment' => $chat['sentiment'],
                    'stats' => [
                        'messages' => (int)$chat['message_count'],
                        'goal_fulfill' => $chat['goal_fulfill']
                    ],
                    'created_at' => $chat['created_at']
                ];
            }, $chats);

            echo json_encode([
                'data' => $formattedChats,
                'total' => count($formattedChats)
            ]);
        } catch (Exception $e) {
            error_log("Error in AuditController@chats: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function find() {
        try {
            // Get code from POST data
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['code'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Code is required'
                ]);
                return;
            }

            // Use the Audit model to find the audit by code
            $result = $this->audit->findByCode($data['code']);
            
            if (!$result) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Code not found'
                ]);
                return;
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'uuid' => $result['uuid']
                ]
            ]);
        } catch (Exception $e) {
            error_log("Error in AuditController@find: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]);
        }
    }

    public function mail($params) {
        try {
            $uuid = $params['uuid'];
            error_log("Mail request for audit UUID: " . $uuid);
            
            $data = json_decode(file_get_contents('php://input'), true);
            error_log("Received data: " . json_encode($data));
            
            if (!isset($data['userId']) || !isset($data['type'])) {
                http_response_code(400);
                echo json_encode(['error' => 'userId and type are required']);
                return;
            }

            // Get audit details
            error_log("Fetching audit details for UUID: " . $uuid);
            $audit = $this->audit->getByUuid($uuid);
            error_log("Audit data: " . json_encode($audit));
            if (!$audit) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found']);
                return;
            }

            // Get user details
            error_log("Fetching user details for ID: " . $data['userId']);
            $user = $this->user->getById($data['userId']);
            error_log("User data: " . json_encode($user));
            if (!$user) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            // Get user's access code for this audit
            error_log("Fetching access code for audit ID: " . $audit['id'] . " and user ID: " . $user['id']);
            $accessCode = $this->audit->getUserAccessCode($audit['id'], $user['id']);
            error_log("Access code: " . ($accessCode ?: 'null'));
            if (!$accessCode) {
                http_response_code(400);
                echo json_encode(['error' => 'User does not have access to this audit']);
                return;
            }

            // Prepare template data
            $templateModel = [
                "action_url" => getenv('FRONTEND_URL') . "/audit/{$audit['uuid']}?code={$accessCode}",
                "company" => $audit['organization_name']
            ];
            error_log("Template model prepared: " . json_encode($templateModel));

            // Send email using template
            error_log("Sending template email to: " . $user['email']);
            $result = $this->postmark->sendTemplate(
                $user['email'],
                'user-invitation',  // Using template alias instead of ID
                $templateModel,
                "Pozvánka k auditu: " . $audit['organization_name']
            );
            error_log("Postmark result: " . json_encode($result));

            if (!$result['success']) {
                error_log("Failed to send Postmark template email. Error: " . json_encode($result));
                http_response_code(500);
                echo json_encode(['error' => 'Failed to send email', 'details' => $result['error']]);
                return;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Email notification sent successfully'
            ]);

        } catch (Exception $e) {
            error_log("Error in AuditController@mail: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}