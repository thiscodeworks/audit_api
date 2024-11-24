<?php
class Env {
    public static function load($path) {
        if (!file_exists($path)) {
            throw new Exception(".env file not found at: $path");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                
                // Remove quotes if they exist
                if (strpos($value, '"') === 0 || strpos($value, "'") === 0) {
                    $value = trim($value, '"\'');
                }
                
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    public static function get($key, $default = null) {
        return getenv($key) ?: $default;
    }

    public static function getAuthorizationHeader() {
        $headers = array_change_key_case(getallheaders());
        return isset($headers['authorization']) ? str_replace('Bearer ', '', $headers['authorization']) : null;
    }
} 