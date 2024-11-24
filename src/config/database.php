<?php
require_once __DIR__ . '/../utils/Env.php';

class Database {
    private static $instance = null;
    private $host;
    private $database_name;
    private $username;
    private $password;
    public $conn;

    private function __construct() {
        $this->host = Env::get('DB_HOST', 'localhost');
        $this->database_name = Env::get('DB_NAME', 'crm_db');
        $this->username = Env::get('DB_USER', 'root');
        $this->password = Env::get('DB_PASSWORD', '');
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->getConnection();
    }

    public function getConnection() {
        if ($this->conn === null) {
            try {
                $this->conn = new PDO(
                    "mysql:host=" . $this->host . ";dbname=" . $this->database_name,
                    $this->username,
                    $this->password
                );
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch(PDOException $e) {
                echo "Connection failed: " . $e->getMessage();
            }
        }
        return $this->conn;
    }
} 