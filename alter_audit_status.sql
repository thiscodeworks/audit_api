-- Modify the existing status enum to include 'requested' value
ALTER TABLE `audits` 
MODIFY COLUMN `status` enum('active','inprogress','archive','analyze','requested') NOT NULL DEFAULT 'active';

-- Update existing NULL status values to 'active'
UPDATE `audits` SET `status` = 'active' WHERE `status`