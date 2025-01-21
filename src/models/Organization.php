<?php

require_once __DIR__ . '/../config/database.php';

class Organization {
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