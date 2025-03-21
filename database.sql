-- Adminer 4.8.1 MySQL 5.5.5-10.6.12-MariaDB-1:10.6.12+maria~ubu2004 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `analyze`;
CREATE TABLE `analyze` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat` int(11) NOT NULL,
  `sentiment` int(11) NOT NULL,
  `summary` longtext NOT NULL,
  `keyfindings` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `chat` (`chat`),
  CONSTRAINT `analyze_ibfk_1` FOREIGN KEY (`chat`) REFERENCES `chats` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `audits`;
CREATE TABLE `audits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `employee_count_limit` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `ai_system` text DEFAULT NULL,
  `ai_prompt` text DEFAULT NULL,
  `audit_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`audit_data`)),
  `type` enum('public','assign') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `organization` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_uuid` (`uuid`),
  KEY `idx_company` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `chats`;
CREATE TABLE `chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `audit_uuid` varchar(36) NOT NULL,
  `user` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `state` enum('open','finished') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `audit_uuid` (`audit_uuid`),
  KEY `user` (`user`),
  CONSTRAINT `chats_ibfk_1` FOREIGN KEY (`audit_uuid`) REFERENCES `audits` (`uuid`),
  CONSTRAINT `chats_ibfk_2` FOREIGN KEY (`user`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `chat_uuid` varchar(36) NOT NULL,
  `content` text NOT NULL,
  `role` enum('user','assistant') NOT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `chat_uuid` (`chat_uuid`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`chat_uuid`) REFERENCES `chats` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `organizations`;
CREATE TABLE `organizations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `auth_token` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `users_audit`;
CREATE TABLE `users_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user` int(11) NOT NULL,
  `audit` int(11) NOT NULL,
  `code` varchar(6) NOT NULL,
  `view` tinyint(2) NOT NULL DEFAULT 0,
  `invite` tinyint(2) NOT NULL DEFAULT 0,
  `push` tinyint(2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `user` (`user`),
  KEY `audit` (`audit`),
  CONSTRAINT `users_audit_ibfk_1` FOREIGN KEY (`user`) REFERENCES `users` (`id`),
  CONSTRAINT `users_audit_ibfk_2` FOREIGN KEY (`audit`) REFERENCES `audits` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `users_organization`;
CREATE TABLE `users_organization` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user` int(11) NOT NULL,
  `organization` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user` (`user`),
  KEY `organization` (`organization`),
  CONSTRAINT `users_organization_ibfk_1` FOREIGN KEY (`user`) REFERENCES `users` (`id`),
  CONSTRAINT `users_organization_ibfk_2` FOREIGN KEY (`organization`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `users_permission`;
CREATE TABLE `users_permission` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user` int(11) NOT NULL,
  `permission` enum('admin','adminorg','user') NOT NULL DEFAULT 'user',
  PRIMARY KEY (`id`),
  KEY `user` (`user`),
  CONSTRAINT `users_permission_ibfk_1` FOREIGN KEY (`user`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `audit_slides`;
CREATE TABLE `audit_slides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `audit_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `order_index` int(11) NOT NULL DEFAULT 0,
  `is_home` tinyint(1) NOT NULL DEFAULT 0,
  `html_content` text,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `audit_id` (`audit_id`),
  CONSTRAINT `audit_slides_ibfk_1` FOREIGN KEY (`audit_id`) REFERENCES `audits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audit_findings`;
CREATE TABLE `audit_findings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slide_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `recommendation` text NOT NULL,
  `severity` enum('low','medium','high') NOT NULL,
  `order_index` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `