ALTER TABLE `analyze` 
ADD COLUMN `topics` text DEFAULT NULL COMMENT 'Comma-separated list of topics discussed' AFTER `tags`,
ADD COLUMN `customer_satisfaction` int DEFAULT NULL COMMENT 'Customer satisfaction score (0-100)' AFTER `topics`,
ADD COLUMN `agent_effectiveness` int DEFAULT NULL COMMENT 'Agent effectiveness score (0-100)' AFTER `customer_satisfaction`,
ADD COLUMN `improvement_suggestions` text DEFAULT NULL COMMENT 'Bulleted list of improvement suggestions' AFTER `agent_effectiveness`,
ADD COLUMN `conversation_quality` json DEFAULT NULL COMMENT 'JSON object with conversation quality metrics' AFTER `improvement_suggestions`; 