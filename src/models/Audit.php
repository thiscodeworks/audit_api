<?php
require_once __DIR__ . '/../config/Database.php';

class Audit {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getByUuid($uuid) {
        $stmt = $this->db->prepare("
            SELECT 
                uuid,
                company_name,
                employee_count_limit,
                description,
                ai_system,
                ai_prompt,
                audit_data,
                created_at,
                updated_at
            FROM audits 
            WHERE uuid = ?
        ");
        $stmt->execute([$uuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
} 