<?php

require_once __DIR__ . '/../models/Organization.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../utils/JWTHandler.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class OrganizationController {
    private $jwt;
    private $db;
    private $organization;

    public function __construct() {
        $this->jwt = new JWTHandler();
        $this->db = Database::getInstance();
        $this->organization = new Organization();
    }

    private function hasAdminPermission() {
        try {
            $userData = AuthMiddleware::getAuthenticatedUser();
            $userId = $userData->id;
            
            $stmt = $this->db->prepare("SELECT permission FROM users_permission WHERE user = ? AND permission IN ('admin', 'adminorg')");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function create() {
        if (!$this->hasAdminPermission()) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }

        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        
        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['error' => 'Organization name is required']);
            return;
        }

        try {
            $about = isset($_POST['about']) ? trim($_POST['about']) : null;
            $id = $this->organization->create($name, $about);
            
            // Handle logo upload if present
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../public/uploads/organizations/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileExtension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                $fileName = $id . '.' . $fileExtension;
                move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $fileName);
            }
            
            echo json_encode([
                'message' => 'Organization created successfully',
                'data' => [
                    'id' => $id, 
                    'name' => $name,
                    'about' => $about
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create organization: ' . $e->getMessage()]);
        }
    }

    private function parsePutFormData() {
        $putData = [];
        
        if (empty($_SERVER['CONTENT_TYPE'])) {
            return $putData;
        }

        if (preg_match('/boundary=(.*)$/i', $_SERVER['CONTENT_TYPE'], $matches)) {
            $boundary = $matches[1];
            $raw = file_get_contents('php://input');
            
            // Split content into parts
            $parts = explode('--' . $boundary, $raw);
            
            foreach ($parts as $part) {
                // Skip empty parts and closing boundary
                if (empty($part) || $part == '--') {
                    continue;
                }
                
                // Parse the part
                if (preg_match('/Content-Disposition: form-data; name="([^"]+)"/i', $part, $matches)) {
                    $name = $matches[1];
                    // Get the value (everything after the double newline)
                    list(, $value) = explode("\r\n\r\n", $part, 2);
                    // Remove the trailing newline if exists
                    $value = rtrim($value, "\r\n");
                    $putData[$name] = $value;
                }
            }
        }
        
        return $putData;
    }

    public function update($params) {
        if (!$this->hasAdminPermission()) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }

        // Parse PUT form data
        $putData = $this->parsePutFormData();
        $name = isset($putData['name']) ? trim($putData['name']) : '';
        
        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['error' => 'Organization name is required']);
            return;
        }

        try {
            $about = isset($putData['about']) ? trim($putData['about']) : null;
            
            if ($this->organization->update($params['id'], $name, $about)) {
                // Handle logo upload if present
                if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../../public/uploads/organizations/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $fileExtension = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                    $fileName = $params['id'] . '.' . $fileExtension;
                    move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $fileName);
                }

                echo json_encode([
                    'message' => 'Organization updated successfully',
                    'data' => [
                        'id' => $params['id'], 
                        'name' => $name,
                        'about' => $about
                    ]
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Organization not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update organization: ' . $e->getMessage()]);
        }
    }

    public function delete($id) {
        if (!$this->hasAdminPermission()) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            return;
        }

        try {
            if ($this->organization->delete($id)) {
                echo json_encode(['message' => 'Organization deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Organization not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete organization']);
        }
    }

    public function get($id) {
        try {
            $org = $this->organization->get($id);
            if ($org) {
                echo json_encode(['data' => $org]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Organization not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch organization']);
        }
    }

    public function list() {
        try {
            $organizations = $this->organization->getAll();
            echo json_encode(['data' => $organizations]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch organizations']);
        }
    }
} 