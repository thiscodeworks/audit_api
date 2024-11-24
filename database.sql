SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `audits`;
CREATE TABLE `audits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `employee_count_limit` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `ai_system` varchar(255) DEFAULT NULL,
  `ai_prompt` text DEFAULT NULL,
  `audit_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`audit_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_uuid` (`uuid`),
  KEY `idx_company` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `audits` (`id`, `uuid`, `company_name`, `employee_count_limit`, `description`, `ai_system`, `ai_prompt`, `audit_data`, `created_at`, `updated_at`) VALUES
(1,	'58dba727-2653-41ee-baba-0aaf4b0bfaa6',	'Žaluzieee.cz',	50,	NULL,	NULL,	NULL,	NULL,	'2024-11-24 20:20:24',	'2024-11-24 20:20:24');

DROP TABLE IF EXISTS `chats`;
CREATE TABLE `chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `audit_uuid` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `audit_uuid` (`audit_uuid`),
  CONSTRAINT `chats_ibfk_1` FOREIGN KEY (`audit_uuid`) REFERENCES `audits` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
    `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
    `uuid` VARCHAR(36) NOT NULL UNIQUE,
    `chat_uuid` VARCHAR(36) NOT NULL,
    `content` TEXT NOT NULL,
    `role` ENUM('user', 'assistant') NOT NULL,
    `is_hidden` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` TIMESTAMP NOT NULL,
    FOREIGN KEY (`chat_uuid`) REFERENCES `chats`(`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;