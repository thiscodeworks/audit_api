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
            echo json_encode(['chats' => $chats]);
        } catch (Exception $e) {
            error_log("Error in ChatController@list: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function get($uuid) {
        try {
            $chat = new Chat();
            $chatData = $chat->getByUuid($uuid);
            
            if (!$chatData) {
                http_response_code(404);
                echo json_encode(['error' => 'Chat not found']);
                return;
            }

            echo json_encode($chatData);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
}