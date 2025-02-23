<?php

require_once __DIR__ . '/../config/database.php';

class Organization {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function create($name, $about = null) {
        $stmt = $this->db->prepare("INSERT INTO organizations (name, about) VALUES (?, ?)");
        $stmt->execute([$name, $about]);
        return $this->db->lastInsertId();
    }

    public function update($id, $name, $about = null) {
        $stmt = $this->db->prepare("UPDATE organizations SET name = ?, about = ? WHERE id = ?");
        return $stmt->execute([$name, $about, $id]);
    }

    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM organizations WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function get($id) {
        $stmt = $this->db->prepare("SELECT * FROM organizations WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAll() {
        $stmt = $this->db->prepare("
            SELECT 
                o.*,
                COUNT(DISTINCT uo.user) as user_count,
                COUNT(DISTINCT c.id) as chat_count,
                COUNT(DISTINCT a.id) as audit_count
            FROM organizations o
            LEFT JOIN users_organization uo ON uo.organization = o.id
            LEFT JOIN audits a ON a.organization = o.id
            LEFT JOIN chats c ON c.audit_uuid = a.uuid
            GROUP BY o.id
            ORDER BY o.name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getForUser($userId) {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT o.* 
            FROM organizations o
            LEFT JOIN users_organization uo ON o.id = uo.organization
            WHERE uo.user = :userId
        ");
        
        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} 