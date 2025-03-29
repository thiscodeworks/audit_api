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
            
            
            // For public audits, create a new user with any available user data (can be anonymous)
            if (!$userId && isset($validation['audit']) && $validation['audit']['type'] === 'public') {
                // Collect any user data that might be provided
                $userData = [
                    'email' => $data['email'] ?? null,
                    'name' => $data['name'] ?? null,
                    'position' => $data['position'] ?? null,
                    'phone' => $data['phone'] ?? null
                ];
                
                // If name is not provided but email is, use email as name
                if (empty($userData['name']) && !empty($userData['email'])) {
                    $userData['name'] = $userData['email'];
                }
                
                try {
                    error_log("Creating new anonymous user with data: " . json_encode($userData));
                    // Create new user and get their ID using the anonymous user method
                    $userId = $this->user->createAnonymousUser($userData);
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
                        'data' => $userData
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
                                    'description', f.description,
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
                
                // Process recommendations and descriptions to preserve newlines
                foreach ($category['findings'] as &$finding) {
                    if (isset($finding['recommendation'])) {
                        // Convert newlines to <br> tags for proper HTML display
                        $finding['recommendation'] = nl2br($finding['recommendation']);
                    }
                    if (isset($finding['description'])) {
                        // Convert newlines to <br> tags for proper HTML display
                        $finding['description'] = nl2br($finding['description']);
                    }
                }
            }

            // Get tags cloud data
            $tagsQuery = "
                SELECT 
                    tag,
                    weight
                FROM audit_tags_cloud
                WHERE audit_id = (SELECT id FROM audits WHERE uuid = ?)
                ORDER BY weight DESC";
            
            $stmt = $db->prepare($tagsQuery);
            $stmt->execute([$uuid]);
            $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                        'categories' => $categories,
                        'tags_cloud' => $tags
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

            // Base HTML template - updated to match the new UI
            $baseTemplate = '<div class="flex flex-col px-0">
            <h3 class="tracking-tight text-2xl font-bold text-primary mb-2 mt-0">{{welcome_title}}</h3>
            <p class="text-sm text-muted-foreground">{{welcome_subtitle}}</p>
        </div>
        <div class="space-y-6">
            <p>{{audit_description}}</p>
            <section>
                <h2 class="text-lg font-semibold mb-4 text-primary">Klíčové oblasti auditu:</h2>
                <ul class="space-y-3 p-0 m-0 items-center">
                    {{audit_areas}}
                </ul>
            </section>
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

            Keep the tone professional but friendly, and make sure the content is in Czech language.
            The audit_areas should be derived from and related to the questions provided.
            
            Respond ONLY with a valid JSON object with all the fields listed above.";

            // Get customized content from Anthropic
            $anthropicResponse = $this->anthropic->generateContent($prompt);
            $customContent = json_decode($anthropicResponse, true);

            if (!$customContent) {
                throw new Exception("Failed to generate customized content");
            }

            // Ensure all required fields are present with defaults
            $requiredFields = [
                'welcome_title' => 'Vítejte v našem auditu',
                'welcome_subtitle' => 'Děkujeme za vaši účast',
                'audit_description' => $data['description'],
                'duration' => count($data['questions']) * 3 . '-' . count($data['questions']) * 5 . ' minut',
                'audit_areas' => []
            ];
            
            foreach ($requiredFields as $field => $defaultValue) {
                if (!isset($customContent[$field])) {
                    $customContent[$field] = $defaultValue;
                    error_log("Using default value for missing field: {$field}");
                }
            }

            // If audit_areas is empty or not an array, provide some defaults based on the title
            if (empty($customContent['audit_areas']) || !is_array($customContent['audit_areas'])) {
                $customContent['audit_areas'] = ['Zpětná vazba', 'Anonymní hlášení', 'Bezpečnost', 'Pracovní prostředí'];
                error_log("Using default audit areas since none were provided");
            }

            // Generate audit areas HTML with svg icons similar to the new template
            $areasHtml = '';
            $icons = [
                '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users w-5 h-5 text-primary mr-3"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
                '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-target w-5 h-5 text-primary mr-3"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>',
                '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-map w-5 h-5 text-primary mr-3"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"></polygon><line x1="9" x2="9" y1="3" y2="18"></line><line x1="15" x2="15" y1="6" y2="21"></line></svg>',
                '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-barrier-block w-5 h-5 text-primary mr-3"><rect x="4" y="2" width="16" height="20" rx="2"></rect><path d="M4 14h16"></path><path d="M4 18h16"></path><path d="M4 10h16"></path><path d="M4 6h16"></path></svg>',
                '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-list-todo w-5 h-5 text-primary mr-3"><rect x="3" y="5" width="6" height="6" rx="1"></rect><path d="m3 17 2 2 4-4"></path><path d="M13 6h8"></path><path d="M13 12h8"></path><path d="M13 18h8"></path></svg>',
                '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trending-up w-5 h-5 text-primary mr-3"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>'
            ];

            foreach ($customContent['audit_areas'] as $index => $area) {
                $iconIndex = $index % count($icons);
                $areasHtml .= '<li class="flex items-center">' . $icons[$iconIndex] . '<span class="pl-2">' . htmlspecialchars($area) . '</span></li>';
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
                    '{{audit_areas}}'
                ],
                [
                    htmlspecialchars($organization['name']),
                    htmlspecialchars($data['organization_id']),
                    htmlspecialchars($customContent['welcome_title']),
                    htmlspecialchars($customContent['welcome_subtitle']),
                    htmlspecialchars($customContent['audit_description']),
                    htmlspecialchars($customContent['duration']),
                    $areasHtml
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
    
    public function request() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            if (!isset($data['request']) || empty($data['request'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Request content is required']);
                return;
            }

            if (!isset($data['organization_id']) || empty($data['organization_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Organization ID is required']);
                return;
            }

            if (!isset($data['created_by']) || empty($data['created_by'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Creator information is required']);
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

                // Create a title for the audit based on the request type
                $title = 'Audit na míru';
                
                // Create the audit with the custom request data
                $auditData = [
                    'title' => $title,
                    'company_name' => $organization['name'],
                    'organization' => $data['organization_id'],
                    'type' => 'public', // Custom requests are always public
                    'status' => 'requested', // Mark as requested status
                    'description' => $data['request'],
                    'employee_count_limit' => 0,
                    'ai_system' => $data['template'] ?? 'custom-request',
                    'ai_prompt' => null,
                    'audit_data' => [
                        'request' => $data['request'],
                        'created_by' => $data['created_by'],
                        'template' => $data['template'] ?? 'custom-request'
                    ]
                ];

                $uuid = $this->audit->create($auditData);
                
                if ($uuid) {
                    // Log creation
                    error_log("Custom request audit created with UUID: " . $uuid);
                    
                    // Send notification to Google Chat
                    try {
                        $this->googleChat->sendCustomRequestNotification(
                            $title,
                            $organization['name'],
                            $data['created_by']['name'] ?? 'Unknown User',
                            $data['request'],
                            $uuid
                        );
                        error_log("Google Chat notification sent for custom request: " . $uuid);
                    } catch (Exception $e) {
                        // Just log the error but don't fail the request
                        error_log("Failed to send Google Chat notification: " . $e->getMessage());
                    }
                    
                    echo json_encode([
                        'message' => 'Custom request created successfully',
                        'data' => [
                            'uuid' => $uuid
                        ]
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to create custom request']);
                }
            } catch (Exception $e) {
                error_log("Error creating custom request: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                http_response_code(500);
                echo json_encode([
                    'error' => 'Failed to create custom request',
                    'message' => $e->getMessage()
                ]);
            }
        } catch (Exception $e) {
            error_log("Error in AuditController@request: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ]);
        }
    }

    public function prompt() {
        try {
            // Get JSON input (same payload as preview)
            $data = json_decode(file_get_contents('php://input'), true);
            error_log("Prompt received data: " . json_encode($data));

            // Check if uuid is provided as audit_uuid instead
            if (isset($data['audit_uuid']) && !isset($data['uuid'])) {
                $data['uuid'] = $data['audit_uuid'];
            }

            // Validate required fields
            $requiredFields = ['title', 'description', 'type', 'organization_id', 'questions', 'uuid'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: {$field}"]);
                    return;
                }
            }

            // Verify the audit UUID exists
            $auditCheck = $this->audit->getByUuid($data['uuid']);
            if (!$auditCheck) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found with provided UUID']);
                return;
            }

            // Get organization details for company name
            $stmt = $this->db->prepare("SELECT name FROM organizations WHERE id = ?");
            $stmt->execute([$data['organization_id']]);
            $organization = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$organization) {
                http_response_code(404);
                echo json_encode(['error' => 'Organization not found']);
                return;
            }

            // Prepare audit data for the prompt generator
            $auditData = [
                'title' => $data['title'],
                'description' => $data['description'],
                'type' => $data['type'],
                'company_name' => $organization['name'],
                'organization_name' => $organization['name'],
                'questions' => $data['questions'],
                // Include additional data if available
                'employee_count_limit' => $data['employee_count_limit'] ?? 0,
                'template' => $data['template'] ?? null
            ];

            // Load the PromptGeneratorService
            require_once __DIR__ . '/../services/PromptGeneratorService.php';
            $promptGenerator = new PromptGeneratorService();

            // Generate the prompt
            $result = $promptGenerator->generateAuditPrompt($auditData);

            if ($result['success'] || isset($result['prompt'])) {
                // Get the prompt from result - either from a successful generation or fallback
                $promptXml = isset($result['prompt']) ? $result['prompt'] : '';
                
                // Update the audit with the generated prompt
                try {
                    // Update the audit with the new ai_system (prompt) and description
                    $updateStmt = $this->db->prepare("
                        UPDATE audits 
                        SET ai_system = ?, ai_prompt = ?
                        WHERE uuid = ?
                    ");
                    
                    $updateResult = $updateStmt->execute([
                        $promptXml,                      // ai_system field (for XML prompt)
                        $data['description'] ?? null,    // ai_prompt field (for description)
                        $data['uuid']                    // audit UUID
                    ]);
                    
                    if (!$updateResult) {
                        throw new Exception("Failed to update audit with the generated prompt");
                    }
                    
                    error_log("Successfully updated audit {$data['uuid']} with the generated prompt");
                    
                    // Return the successful response with the prompt
                    $responseData = [
                        'success' => true,
                        'message' => 'Prompt generated and saved successfully',
                        'data' => [
                            'prompt' => $promptXml,
                            'uuid' => $data['uuid']
                        ]
                    ];
                    
                    // Add warning if this was a fallback prompt
                    if (!$result['success'] && isset($result['error'])) {
                        $responseData['data']['warning'] = $result['error'];
                        $responseData['message'] = 'Generated fallback prompt (Claude generation failed)';
                    }
                    
                    echo json_encode($responseData);
                } catch (Exception $dbException) {
                    error_log("Database error saving prompt: " . $dbException->getMessage());
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to save the prompt to the database',
                        'message' => $dbException->getMessage()
                    ]);
                }
            } else {
                // Only return error if both main and fallback failed
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $result['error']
                ]);
            }
        } catch (Exception $e) {
            error_log("Error in AuditController@prompt: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}