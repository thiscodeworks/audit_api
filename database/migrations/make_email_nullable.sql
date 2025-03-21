-- Make email field nullable in users table
ALTER TABLE users MODIFY COLUMN email varchar(255) NULL; 