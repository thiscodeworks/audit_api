<?php

require_once __DIR__ . '/../models/User.php';

class UserController {
    private $user;

    public function __construct() {
        $this->user = new User();
    }

    public function list() {
        try {
            $users = $this->user->getAll();
            
            // Format the response
            $formattedUsers = array_map(function($user) {
                return [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'name' => $user['name'],
                    'company' => $user['company'],
                    'organization' => $user['organization_name'],
                    'permission' => $user['permission'] ?? 'user',
                    'stats' => [
                        'total_chats' => (int)$user['total_chats'],
                        'total_messages' => (int)$user['total_messages']
                    ],
                    'created_at' => $user['created_at'],
                    'updated_at' => $user['updated_at']
                ];
            }, $users);

            echo json_encode([
                'data' => $formattedUsers,
                'total' => count($formattedUsers)
            ]);
        } catch (Exception $e) {
            error_log("Error in UserController@list: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
} 