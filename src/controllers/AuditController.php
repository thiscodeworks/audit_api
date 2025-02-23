<?php

require_once __DIR__ . '/../models/Audit.php';
require_once __DIR__ . '/../models/Chat.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/PostmarkService.php';
require_once __DIR__ . '/../services/GoogleChatService.php';
require_once __DIR__ . '/../services/AnthropicService.php';

class AuditController {
    private $audit;
    private $chat;
    private $user;
    private $postmark;
    private $googleChat;
    private $db;
    private $anthropic;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->audit = new Audit();
        $this->chat = new Chat();
        $this->user = new User();
        $this->postmark = new PostmarkService();
        $this->googleChat = new GoogleChatService();
        $this->anthropic = new AnthropicService(null);
    }

    public function start($params) {
        try {
            $uuid = $params['uuid'];
            
            // Get data from request body
            $data = json_decode(file_get_contents('php://input'), true);
            $code = $data['code'] ?? null;
            
            error_log("Starting audit with UUID: " . $uuid . ", request data: " . json_encode($data));
            
            // Validate the audit access
            $validation = $this->audit->validateAuditAccess($uuid, $code);
            error_log("Audit access validation result: " . json_encode($validation));
            
            if (!$validation['valid']) {
                http_response_code(401);
                echo json_encode(['error' => $validation['error']]);
                return;
            }

            // Get or create user ID based on audit type
            $userId = $validation['user_id'] ?? null;
            error_log("Initial user ID from validation: " . ($userId ?? 'null'));
            
            // For public audits, create a new user if email is provided
            if (!$userId && isset($validation['audit']) && $validation['audit']['type'] === 'public') {
                $email = $data['email'] ?? null;
                $name = $data['name'] ?? $email; // Use email as name if name is not provided
                $position = $data['position'] ?? null;

                if (!$email) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Email is required for public audits']);
                    return;
                }

                try {
                    error_log("Creating new public user with email: " . $email . ", name: " . $name);
                    // Create new user and get their ID
                    $userId = $this->user->createPublicUser($email, $name, $position);
                    error_log("Created new user with ID: " . $userId);

                    // Verify user was created
                    $createdUser = $this->user->getById($userId);
                    error_log("Verified created user: " . json_encode($createdUser));
                } catch (Exception $e) {
                    error_log("Failed to create user: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Failed to create user',
                        'details' => $e->getMessage(),
                        'data' => [
                            'email' => $email,
                            'name' => $name,
                            'position' => $position
                        ]
                    ]);
                    return;
                }
            }

            try {
                error_log("Creating chat for audit UUID: " . $uuid . " and user ID: " . ($userId ?? 'null'));
                // Create new chat with user ID
                $chatUuid = $this->chat->create($uuid, $userId);
                error_log("Created chat with UUID: " . $chatUuid);

                // Verify chat was created with correct user
                $createdChat = $this->chat->getByUuid($chatUuid);
                error_log("Verified created chat: " . json_encode($createdChat));

                // Get user and audit details for Google Chat notification
                $user = $this->user->getById($userId);
                $auditDetails = $this->audit->getByUuid($uuid);

                // Send notification to Google Chat
                if ($user && $auditDetails) {
                    $this->googleChat->sendAuditStartNotification(
                        $auditDetails['audit_name'],
                        $auditDetails['organization_name'],
                        $user['name'],
                        $user['email']
                    );
                }

                echo json_encode(['data' => ['uuid' => $chatUuid]]);
            } catch (Exception $e) {
                error_log("Failed to create chat: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                http_response_code(500);
                echo json_encode([
                    'error' => 'Failed to create chat',
                    'details' => $e->getMessage(),
                    'data' => [
                        'audit_uuid' => $uuid,
                        'user_id' => $userId
                    ]
                ]);
                return;
            }
        } catch (Exception $e) {
            error_log("Error in AuditController@start: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'validation' => $validation ?? null,
                'request_data' => $data ?? null
            ]);
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

            // Set blocked status with a default value of false if not present
            $audit['blocked'] = $validation['blocked'] ?? false;
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
            error_log("Error in AuditController@get: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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
                    'audit_name' => $audit['audit_name'],
                    'type' => $audit['type'],
                    'company_name' => $audit['company_name'],
                    'employee_count_limit' => (int)$audit['employee_count_limit'],
                    'description' => $audit['description'],
                    'ai_system' => $audit['ai_system'],
                    'organization_name' => $audit['organization_name'],
                    'status' => $audit['status'],
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

            $db = Database::getInstance();
            
            // Get analysis statistics
            $analysisQuery = "
                SELECT 
                    COUNT(DISTINCT s.id) as total_slides,
                    (
                        SELECT html_content 
                        FROM audit_slides 
                        WHERE audit_id = s.audit_id AND is_home = 1 
                        LIMIT 1
                    ) as summary,
                    COUNT(DISTINCT f.id) as total_findings,
                    SUM(CASE WHEN f.severity = 'high' THEN 1 ELSE 0 END) as high_severity,
                    SUM(CASE WHEN f.severity = 'medium' THEN 1 ELSE 0 END) as medium_severity,
                    SUM(CASE WHEN f.severity = 'low' THEN 1 ELSE 0 END) as low_severity
                FROM audit_slides s
                LEFT JOIN audit_findings f ON f.slide_id = s.id
                WHERE s.audit_id = (SELECT id FROM audits WHERE uuid = ?)
                GROUP BY s.audit_id";
            
            $stmt = $db->prepare($analysisQuery);
            $stmt->execute([$uuid]);
            $analysisStats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get categories with their findings count and details
            $categoriesQuery = "
                SELECT 
                    s.id as slide_id,
                    s.name as category,
                    s.description,
                    COUNT(DISTINCT f.id) as findings_count,
                    COALESCE(
                        JSON_ARRAYAGG(
                            IF(f.id IS NOT NULL,
                                JSON_OBJECT(
                                    'id', f.id,
                                    'title', f.title,
                                    'recommendation', f.recommendation,
                                    'severity', f.severity,
                                    'order_index', f.order_index,
                                    'examples', COALESCE(
                                        (
                                            SELECT JSON_ARRAYAGG(
                                                JSON_OBJECT(
                                                    'id', e.id,
                                                    'chat_id', e.chat_id,
                                                    'chat_uuid', c.uuid,
                                                    'description', e.description,
                                                    'username', u.name,
                                                    'created_at', e.created_at
                                                )
                                            )
                                            FROM audit_finding_examples e
                                            LEFT JOIN chats c ON c.id = e.chat_id
                                            LEFT JOIN users u ON u.id = c.user
                                            WHERE e.finding_id = f.id
                                        ),
                                        JSON_ARRAY()
                                    )
                                ),
                                NULL
                            )
                        ),
                        JSON_ARRAY()
                    ) as findings
                FROM audit_slides s
                LEFT JOIN audit_findings f ON f.slide_id = s.id
                WHERE s.audit_id = (SELECT id FROM audits WHERE uuid = ?)
                AND s.is_home = 0
                GROUP BY s.id
                ORDER BY s.order_index";
            
            $stmt = $db->prepare($categoriesQuery);
            $stmt->execute([$uuid]);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Process the findings JSON for each category
            foreach ($categories as &$category) {
                // Decode the JSON string into an array
                $findings = json_decode($category['findings'], true);
                // Remove any null values (from the LEFT JOIN)
                $category['findings'] = array_values(array_filter($findings));
                // Sort findings by order_index
                usort($category['findings'], function($a, $b) {
                    return $a['order_index'] - $b['order_index'];
                });
            }

            $isAssignType = $stats['type'] === 'assign';
            $totalAssigned = $isAssignType ? (int)$stats['total_assigned_users'] : (int)$stats['total_active_users'];
            $totalFilled = (int)$stats['total_active_users'];
            
            echo json_encode([
                'data' => [
                    'type' => $stats['type'],
                    'status' => $stats['status'],
                    'total_users' => $totalAssigned,
                    'total_filled' => $totalFilled,
                    'percentage_filled' => $totalAssigned > 0 ? round(($totalFilled / $totalAssigned) * 100) : 0,
                    'total_chats' => (int)$stats['total_chats'],
                    'total_messages' => (int)$stats['total_messages'],
                    'analysis' => $analysisStats ? [
                        'total_slides' => (int)$analysisStats['total_slides'],
                        'total_findings' => (int)$analysisStats['total_findings'],
                        'severity_distribution' => [
                            'high' => (int)$analysisStats['high_severity'],
                            'medium' => (int)$analysisStats['medium_severity'],
                            'low' => (int)$analysisStats['low_severity']
                        ],
                        'summary' => $analysisStats['summary'],
                        'categories' => $categories
                    ] : null
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
                    'code' => $user['code'],
                    'view' => (bool)$user['view'],
                    'invite' => (bool)$user['invite'],
                    'push' => (bool)$user['push'],
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
                        'goal_fulfill' => (int)$chat['goal_fulfill']
                    ],
                    'created_at' => $chat['created_at'],
                    'last_message_at' => $chat['last_message_at'],
                    'state' => $chat['state'],
                    'has_analysis' => isset($chat['id']) && isset($chat['analyze_id']),
                    'analysis' => [
                        'summary' => $chat['summary'] ?? '',
                        'keyfindings' => $chat['keyfindings'] ?? ''
                    ]
                ];
            }, $chats);

            echo json_encode([
                'data' => $formattedChats,
                'total' => count($formattedChats)
            ]);
        } catch (Exception $e) {
            var_dump($e->getMessage());
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

            // Prepare base template data
            $templateModel = [
                "action_url" => getenv('FRONTEND_URL') . "/audit/{$audit['uuid']}?code={$accessCode}",
                "company" => $audit['organization_name']
            ];

            // Set template alias and additional data based on type
            if ($data['type'] === 'invitation') {
                $templateAlias = 'user-invitation';
                $emailSubject = "Pozvánka k auditu: " . $audit['organization_name'];
            } else if ($data['type'] === 'push') {
                $templateAlias = 'user-push';
                $emailSubject = "Připomenutí auditu: " . $audit['organization_name'];
                // Add completed_count for push template
                $templateModel['completed_count'] = (int)$audit['total_active_users'];
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid template type']);
                return;
            }

            error_log("Template model prepared: " . json_encode($templateModel));
            error_log("Using template alias: " . $templateAlias);

            // Send email using template
            error_log("Sending template email to: " . $user['email']);
            $result = $this->postmark->sendTemplate(
                $user['email'],
                $templateAlias,
                $templateModel,
                $emailSubject
            );
            error_log("Postmark result: " . json_encode($result));

            if (!$result['success']) {
                error_log("Failed to send Postmark template email. Error: " . json_encode($result));
                http_response_code(500);
                echo json_encode(['error' => 'Failed to send email', 'details' => $result['error']]);
                return;
            }

            // Update user's email status in users_audit table
            error_log("Updating user email status for audit ID: " . $audit['id'] . " and user ID: " . $user['id']);
            $this->audit->updateUserEmailStatus($audit['id'], $user['id'], $data['type']);

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

    public function getAvailableUsers($params) {
        try {
            $uuid = $params['uuid'];
            $users = $this->audit->getAvailableUsers($uuid);
            
            if (!$users) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found']);
                return;
            }

            echo json_encode([
                'data' => $users,
                'total' => count($users)
            ]);
        } catch (Exception $e) {
            error_log("Error in AuditController@getAvailableUsers: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function assignUser($params) {
        try {
            $uuid = $params['uuid'];
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['userId'])) {
                http_response_code(400);
                echo json_encode(['error' => 'User ID is required']);
                return;
            }

            $result = $this->audit->assignUser($uuid, $data['userId']);
            
            if (isset($result['error'])) {
                http_response_code(404);
                echo json_encode(['error' => $result['error']]);
                return;
            }

            echo json_encode([
                'success' => true,
                'code' => $result['code']
            ]);
        } catch (Exception $e) {
            error_log("Error in AuditController@assignUser: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'details' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public function preview() {
        try {
            // Get JSON input
            $data = json_decode(file_get_contents('php://input'), true);
            error_log("Preview audit data: " . json_encode($data));

            // Validate required fields
            $requiredFields = ['title', 'description', 'type', 'organization_id', 'questions'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: {$field}"]);
                    return;
                }
            }

            // Get organization details
            $stmt = $this->db->prepare("SELECT name FROM organizations WHERE id = ?");
            $stmt->execute([$data['organization_id']]);
            $organization = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$organization) {
                http_response_code(404);
                echo json_encode(['error' => 'Organization not found']);
                return;
            }

            // Base HTML template
            $baseTemplate = '<div class="max-w-2xl mx-auto p-4 space-y-4">
                <div class="text-center space-y-2">
                    <img src="/uploads/organizations/{{organization_id}}/logo.png" alt="{{company_name}} Logo" class="h-12 mx-auto mb-4">
                    <h1 class="text-2xl font-bold">{{welcome_title}}</h1>
                    <p class="text-lg text-gray-600">{{welcome_subtitle}}</p>
                </div>

                <div class="bg-white border rounded-lg shadow-sm">
                    <div class="p-4">
                        <h2 class="text-xl font-semibold mb-2">O auditu</h2>
                        <div class="space-y-2 text-sm">
                            <p>{{audit_description}}</p>
                            <div class="flex items-center space-x-2 text-gray-600">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>Doba trvání: {{duration}}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white border rounded-lg shadow-sm">
                    <div class="p-4">
                        <h2 class="text-xl font-semibold mb-2">Oblasti auditu</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                            {{audit_areas}}
                        </div>
                    </div>
                </div>

                <div class="bg-gray-600 text-white rounded-lg shadow-sm" style="background-color: #323232;">
                    <div class="p-4">
                        <p class="text-base mb-3 text-center">{{cta_text}}</p>
                        <button class="w-full py-3 px-4 bg-white text-blue-600 rounded-md text-lg font-semibold flex items-center justify-center">
                            {{button_text}}
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="space-y-2 text-center text-xs text-gray-600">
                    <p>{{thank_you_text}}</p>
                    <p>{{privacy_text}}</p>
                </div>
            </div>';

            // Prepare the prompt for Anthropic
            $prompt = "You are a helpful assistant that generates customized welcome pages for workplace audits. 
            Based on the following audit information, generate appropriate text content for each placeholder in the template:

            Audit Title: {$data['title']}
            Company: {$organization['name']}
            Description: {$data['description']}
            Questions: " . implode(', ', $data['questions']) . "

            Generate JSON with the following fields:
            - welcome_title: A welcoming title
            - welcome_subtitle: A subtitle explaining the purpose
            - audit_description: Detailed description of the audit
            - duration: Estimated duration (based on number of questions)
            - audit_areas: Array of key areas based on the questions (maximum 8 areas)
            - cta_text: Call to action text
            - button_text: Button text
            - thank_you_text: Thank you message
            - privacy_text: Privacy notice

            Keep the tone professional but friendly, and make sure the content is in Czech language.
            The audit_areas should be derived from and related to the questions provided.";

            // Get customized content from Anthropic
            $anthropicResponse = $this->anthropic->generateContent($prompt);
            $customContent = json_decode($anthropicResponse, true);

            if (!$customContent) {
                throw new Exception("Failed to generate customized content");
            }

            // Generate audit areas HTML
            $areasHtml = '';
            foreach ($customContent['audit_areas'] as $area) {
                $areasHtml .= '<div class="flex items-center space-x-2">
                    <div class="w-1.5 h-1.5 bg-blue-600 rounded-full"></div>
                    <span>' . htmlspecialchars($area) . '</span>
                </div>';
            }

            // Replace placeholders in template
            $customizedHtml = str_replace(
                [
                    '{{company_name}}',
                    '{{organization_id}}',
                    '{{welcome_title}}',
                    '{{welcome_subtitle}}',
                    '{{audit_description}}',
                    '{{duration}}',
                    '{{audit_areas}}',
                    '{{cta_text}}',
                    '{{button_text}}',
                    '{{thank_you_text}}',
                    '{{privacy_text}}'
                ],
                [
                    htmlspecialchars($organization['name']),
                    htmlspecialchars($data['organization_id']),
                    htmlspecialchars($customContent['welcome_title']),
                    htmlspecialchars($customContent['welcome_subtitle']),
                    htmlspecialchars($customContent['audit_description']),
                    htmlspecialchars($customContent['duration']),
                    $areasHtml,
                    htmlspecialchars($customContent['cta_text']),
                    htmlspecialchars($customContent['button_text']),
                    htmlspecialchars($customContent['thank_you_text']),
                    htmlspecialchars($customContent['privacy_text'])
                ],
                $baseTemplate
            );

            // Prepare preview response
            $preview = [
                'audit_name' => $data['title'],
                'company_name' => $organization['name'],
                'description' => $data['description'],
                'type' => $data['type'],
                'organization_id' => $data['organization_id'],
                'questions' => $data['questions'],
                'html_template' => $customizedHtml,
                'preview_data' => [
                    'total_questions' => count($data['questions']),
                    'estimated_duration' => count($data['questions']) * 3 . '-' . count($data['questions']) * 5 . ' minutes',
                    'type_description' => $data['type'] === 'public' ? 'Public audit - anyone with the link can participate' : 'Assigned audit - only invited users can participate'
                ]
            ];

            echo json_encode(['data' => $preview]);
        } catch (Exception $e) {
            error_log("Error in AuditController@preview: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public function create() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['title']) || empty($data['title'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Audit title is required']);
                return;
            }

            if (!isset($data['organization_id']) || empty($data['organization_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Organization is required']);
                return;
            }

            // Get authenticated user for audit creation
            $userData = AuthMiddleware::getAuthenticatedUser();
            
            try {
                // Get organization name for company_name
                $stmt = $this->db->prepare("SELECT name FROM organizations WHERE id = ?");
                $stmt->execute([$data['organization_id']]);
                $organization = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$organization) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Organization not found']);
                    return;
                }

                $auditData = [
                    'title' => $data['title'],  // This will be used as audit_name
                    'company_name' => $organization['name'],
                    'organization' => $data['organization_id'],
                    'type' => $data['type'] ?? 'assign',
                    'description' => $data['description'] ?? null,
                    'employee_count_limit' => $data['employee_count_limit'] ?? 0,
                    'ai_system' => $data['template'] ?? null,
                    'ai_prompt' => null,
                    'audit_data' => [
                        'questions' => $data['questions'] ?? [],
                        'users' => $data['users'] ?? []
                    ]
                ];

                $uuid = $this->audit->create($auditData);
                
                if ($uuid) {
                    // If users are provided, assign them to the audit
                    if (!empty($data['users'])) {
                        foreach ($data['users'] as $userId) {
                            $this->audit->assignUser($uuid, $userId);
                        }
                    }

                    echo json_encode([
                        'message' => 'Audit created successfully',
                        'data' => [
                            'uuid' => $uuid
                        ]
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to create audit']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Failed to create audit',
                    'message' => $e->getMessage()
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ]);
        }
    }
}