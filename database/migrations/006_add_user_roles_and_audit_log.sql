-- Migration 006: Add User Roles and Audit Logging System
-- Implements role-based access control (admin vs read-only)
-- Creates comprehensive audit log for all data modifications

-- Create users table if it doesn't exist
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL COMMENT 'Bcrypt hash',
    role ENUM('admin', 'read_only') DEFAULT 'read_only' COMMENT 'User access level',
    active TINYINT(1) DEFAULT 1 COMMENT 'User account status',
    last_login DATETIME NULL COMMENT 'Last login timestamp',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='System users with role-based access';

-- If table already existed, add missing columns conditionally
SET @dbname = DATABASE();
SET @tablename = "users";

-- Add role column if it doesn't exist (for tables created before this migration)
SET @columnname = "role";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE users ADD COLUMN role ENUM('admin', 'read_only') DEFAULT 'read_only' COMMENT 'User access level';",
  "SELECT 1;"
));
PREPARE addRoleColumn FROM @preparedStatement;
EXECUTE addRoleColumn;
DEALLOCATE PREPARE addRoleColumn;

-- Add active column if it doesn't exist
SET @columnname = "active";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE users ADD COLUMN active TINYINT(1) DEFAULT 1 COMMENT 'User account status';",
  "SELECT 1;"
));
PREPARE addActiveColumn FROM @preparedStatement;
EXECUTE addActiveColumn;
DEALLOCATE PREPARE addActiveColumn;

-- Add last_login column if it doesn't exist
SET @columnname = "last_login";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE users ADD COLUMN last_login DATETIME NULL COMMENT 'Last login timestamp';",
  "SELECT 1;"
));
PREPARE addLastLoginColumn FROM @preparedStatement;
EXECUTE addLastLoginColumn;
DEALLOCATE PREPARE addLastLoginColumn;

-- Create audit_log table (if it doesn't exist)
CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL COMMENT 'Stored for reference even if user deleted',
    action ENUM('create', 'update', 'delete', 'login', 'logout', 'import') NOT NULL,
    table_name VARCHAR(100) NULL COMMENT 'Affected database table',
    record_id INT NULL COMMENT 'ID of affected record',
    record_description VARCHAR(255) NULL COMMENT 'Human-readable description of record',
    old_values TEXT NULL COMMENT 'JSON of old values (for updates/deletes)',
    new_values TEXT NULL COMMENT 'JSON of new values (for creates/updates)',
    ip_address VARCHAR(45) NULL COMMENT 'Client IP address (supports IPv6)',
    user_agent VARCHAR(255) NULL COMMENT 'Browser user agent',
    notes TEXT NULL COMMENT 'Additional context or notes',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_table_record (table_name, record_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Audit trail for all system changes';

-- Create user_sessions table for tracking active sessions (if it doesn't exist)
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_session_token (session_token),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Active user sessions';

-- Set existing admin user to admin role (assumes first user is admin)
-- Only if users table has records
SET @updateAdminRole = (SELECT IF(
  (SELECT COUNT(*) FROM users) > 0,
  "UPDATE users SET role = 'admin' WHERE id = (SELECT MIN(id) FROM (SELECT id FROM users) AS temp) LIMIT 1;",
  "SELECT 1;"
));
PREPARE setAdminRole FROM @updateAdminRole;
EXECUTE setAdminRole;
DEALLOCATE PREPARE setAdminRole;

/*
User Roles:
- 'admin': Full read/write access, can modify all data, manage users, view audit logs
- 'read_only': Can view all data but cannot create, update, or delete records

Audit Log Usage:
- All write operations (create, update, delete) should log to audit_log
- Include user_id, action type, affected table/record, old/new values (as JSON)
- IP address and user agent help identify session/device
- created_at automatically tracks when action occurred

Security Notes:
- Audit log entries should never be deleted (except by DB admin manually)
- Consider archiving old audit logs periodically (older than 1-2 years)
- Foreign key on user_id uses CASCADE to preserve referential integrity
*/
