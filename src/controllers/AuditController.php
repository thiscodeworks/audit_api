<?php
require_once __DIR__ . '/../models/Audit.php';
require_once __DIR__ . '/../models/Chat.php';

class AuditController {
    private $auditModel;

    public function __construct() {
        $this->auditModel = new Audit();
    }

    public function get($params) {
        $uuid = $params['uuid'];
        $audit = $this->auditModel->getByUuid($uuid);

        if (!$audit) {
            http_response_code(404);
            echo json_encode(['error' => 'Audit not found']);
            return;
        }

        // Handle audit_data JSON decoding
        $auditData = null;
        if (!empty($audit['audit_data'])) {
            $auditData = json_decode($audit['audit_data']);
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'uuid' => $audit['uuid'],
                'company_name' => $audit['company_name'],
                'employee_count_limit' => $audit['employee_count_limit'],
                'description' => $audit['description'],
                'ai_system' => $audit['ai_system'],
                'ai_prompt' => $audit['ai_prompt'],
                'audit_data' => $auditData,
                'created_at' => $audit['created_at'],
                'updated_at' => $audit['updated_at']
            ]
        ]);
    }

    public function getAudit($uuid) {
        $audit = new Audit();
        $result = $audit->getByUuid($uuid);
        
        if ($result) {
            // Ensure audit_data is a valid JSON string or null
            $result['audit_data'] = !empty($result['audit_data']) ? 
                json_decode($result['audit_data'], true) : 
                null;
                
            return [
                'status' => 'success',
                'data' => $result
            ];
        }

        return [
            'status' => 'error',
            'message' => 'Audit not found'
        ];
    }

    public function start($params) {
        $uuid = $params['uuid'];
        $audit = $this->auditModel->getByUuid($uuid);

        if (!$audit) {
            http_response_code(404);
            echo json_encode(['error' => 'Audit not found']);
            return;
        }

        $chat = new Chat();
        $chatUuid = $chat->create($uuid);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'uuid' => $chatUuid
            ]
        ]);
    }
} 