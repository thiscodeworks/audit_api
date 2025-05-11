-- phpMyAdmin SQL Dump
-- version 5.2.1deb1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 11, 2025 at 12:50 PM
-- Server version: 10.11.6-MariaDB-0+deb12u1
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `audit`
--

-- --------------------------------------------------------

--
-- Table structure for table `analyze`
--

CREATE TABLE `analyze` (
  `id` int(11) NOT NULL,
  `chat` int(11) NOT NULL,
  `sentiment` int(11) NOT NULL,
  `goal_fulfill` int(11) DEFAULT 0,
  `summary` longtext NOT NULL,
  `keyfindings` longtext NOT NULL,
  `tags` text DEFAULT NULL COMMENT 'Comma-separated list of tags for the chat',
  `topics` text DEFAULT NULL COMMENT 'Comma-separated list of topics discussed',
  `customer_satisfaction` int(11) DEFAULT NULL COMMENT 'Customer satisfaction score (0-100)',
  `agent_effectiveness` int(11) DEFAULT NULL COMMENT 'Agent effectiveness score (0-100)',
  `improvement_suggestions` text DEFAULT NULL COMMENT 'Bulleted list of improvement suggestions',
  `conversation_quality` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON object with conversation quality metrics' CHECK (json_valid(`conversation_quality`)),
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audits`
--

CREATE TABLE `audits` (
  `id` int(11) NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `audit_name` varchar(255) NOT NULL,
  `employee_count_limit` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `ai_system` text DEFAULT NULL,
  `ai_prompt` text DEFAULT NULL,
  `audit_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`audit_data`)),
  `type` enum('public','assign') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `organization` int(11) NOT NULL,
  `status` enum('active','inprogress','archive','analyze','requested') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `form_name` tinyint(4) DEFAULT 0,
  `form_position` tinyint(4) DEFAULT 0,
  `form_email` tinyint(4) DEFAULT 0,
  `form_phone` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_findings`
--

CREATE TABLE `audit_findings` (
  `id` int(11) NOT NULL,
  `slide_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `evidence` text DEFAULT NULL,
  `is_solution` tinyint(1) NOT NULL DEFAULT 0,
  `recommendation` text NOT NULL,
  `severity` enum('low','medium','high') DEFAULT NULL,
  `order_index` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_finding_examples`
--

CREATE TABLE `audit_finding_examples` (
  `id` int(11) NOT NULL,
  `finding_id` int(11) NOT NULL,
  `chat_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_slides`
--

CREATE TABLE `audit_slides` (
  `id` int(11) NOT NULL,
  `audit_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `order_index` int(11) NOT NULL DEFAULT 0,
  `is_home` tinyint(1) NOT NULL DEFAULT 0,
  `html_content` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_tags_cloud`
--

CREATE TABLE `audit_tags_cloud` (
  `id` int(11) NOT NULL,
  `audit_id` int(11) NOT NULL,
  `tag` varchar(255) NOT NULL,
  `weight` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chats`
--

CREATE TABLE `chats` (
  `id` int(11) NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `audit_uuid` varchar(36) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `state` enum('open','finished') NOT NULL DEFAULT 'open',
  `user` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `magic_link_tokens`
--

CREATE TABLE `magic_link_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` bigint(20) NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `chat_uuid` varchar(36) NOT NULL,
  `content` text NOT NULL,
  `role` enum('user','assistant') NOT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `organizations`
--

CREATE TABLE `organizations` (
  `id` int(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `about` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `auth_token` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_audit`
--

CREATE TABLE `users_audit` (
  `id` int(11) NOT NULL,
  `user` int(11) NOT NULL,
  `audit` int(11) NOT NULL,
  `code` varchar(6) NOT NULL,
  `view` tinyint(2) NOT NULL DEFAULT 0,
  `invite` tinyint(2) NOT NULL DEFAULT 0,
  `push` tinyint(2) NOT NULL DEFAULT 0,
  `blocked` tinyint(2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_organization`
--

CREATE TABLE `users_organization` (
  `id` int(11) NOT NULL,
  `user` int(11) NOT NULL,
  `organization` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_permission`
--

CREATE TABLE `users_permission` (
  `id` int(11) NOT NULL,
  `user` int(11) NOT NULL,
  `permission` enum('admin','adminorg','user') NOT NULL DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `analyze`
--
ALTER TABLE `analyze`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chat` (`chat`);

--
-- Indexes for table `audits`
--
ALTER TABLE `audits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD KEY `idx_uuid` (`uuid`),
  ADD KEY `idx_company` (`company_name`);

--
-- Indexes for table `audit_findings`
--
ALTER TABLE `audit_findings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `slide_id` (`slide_id`);

--
-- Indexes for table `audit_finding_examples`
--
ALTER TABLE `audit_finding_examples`
  ADD PRIMARY KEY (`id`),
  ADD KEY `finding_id` (`finding_id`),
  ADD KEY `chat_id` (`chat_id`);

--
-- Indexes for table `audit_slides`
--
ALTER TABLE `audit_slides`
  ADD PRIMARY KEY (`id`),
  ADD KEY `audit_id` (`audit_id`);

--
-- Indexes for table `audit_tags_cloud`
--
ALTER TABLE `audit_tags_cloud`
  ADD PRIMARY KEY (`id`),
  ADD KEY `audit_id` (`audit_id`);

--
-- Indexes for table `chats`
--
ALTER TABLE `chats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD KEY `audit_uuid` (`audit_uuid`),
  ADD KEY `user` (`user`);

--
-- Indexes for table `magic_link_tokens`
--
ALTER TABLE `magic_link_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD KEY `chat_uuid` (`chat_uuid`);

--
-- Indexes for table `organizations`
--
ALTER TABLE `organizations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `users_audit`
--
ALTER TABLE `users_audit`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `user` (`user`),
  ADD KEY `audit` (`audit`);

--
-- Indexes for table `users_organization`
--
ALTER TABLE `users_organization`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user` (`user`),
  ADD KEY `organization` (`organization`);

--
-- Indexes for table `users_permission`
--
ALTER TABLE `users_permission`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user` (`user`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `analyze`
--
ALTER TABLE `analyze`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audits`
--
ALTER TABLE `audits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_findings`
--
ALTER TABLE `audit_findings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_finding_examples`
--
ALTER TABLE `audit_finding_examples`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_slides`
--
ALTER TABLE `audit_slides`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_tags_cloud`
--
ALTER TABLE `audit_tags_cloud`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chats`
--
ALTER TABLE `chats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `magic_link_tokens`
--
ALTER TABLE `magic_link_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `organizations`
--
ALTER TABLE `organizations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users_audit`
--
ALTER TABLE `users_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users_organization`
--
ALTER TABLE `users_organization`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users_permission`
--
ALTER TABLE `users_permission`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `analyze`
--
ALTER TABLE `analyze`
  ADD CONSTRAINT `analyze_ibfk_1` FOREIGN KEY (`chat`) REFERENCES `chats` (`id`);

--
-- Constraints for table `audit_findings`
--
ALTER TABLE `audit_findings`
  ADD CONSTRAINT `audit_findings_ibfk_1` FOREIGN KEY (`slide_id`) REFERENCES `audit_slides` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_finding_examples`
--
ALTER TABLE `audit_finding_examples`
  ADD CONSTRAINT `audit_finding_examples_ibfk_1` FOREIGN KEY (`finding_id`) REFERENCES `audit_findings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `audit_finding_examples_ibfk_2` FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`);

--
-- Constraints for table `audit_slides`
--
ALTER TABLE `audit_slides`
  ADD CONSTRAINT `audit_slides_ibfk_1` FOREIGN KEY (`audit_id`) REFERENCES `audits` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_tags_cloud`
--
ALTER TABLE `audit_tags_cloud`
  ADD CONSTRAINT `audit_tags_cloud_ibfk_1` FOREIGN KEY (`audit_id`) REFERENCES `audits` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chats`
--
ALTER TABLE `chats`
  ADD CONSTRAINT `chats_ibfk_1` FOREIGN KEY (`audit_uuid`) REFERENCES `audits` (`uuid`),
  ADD CONSTRAINT `chats_ibfk_2` FOREIGN KEY (`user`) REFERENCES `users` (`id`);

--
-- Constraints for table `magic_link_tokens`
--
ALTER TABLE `magic_link_tokens`
  ADD CONSTRAINT `magic_link_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`chat_uuid`) REFERENCES `chats` (`uuid`);

--
-- Constraints for table `users_audit`
--
ALTER TABLE `users_audit`
  ADD CONSTRAINT `users_audit_ibfk_1` FOREIGN KEY (`user`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `users_audit_ibfk_2` FOREIGN KEY (`audit`) REFERENCES `audits` (`id`);

--
-- Constraints for table `users_organization`
--
ALTER TABLE `users_organization`
  ADD CONSTRAINT `users_organization_ibfk_1` FOREIGN KEY (`user`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `users_organization_ibfk_2` FOREIGN KEY (`organization`) REFERENCES `organizations` (`id`);

--
-- Constraints for table `users_permission`
--
ALTER TABLE `users_permission`
  ADD CONSTRAINT `users_permission_ibfk_1` FOREIGN KEY (`user`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
