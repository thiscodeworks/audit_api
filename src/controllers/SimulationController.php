<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Chat.php';
require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/Audit.php';
require_once __DIR__ . '/../services/AnthropicService.php';
require_once __DIR__ . '/../config/database.php';

class SimulationController {
    private $user;
    private $chat;
    private $message;
    private $audit;
    private $anthropic;
    private $db;

    public function __construct() {
        $this->user = new User();
        $this->chat = new Chat();
        $this->message = new Message();
        $this->audit = new Audit();
        $this->anthropic = new AnthropicService();
        $this->db = Database::getInstance();
    }

    public function employeeSimulate($params) {
        try {
            $auditUuid = $params['uuid'];
            $payload = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($payload)) {
                throw new Exception('Invalid payload format');
            }

            $name = $payload['name'] ?? '';
            $email = $payload['email'] ?? '';
            $position = $payload['position'] ?? '';
            $chatContext = $payload['chat_context'] ?? '';

            if (!$auditUuid) {
                throw new Exception('Audit UUID is required');
            }

            if (empty($name)) {
                throw new Exception('Employee name is required');
            }

            if (empty($email)) {
                throw new Exception('Employee email is required');
            }

            if (empty($position)) {
                throw new Exception('Employee position is required');
            }

            if (empty($chatContext)) {
                throw new Exception('Chat context is required');
            }

            // Verify audit exists
            $auditData = $this->audit->getByUuid($auditUuid);
            if (!$auditData) {
                throw new Exception('Audit not found');
            }

            // Generate conversation with Claude
            $systemPrompt = $auditData['ai_system'];
            $messages = $this->generateConversation($systemPrompt, $chatContext);
            echo "Generated Conversation:\n";
            echo json_encode($messages, JSON_PRETTY_PRINT) . "\n\n";

            // Create user
            $userId = $this->user->create([
                'name' => $name,
                'email' => $email,
                'position' => $position,
                'username' => explode('@', $email)[0],
                'password' => null,
                'organizations' => [$auditData['organization']],
                'permission' => 'user'
            ]);

            // Generate a random 6-digit access code
            $accessCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

            // Create users_audit record
            $stmt = $this->db->prepare("
                INSERT INTO users_audit (user, audit, code, view, invite, push, blocked)
                VALUES (?, ?, ?, 1, 1, 1, 0)
            ");
            $stmt->execute([$userId, $auditData['id'], $accessCode]);

            // Create chat with timestamp from yesterday
            $chatUuid = $this->chat->create($auditUuid, $userId);
            
            // Set chat creation time to yesterday
            $stmt = $this->db->prepare("UPDATE chats SET created_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE uuid = ?");
            $stmt->execute([$chatUuid]);

            // Save messages with simulated timestamps
            $baseTime = strtotime('-1 day'); // Start from yesterday
            $messageInterval = 60; // 1 minute between messages
            
            foreach ($messages as $index => $msg) {
                // Calculate timestamp for this message with random seconds
                $randomSeconds = rand(0, 59);
                $messageTime = date('Y-m-d H:i:s', $baseTime + ($index * $messageInterval) + $randomSeconds);
                
                // Create message with specific timestamp
                $stmt = $this->db->prepare("
                    INSERT INTO messages (chat_uuid, content, role, created_at)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$chatUuid, $msg['content'], $msg['role'], $messageTime]);
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $userId,
                        'name' => $name,
                        'email' => $email,
                        'position' => $position,
                        'context' => $chatContext
                    ],
                    'chat' => [
                        'uuid' => $chatUuid
                    ],
                    'messages' => count($messages)
                ]
            ]);

        } catch (Exception $e) {
            error_log("Error in SimulationController: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ]);
        }
    }

    private function generateUserDetails($context) {
        $prompt = "Based on the following user context, generate realistic user details in JSON format. The user is: " . $context . "\n\nGenerate a first name, last name, email prefix (based on the name), and position that would be appropriate for this context. Return the data in this format:\n{\n  \"first_name\": \"...\",\n  \"last_name\": \"...\",\n  \"email_prefix\": \"...\",\n  \"position\": \"...\"\n}";

        $data = [
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 4096,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'anthropic-version: 2023-06-01',
            'x-api-key: ' . getenv('ANTHROPIC_API_KEY')
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Failed to generate user details');
        }

        $responseData = json_decode($response, true);
        $content = $responseData['content'][0]['text'];
        
        // Extract JSON from the response
        preg_match('/\{.*\}/s', $content, $matches);
        if (empty($matches)) {
            throw new Exception('Failed to parse user details from response');
        }

        return json_decode($matches[0], true);
    }

    private function generateConversation($systemPrompt, $userContext) {
        $messageCount = rand(8, 15);
        $prompt = "Generate a realistic customer service conversation with $messageCount messages (alternating between user and assistant) in JSON format. The conversation should be related to the following system prompt: " . $systemPrompt . "\n\nAdditional context about the user: " . $userContext . "\n\nReturn the conversation as an array of objects with 'role' and 'content' fields. The conversation should reflect the user's emotional state and situation.";

        $data = [
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 4096,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'anthropic-version: 2023-06-01',
            'x-api-key: ' . getenv('ANTHROPIC_API_KEY')
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Failed to generate conversation');
        }

        $responseData = json_decode($response, true);
        $content = $responseData['content'][0]['text'];
        
        // Extract JSON from the response
        preg_match('/\[.*\]/s', $content, $matches);
        if (empty($matches)) {
            throw new Exception('Failed to parse conversation from response');
        }

        return json_decode($matches[0], true);
    }

    public function simulate($params) {
        try {
            $auditUuid = $params['uuid'];
            $payload = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($payload)) {
                throw new Exception('Invalid payload format');
            }

            $structure = $payload['structure'] ?? '';
            $employeeCount = $payload['employee_count'] ?? 0;
            $issues = $payload['issues'] ?? [];
            $emailDomain = $payload['email_domain'] ?? '@example.com';

            if (!$auditUuid) {
                throw new Exception('Audit UUID is required');
            }

            if (empty($structure)) {
                throw new Exception('Company structure is required');
            }

            if ($employeeCount <= 0) {
                throw new Exception('Employee count must be greater than 0');
            }

            // Verify audit exists
            $auditData = $this->audit->getByUuid($auditUuid);
            if (!$auditData) {
                throw new Exception('Audit not found');
            }

            // Generate employee list with Claude
            $employeePrompt = "Based on the following company structure and issues, generate a list of $employeeCount employees with their roles and chat contexts. The company structure is: $structure. The main issues to reflect in conversations are: " . implode(', ', $issues) . ". 

IMPORTANT: Generate everything in Czech language:
- Use Czech first and last names
- Write all positions in Czech
- Write the chat_context in Czech, reflecting typical Czech workplace communication style and concerns
- For each employee, generate an email address using their first and last name in the format: firstname.lastname@domain (use the provided email domain: $emailDomain)

For each employee, provide: first_name, last_name, position, email, and a chat_context that reflects their role and the company issues. Return the data in JSON format as an array of objects.";

            $data = [
                'model' => 'claude-3-5-haiku-20241022',
                'max_tokens' => 4096,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $employeePrompt
                    ]
                ],
                'temperature' => 0.7
            ];

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'anthropic-version: 2023-06-01',
                'x-api-key: ' . getenv('ANTHROPIC_API_KEY')
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception('Failed to generate employee list');
            }

            $responseData = json_decode($response, true);
            $content = $responseData['content'][0]['text'];
            
            // Extract JSON from the response
            preg_match('/\[.*\]/s', $content, $matches);
            if (empty($matches)) {
                throw new Exception('Failed to parse employee list from response');
            }

            $employees = json_decode($matches[0], true);

            echo json_encode([
                'success' => true,
                'data' => [
                    'total_employees' => count($employees),
                    'employees' => $employees
                ]
            ]);

        } catch (Exception $e) {
            error_log("Error in SimulationController: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ]);
        }
    }
} 