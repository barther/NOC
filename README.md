# NOC Scheduler

**Network Operations Center - 24/7 Dispatcher Scheduling System**

A comprehensive scheduling system for managing railroad dispatcher assignments, ensuring continuous 24/7 coverage while enforcing FRA hours of service regulations and union work rules.

## Features

- **Dispatcher Management**: Track dispatchers with seniority rankings, classifications, and qualifications
- **Desk Assignments**: Manage regular job holders, relief dispatchers, and Around-the-World coverage
- **FRA Compliance**: Automatic enforcement of 9-hour duty limit and 15-hour minimum rest
- **Vacancy Coverage**: Intelligent order-of-call engine for filling vacancies
- **Hold-down Bidding**: Manage vacation/training coverage with seniority-based bidding
- **Complete Audit Trail**: Log all assignments and decisions
- **Web-based Interface**: Standalone LAMP application, no cloud dependencies

## System Requirements

- **Web Server**: Apache 2.4+ (with mod_rewrite)
- **PHP**: 7.4 or higher (PHP 8.x recommended)
- **Database**: MySQL 5.7+ or MariaDB 10.3+
- **Browser**: Modern browser with JavaScript enabled

## Installation

### 1. Clone or Download

Place the NOC Scheduler files in your web server's document root:

```bash
# Example for Apache on Linux
cd /var/www/html
git clone <repository-url> noc-scheduler
cd noc-scheduler
```

### 2. Database Setup

Create a MySQL database and user:

```sql
CREATE DATABASE noc_scheduler CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'noc_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON noc_scheduler.* TO 'noc_user'@'localhost';
FLUSH PRIVILEGES;
```

Import the database schema:

```bash
mysql -u noc_user -p noc_scheduler < database/schema.sql
```

### 3. Configure Database Connection

Edit `config/database.php` and update the connection settings:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'noc_scheduler');
define('DB_USER', 'noc_user');
define('DB_PASS', 'your_secure_password');
```

### 4. Set Permissions

Ensure proper file permissions:

```bash
# Set ownership (adjust user/group for your system)
chown -R www-data:www-data /var/www/html/noc-scheduler

# Set directory permissions
find /var/www/html/noc-scheduler -type d -exec chmod 755 {} \;

# Set file permissions
find /var/www/html/noc-scheduler -type f -exec chmod 644 {} \;
```

### 5. Apache Configuration

Create a virtual host configuration (optional but recommended):

```apache
<VirtualHost *:80>
    ServerName noc-scheduler.local
    DocumentRoot /var/www/html/noc-scheduler

    <Directory /var/www/html/noc-scheduler>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/noc-scheduler-error.log
    CustomLog ${APACHE_LOG_DIR}/noc-scheduler-access.log combined
</VirtualHost>
```

Enable the site and restart Apache:

```bash
sudo a2ensite noc-scheduler
sudo systemctl restart apache2
```

### 6. Access the Application

Open your browser and navigate to:
- `http://localhost/noc-scheduler` (if installed in subdirectory)
- `http://noc-scheduler.local` (if using virtual host)

## Initial Setup

### 1. Configure System Settings

Navigate to **Settings** and configure:
- **FRA Hours of Service**: Default is 9 hours max duty, 15 hours min rest
- **EB Baseline Count**: Set the baseline Extra Board count for overtime calculations

### 2. Create Divisions

1. Go to **Desks** → **Manage Divisions**
2. Create your 6 divisions (e.g., Northern, Southern, Eastern, Western, Central, Coastal)

### 3. Create Desks

1. Go to **Desks** → **Add Desk**
2. Create all ~54 desks, assigning each to a division

### 4. Add Dispatchers

1. Go to **Dispatchers** → **Add Dispatcher**
2. Enter employee information:
   - Employee Number
   - First/Last Name
   - Seniority Date (automatically calculates rank)
   - Classification (Job Holder, Extra Board, or Qualifying)

### 5. Set Qualifications

For each dispatcher:
1. Click **Edit** → **Qualifications**
2. Mark which desks they are qualified for

### 6. Assign Regular Jobs

1. Go to **Desks** → **Manage Assignments**
2. For each desk, assign:
   - First shift dispatcher
   - Second shift dispatcher
   - Third shift dispatcher
   - Relief dispatcher

### 7. Configure Relief Schedules

1. Go to **Desks** → **Manage Assignments** → **Relief Schedule**
2. The system will auto-generate standard relief coverage:
   - 2 First shifts (Sat/Sun)
   - 2 Second shifts (Sat/Sun)
   - 1 Third shift (Sat night)

### 8. Set Up Around-the-World Rotation

1. Go to **Settings** → **ATW Rotation**
2. The system will distribute the 7th third shift across desks
3. Assign an ATW dispatcher

## Daily Operations

### Viewing the Schedule

1. Go to **Schedule**
2. Select a week start date
3. View complete coverage for all desks, all shifts, 7 days

### Creating Vacancies

When a dispatcher calls out sick or takes leave:

1. Go to **Vacancies** → **Create Vacancy**
2. Select:
   - Desk
   - Shift
   - Date
   - Type (sick, vacation, training, etc.)
3. Click **Fill** to run the order-of-call engine

### Order of Call Process

The system automatically follows the collective bargaining agreement:

1. **Extra Board**: Check for qualified, rested EB
2. **Incumbent OT**: Offer to desk incumbent on rest day
3. **Senior Rest Day OT**: Offer to senior qualified dispatchers on rest days
4. **Junior Diversion (with EB)**: Divert junior dispatcher if EB can backfill
5. **Junior Diversion (cascading)**: Divert junior even without EB (creates cascade)
6. **Senior Off-Shift OT**: Divert senior from different shift (with EB backfill)
7. **Fallback**: Least overtime cost solution

The system logs every step and shows the decision path.

### Posting Hold-Downs

For planned absences (vacation, training):

1. Go to **Hold-Downs** → **Post Hold-Down**
2. Enter:
   - Desk and shift
   - Start/End dates
   - Incumbent dispatcher
3. System posts for bidding

### Bidding on Hold-Downs

Dispatchers can bid through the interface:

1. Go to **Hold-Downs**
2. Click **Bid** on open postings
3. System tracks all bids

### Awarding Hold-Downs

1. Go to **Hold-Downs**
2. Click **View Bids** to see all bidders
3. Click **Award** to automatically award to most senior qualified bidder
4. System checks FRA compliance and inserts hold-off day if needed
5. Creates vacancy coverage for incumbent's regular job

## Business Rules Reference

### FRA Hours of Service (49 CFR Part 228)

- **Maximum duty**: 9 hours
- **Minimum rest**: 15 hours before next shift
- **Effect**: Locks dispatchers into 24-hour cycles

### Dispatcher Classifications

1. **Job Holders**: Assigned to desk/shift indefinitely
2. **Extra Board (GAD)**: Unassigned pool for coverage
3. **Qualifying**: Learning a desk (limited use)

### Regular Coverage

- **Regular Shifts**: First (0600-1400), Second (1400-2200), Third (2200-0600)
- **5-Day Block**: Job holders work 5 consecutive days
- **Relief Coverage**: 2 first, 2 second, 1 third (typically weekends)
- **Around-the-World**: Covers the 7th third shift each week

### Vacancy Coverage Priority

1. Extra Board (qualified)
2. Incumbent on rest day (OT)
3. Senior dispatcher on rest day (OT)
4. Junior diversion, same shift (with EB backfill)
5. Junior diversion, same shift (without EB - cascades)
6. Senior diversion, off shift (with EB, OT)
7. Fallback (least cost)

### Special Protections

- **Improper Diversion**: 4-hour straight-time penalty
- **EB Below Baseline**: Diversions paid at overtime
- **Qualifying Dispatchers**: Excluded unless carrier opts in

## Data Model

### Core Tables

- **divisions**: Geographic/operational divisions
- **desks**: Dispatcher positions (54 total)
- **dispatchers**: Employee records with seniority
- **dispatcher_qualifications**: Desk qualifications
- **job_assignments**: Current job holders
- **relief_schedules**: Weekend coverage patterns
- **atw_rotation**: Around-the-World schedule
- **vacancies**: Open positions needing coverage
- **holddowns**: Vacation/training coverage bidding
- **vacancy_fills**: Decision log for order-of-call
- **assignment_log**: Complete audit trail
- **fra_hours_tracking**: Hours of service compliance

## API Reference

The system provides a REST API at `api/index.php`:

### Divisions

- `divisions_list`: Get all divisions
- `division_create`: Create division
- `division_update`: Update division
- `division_delete`: Delete division

### Desks

- `desks_list`: Get all desks
- `desk_create`: Create desk
- `desk_update`: Update desk
- `desk_assignments`: Get assignments for desk

### Dispatchers

- `dispatchers_list`: Get all dispatchers
- `dispatcher_create`: Create dispatcher
- `dispatcher_update`: Update dispatcher
- `dispatcher_qualifications`: Get qualifications
- `dispatcher_set_qualification`: Add/update qualification

### Schedule

- `schedule_assign_job`: Assign dispatcher to job
- `schedule_get_date`: Get schedule for date
- `schedule_get_range`: Get schedule for date range
- `schedule_generate_standard_relief`: Auto-generate relief schedule
- `schedule_generate_atw`: Auto-generate ATW rotation

### Vacancies

- `vacancy_create`: Create vacancy
- `vacancy_fill`: Fill vacancy using order-of-call
- `vacancies_list`: Get all vacancies

### Hold-downs

- `holddown_post`: Post hold-down for bidding
- `holddown_bid`: Submit bid
- `holddown_award`: Award to senior bidder
- `holddowns_list`: Get all hold-downs

## Security Considerations

This system is designed for internal network use. For production deployment:

1. **Change default database password**
2. **Use HTTPS** (configure SSL certificate)
3. **Add authentication** (the system currently has no login)
4. **Restrict network access** (firewall, VPN)
5. **Regular backups** of the database
6. **Keep PHP and MySQL updated**

## Backup and Maintenance

### Daily Backup

```bash
#!/bin/bash
# Backup script
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u noc_user -p noc_scheduler > /backups/noc_scheduler_$DATE.sql
```

### Verify Data Integrity

Regularly check:
- Seniority rankings are correct
- No overlapping assignments
- FRA compliance on all assignments
- Vacancy coverage is complete

## Troubleshooting

### Database Connection Errors

- Check `config/database.php` credentials
- Verify MySQL service is running: `systemctl status mysql`
- Check MySQL user permissions

### Schedule Not Loading

- Check browser console for JavaScript errors
- Verify API is accessible: navigate to `api/index.php?action=config_get`
- Check Apache error logs: `/var/log/apache2/error.log`

### FRA Violations

- Recalculate: System should prevent but verify `fra_hours_tracking` table
- Check system config for max duty / min rest hours

## Support

For issues, questions, or feature requests, please contact your system administrator or refer to the project documentation.

## License

Copyright © 2024. All rights reserved.

This software is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
