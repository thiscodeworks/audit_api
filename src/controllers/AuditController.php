<?php

require_once __DIR__ . '/../models/Audit.php';
require_once __DIR__ . '/../models/Chat.php';

class AuditController {
    private $audit;
    private $chat;

    public function __construct() {
        $this->audit = new Audit();
        $this->chat = new Chat();
    }

    public function list() {
        try {
            $audits = $this->audit->getAll();
            echo json_encode(['audits' => $audits]);
        } catch (Exception $e) {
            error_log("Error in AuditController@list: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function create() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['company_name']) || !isset($data['employee_count_limit'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Company name and employee count limit are required']);
                return;
            }

            $auditData = [
                'company_name' => $data['company_name'],
                'employee_count_limit' => $data['employee_count_limit'],
                'description' => $data['description'] ?? null,
                'ai_system' => $data['ai_system'] ?? null,
                'ai_prompt' => $data['ai_prompt'] ?? null,
                'audit_data' => isset($data['audit_data']) ? json_encode($data['audit_data']) : null
            ];

            $uuid = $this->audit->create($auditData);
            echo json_encode(['uuid' => $uuid]);
        } catch (Exception $e) {
            error_log("Error in AuditController@create: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function get($uuid) {
        try {
            $uuid = is_array($uuid) ? $uuid['uuid'] : $uuid;
            $audit = $this->audit->getByUuid($uuid);
            
            if (!$audit) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found']);
                return;
            }

            echo json_encode($audit);
        } catch (Exception $e) {
            error_log("Error in AuditController@get: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function delete($uuid) {
        try {
            $uuid = is_array($uuid) ? $uuid['uuid'] : $uuid;
            
            if (!$this->audit->exists($uuid)) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found']);
                return;
            }

            $this->audit->delete($uuid);
            echo json_encode(['message' => 'Audit deleted successfully']);
        } catch (Exception $e) {
            error_log("Error in AuditController@delete: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function edit($uuid) {
        try {
            $uuid = is_array($uuid) ? $uuid['uuid'] : $uuid;
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$this->audit->exists($uuid)) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found']);
                return;
            }

            $updateData = [
                'company_name' => $data['company_name'] ?? null,
                'employee_count_limit' => $data['employee_count_limit'] ?? null,
                'description' => $data['description'] ?? null,
                'ai_system' => $data['ai_system'] ?? null,
                'ai_prompt' => $data['ai_prompt'] ?? null,
                'audit_data' => isset($data['audit_data']) ? json_encode($data['audit_data']) : null
            ];

            // Remove null values to only update provided fields
            $updateData = array_filter($updateData, function($value) {
                return $value !== null;
            });

            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                return;
            }

            $this->audit->update($uuid, $updateData);
            echo json_encode(['message' => 'Audit updated successfully']);
        } catch (Exception $e) {
            error_log("Error in AuditController@edit: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    public function start($uuid) {
        try {
            $uuid = is_array($uuid) ? $uuid['uuid'] : $uuid;
            
            if (!$this->audit->exists($uuid)) {
                http_response_code(404);
                echo json_encode(['error' => 'Audit not found']);
                return;
            }

            $chatUuid = $this->chat->create($uuid);
            echo json_encode(['uuid' => $chatUuid]);
        } catch (Exception $e) {
            error_log("Error in AuditController@start: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
}