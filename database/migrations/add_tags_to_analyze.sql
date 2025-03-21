ALTER TABLE `analyze` 
ADD COLUMN `tags` text DEFAULT NULL COMMENT 'Comma-separated list of tags for the chat' 
AFTER `keyfindings`; 