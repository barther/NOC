# User Roles and Audit Logging System

## Overview

The NOC Scheduler now supports two user roles with comprehensive audit logging for all system modifications.

## User Roles

### Admin Role
- **Full Access:** Create, read, update, and delete all records
- **User Management:** Can manage other users (future feature)
- **Audit Log Access:** Can view complete audit trail
- **Settings Access:** Can modify system settings

### Read-Only Role
- **View Access:** Can view all schedules, dispatchers, desks, vacancies
- **No Modifications:** Cannot create, update, or delete any records
- **No Settings:** Cannot access or modify system configuration
- **Limited Audit:** Can only view their own activity (future feature)

## Authentication System

### Database-Backed Users
- Users are stored in the `users` table with hashed passwords
- Each user has a `role` field (`admin` or `read_only`)
- Users have `active` status (can be disabled)
- Last login time is tracked

### Session Management
- Sessions stored in `user_sessions` table
- 2-hour inactivity timeout
- 30-day persistent "remember me" option
- Session tokens are cryptographically secure (64 characters)

### First-Time Setup
- On first login with the default password, an admin user is auto-created
- Subsequent logins require username/password

## Audit Logging

### What Gets Logged

Every write operation is logged with the following information:

1. **User Information**
   - User ID
   - Username (stored for reference even if user deleted)

2. **Action Details**
   - Action type: `create`, `update`, `delete`, `login`, `logout`, `import`
   - Table name affected
   - Record ID affected
   - Human-readable record description

3. **Data Changes**
   - Old values (JSON) - for updates and deletes
   - New values (JSON) - for creates and updates

4. **Context Information**
   - IP address (supports IPv6)
   - User agent (browser info)
   - Timestamp (automatic)
   - Optional notes

### Logged Actions

- **create**: New record created (dispatcher, desk, vacancy, etc.)
- **update**: Existing record modified
- **delete**: Record removed
- **login**: User login
- **logout**: User logout
- **import**: Bulk operations (CSV import, etc.)

### Usage Examples

```php
// Log a create action
AuditLog::create('dispatchers', $dispatcherId, 'John Doe', [
    'first_name' => 'John',
    'last_name' => 'Doe',
    'employee_number' => '12345'
]);

// Log an update action
AuditLog::update('desks', $deskId, 'Desk 5',
    ['name' => 'Desk 5', 'division_id' => 1],  // old values
    ['name' => 'Desk 5A', 'division_id' => 2]  // new values
);

// Log a delete action
AuditLog::delete('vacancies', $vacancyId, 'Vacation 2025-01-15', [
    'dispatcher_id' => 10,
    'start_date' => '2025-01-15',
    'reason' => 'vacation'
]);

// Log an import
AuditLog::import('dispatchers', 25, 'CSV import of 25 dispatchers');
```

### Viewing Audit Logs

```php
// Get recent activity (last 100 entries)
$recent = AuditLog::getRecentActivity(100);

// Get activity for a specific user
$userActivity = AuditLog::getUserActivity($userId, 50);

// Get history for a specific record
$recordHistory = AuditLog::getRecordHistory('dispatchers', $dispatcherId);

// Get filtered entries
$entries = AuditLog::getEntries([
    'action' => 'update',
    'table_name' => 'dispatchers',
    'date_from' => '2025-01-01',
    'date_to' => '2025-01-31'
], 100, 0);
```

## Database Schema

### Users Table
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,  -- bcrypt hash
    role ENUM('admin', 'read_only') DEFAULT 'read_only',
    active TINYINT(1) DEFAULT 1,
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### User Sessions Table
```sql
CREATE TABLE user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Audit Log Table
```sql
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    action ENUM('create', 'update', 'delete', 'login', 'logout', 'import') NOT NULL,
    table_name VARCHAR(100) NULL,
    record_id INT NULL,
    record_description VARCHAR(255) NULL,
    old_values TEXT NULL,  -- JSON
    new_values TEXT NULL,  -- JSON
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

## UI Behavior

### Admin Users
- See all navigation buttons
- Can click "Add Dispatcher", "Edit", "Delete" buttons
- Can access Settings tab
- Can view audit logs (future feature)

### Read-Only Users
- See same views as admin
- All write buttons are disabled (greyed out, non-clickable)
- "Read-Only" badge shown in header
- Yellow notice bar shown: "You have read-only access. Contact an administrator to make changes."
- Can still view details, export data, etc.

### Visual Indicators
- User info displayed in header: `username (Admin)` or `username (Read-Only)`
- Read-only mode adds CSS class `.read-only-mode` to disable buttons
- Disabled buttons have reduced opacity and `cursor: not-allowed`

## Security Features

1. **Password Hashing**: All passwords stored using bcrypt (`password_hash()`)
2. **Session Regeneration**: Session ID regenerated on login to prevent fixation attacks
3. **CSRF Protection**: (Future: add CSRF tokens to forms)
4. **Session Timeout**: Auto-logout after 2 hours of inactivity
5. **Secure Cookies**: HttpOnly flag set, HTTPS recommended
6. **Role-Based Access Control**: Server-side validation on all API endpoints
7. **Audit Trail**: Immutable log of all changes with who/what/when
8. **IP Tracking**: Client IP logged for all actions

## API Integration

All write operations in the API should:

1. **Check permissions** using `requireAdmin()` at the start
2. **Log the action** using `AuditLog` before returning success
3. **Include old values** for updates/deletes (fetch before modifying)

Example API endpoint:
```php
<?php
require_once '../config/auth.php';
require_once '../includes/AuditLog.php';

// Require admin privileges
requireAdmin();

if ($action === 'update_dispatcher') {
    $id = $_POST['id'];
    $newData = [/* ... */];

    // Get old data first
    $oldData = dbQueryOne("SELECT * FROM dispatchers WHERE id = ?", [$id]);

    // Perform update
    $sql = "UPDATE dispatchers SET first_name = ?, last_name = ? WHERE id = ?";
    dbExecute($sql, [$newData['first_name'], $newData['last_name'], $id]);

    // Log the change
    AuditLog::update('dispatchers', $id,
        $oldData['first_name'] . ' ' . $oldData['last_name'],
        $oldData,
        $newData
    );

    echo json_encode(['success' => true]);
}
?>
```

## Future Enhancements

1. **User Management UI**: Admin interface to create/edit users
2. **Audit Log Viewer**: Searchable interface to browse audit logs
3. **Role Permissions**: More granular permissions (e.g., can edit dispatchers but not desks)
4. **Email Notifications**: Alert on suspicious activity
5. **Export Audit Logs**: Download as CSV for external analysis
6. **Two-Factor Authentication**: Optional 2FA for admin accounts
7. **Password Reset**: Self-service password reset with email verification

## Maintenance

### Cleaning Old Audit Logs
```php
// Remove logs older than 2 years (730 days)
$deletedCount = AuditLog::cleanup(730);
echo "Deleted $deletedCount old audit log entries";
```

**Important**: Be very careful when deleting audit logs. Consider archiving to another system before deletion for regulatory compliance.

### Monitoring Sessions
```sql
-- View active sessions
SELECT u.username, s.ip_address, s.last_activity, s.expires_at
FROM user_sessions s
JOIN users u ON s.user_id = u.id
WHERE s.expires_at > NOW()
ORDER BY s.last_activity DESC;

-- Clean up expired sessions
DELETE FROM user_sessions WHERE expires_at < NOW();
```

## Migration

To add roles and audit logging to an existing installation:

```bash
mysql -u your_user -p your_database < database/migrations/006_add_user_roles_and_audit_log.sql
```

This migration:
- Adds `role`, `active`, and `last_login` columns to users table
- Creates `user_sessions` table
- Creates `audit_log` table
- Sets first user to admin role automatically

## Compliance Notes

The audit log system helps meet compliance requirements for:
- **SOX (Sarbanes-Oxley)**: Financial data integrity and change tracking
- **HIPAA**: Access logging (if handling protected health information)
- **GDPR**: Data access and modification logs
- **ISO 27001**: Information security management

**Retention Policy Recommendation**: Keep audit logs for at least 2 years, archive older logs to cold storage if needed for longer retention.
