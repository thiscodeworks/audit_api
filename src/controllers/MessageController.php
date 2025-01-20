<?php

require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/Chat.php';
require_once __DIR__ . '/../models/Audit.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/AnthropicService.php';
require_once __DIR__ . '/../services/PusherService.php';

class MessageController {
    public function __construct() {
        $this->message = new Message();
        $this->chat = new Chat();
        $this->user = new User();
        $this->pusherService = new PusherService();
        $this->anthropic = new AnthropicService($this->pusherService);
    }

    public function send($params) {
        try {
            $chatUuid = $params['uuid'];
            $content = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($content['message'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Message is required']);
                return;
            }

            $chat = new Chat();
            $chatData = $chat->getByUuid($chatUuid);
            
            if (!$chatData) {
                http_response_code(404);
                echo json_encode(['error' => 'Chat not found']);
                return;
            }

            $audit = new Audit();
            $auditData = $audit->getByUuid($chatData['audit_uuid']);
            
            if (!$auditData) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found']);
                return;
            }

            // Save user message
            $message = new Message();
            $isHidden = isset($content['hidden']) && $content['hidden'] === true;
            $userMessageUuid = $message->create($chatUuid, $content['message'], 'user', $isHidden);

            // Get chat history
            $messages = $message->getChatHistory($chatUuid);

            // Initialize services
            $pusherService = new PusherService();
            $anthropic = new AnthropicService($pusherService);

            // Initialize userData as null
            $userData = null;
            
            // Modify system prompt if user is associated with the chat
            $systemPrompt = $auditData['ai_system'];
            if (isset($chatData['user']) && $chatData['user']) {
                $userData = $this->user->getById($chatData['user']);
                if ($userData && isset($userData['name']) && isset($userData['position'])) {
                    // Insert user info right after the chatbot opening tag
                    $userInfo = "\n    <user_info>\n        <name>{$userData['name']}</name>\n        <position>{$userData['position']}</position>\n    </user_info>";
                    $systemPrompt = preg_replace('/<chatbot([^>]*)>/', "<chatbot$1>{$userInfo}", $systemPrompt);
                }
            }

            // Start streaming response
            $anthropic->getResponse($messages, $systemPrompt, $chatUuid);

            $response = [
                'status' => 'streaming',
                'user_message_uuid' => $userMessageUuid,
                'chat_channel' => 'chat-' . $chatUuid
            ];

            // Only include user data if it exists
            if ($userData) {
                $response['user'] = $userData;
            }

            echo json_encode($response);

        } catch (Exception $e) {
            error_log("Error in MessageController: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
} 