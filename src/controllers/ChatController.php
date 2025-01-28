<?php

require_once __DIR__ . '/../models/Chat.php';

class ChatController {
    private $chat;

    public function __construct() {
        $this->chat = new Chat();
    }

    public function list() {
        try {
            $chats = $this->chat->getAll();
            echo json_encode(['data' => $chats]);
        } catch (Exception $e) {
            error_log("Error in ChatController@list: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function get($params) {
        try {
            $uuid = $params['uuid'];
            error_log("Attempting to get chat with UUID: " . $uuid);
            
            $chatData = $this->chat->getByUuid($uuid);
            error_log("Raw chat data: " . json_encode($chatData));
            
            if (!$chatData) {
                error_log("Chat not found for UUID: " . $uuid);
                http_response_code(404);
                echo json_encode(['error' => 'Chat not found']);
                return;
            }

            // Format the response
            $response = [
                "data" => [
                    "uuid" => $chatData['uuid'],
                    "audit_uuid" => $chatData['audit_uuid'],
                    "user" => $chatData['user'],
                    "username" => $chatData['username'],
                    "user_email" => $chatData['user_email'],
                    "company_name" => $chatData['company_name'],
                    "created_at" => $chatData['created_at'],
                    "updated_at" => $chatData['updated_at'],
                    "state" => $chatData['state']
                ]
            ];
            
            error_log("Sending response: " . json_encode($response));
            echo json_encode($response);
        } catch (Exception $e) {
            error_log("Error in ChatController@get: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'details' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}