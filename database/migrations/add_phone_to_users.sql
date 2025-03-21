-- Add phone column to users table
ALTER TABLE users ADD COLUMN phone varchar(50) DEFAULT NULL AFTER position; 