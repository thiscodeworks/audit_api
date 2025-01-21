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

    public function create() {
        try {
            // Get JSON input
            $data = json_decode(file_get_contents('php://input'), true);

            // Debug input data
            error_log("Create user input data: " . json_encode($data));

            // Validate required fields
            $requiredFields = ['name', 'email', 'organization', 'permission'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: {$field}"]);
                    return;
                }
            }

            // Create the user
            $userId = $this->user->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'position' => $data['position'] ?? null,
                'organization' => $data['organization'],
                'username' => $data['username'] ?? null,
                'password' => $data['password'] ?? null,
                'permission' => $data['permission']
            ]);

            // Get the created user
            $user = $this->user->getById($userId);

            echo json_encode([
                'message' => 'User created successfully',
                'data' => $user
            ]);
        } catch (Exception $e) {
            error_log("Error in UserController@create: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            http_response_code(500);
            // In development, return the actual error message
            echo json_encode(['error' => 'Internal server error', 'debug_message' => $e->getMessage()]);
        }
    }
} 