# NOC Scheduler - Quick Installation Guide

## Prerequisites Checklist

- [ ] Linux server with Apache installed
- [ ] PHP 7.4 or higher installed
- [ ] MySQL or MariaDB installed
- [ ] Root or sudo access to the server

## 5-Minute Quick Start

### Step 1: Install Dependencies (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install apache2 php php-mysql mysql-server
sudo systemctl enable apache2
sudo systemctl enable mysql
sudo systemctl start apache2
sudo systemctl start mysql
```

### Step 2: Create Database

```bash
# Run MySQL as root
sudo mysql

# In MySQL prompt, run:
CREATE DATABASE noc_scheduler CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'noc_user'@'localhost' IDENTIFIED BY 'YourSecurePassword123!';
GRANT ALL PRIVILEGES ON noc_scheduler.* TO 'noc_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Step 3: Deploy Application

```bash
# Navigate to web root
cd /var/www/html

# If you have the code in a git repository
git clone <your-repo-url> noc-scheduler
cd noc-scheduler

# OR if you have a ZIP file
unzip noc-scheduler.zip
cd noc-scheduler
```

### Step 4: Import Database Schema

```bash
mysql -u noc_user -p noc_scheduler < database/schema.sql
# Enter password when prompted: YourSecurePassword123!
```

### Step 5: Configure Database Connection

```bash
# Edit the config file
nano config/database.php

# Update these lines:
# define('DB_PASS', 'YourSecurePassword123!');

# Save and exit (Ctrl+X, Y, Enter)
```

### Step 6: Set Permissions

```bash
sudo chown -R www-data:www-data /var/www/html/noc-scheduler
sudo find /var/www/html/noc-scheduler -type d -exec chmod 755 {} \;
sudo find /var/www/html/noc-scheduler -type f -exec chmod 644 {} \;
```

### Step 7: Test Installation

Open your web browser and navigate to:
```
http://your-server-ip/noc-scheduler
```

You should see the NOC Scheduler interface!

## First-Time Setup Wizard

### 1. Configure System (Settings Tab)

- Set EB Baseline Count (e.g., 5)
- Verify FRA hours (9 max duty, 15 min rest)

### 2. Create Divisions

Navigate to **Desks** → **Manage Divisions**

Example divisions:
- Northern Division (Code: NORTH)
- Southern Division (Code: SOUTH)
- Eastern Division (Code: EAST)
- Western Division (Code: WEST)
- Central Division (Code: CENTRAL)
- Coastal Division (Code: COAST)

### 3. Create Desks

Navigate to **Desks** → **Add Desk**

Example for Northern Division:
- Desk: "Northern Main" (Code: N-MAIN)
- Desk: "Northern Branch" (Code: N-BRANCH)
- Repeat for all ~54 desks

### 4. Add Dispatchers

Navigate to **Dispatchers** → **Add Dispatcher**

Example:
- Employee #: 1001
- Name: John Smith
- Seniority Date: 2015-03-15
- Classification: Job Holder

**Important**: Seniority date determines rank automatically!

### 5. Set Qualifications

For each dispatcher:
1. Click **Edit**
2. Go to **Qualifications**
3. Check desks they are qualified for
4. Save

### 6. Assign Jobs

Navigate to **Desks** → **Manage Assignments**

For each desk, assign:
- **First Shift** (0600-1400): Select dispatcher
- **Second Shift** (1400-2200): Select dispatcher
- **Third Shift** (2200-0600): Select dispatcher
- **Relief Dispatcher**: Select dispatcher, generate standard schedule

### 7. Set Up Around-the-World

Navigate to **Settings** → **Generate ATW Rotation**

This distributes the 7th third shift across all desks on a rotating schedule.

## Sample Data (For Testing)

You can insert this sample data to test the system:

```sql
-- Sample Divisions
INSERT INTO divisions (name, code) VALUES
('Northern Division', 'NORTH'),
('Southern Division', 'SOUTH');

-- Sample Desks
INSERT INTO desks (division_id, name, code, description) VALUES
(1, 'Northern Main', 'N-MAIN', 'Primary northern desk'),
(1, 'Northern Branch', 'N-BRANCH', 'Northern branch line'),
(2, 'Southern Main', 'S-MAIN', 'Primary southern desk');

-- Sample Dispatchers
INSERT INTO dispatchers (employee_number, first_name, last_name, seniority_date, seniority_rank, classification) VALUES
('1001', 'John', 'Smith', '2010-01-15', 1, 'job_holder'),
('1002', 'Jane', 'Doe', '2012-03-20', 2, 'job_holder'),
('1003', 'Bob', 'Johnson', '2015-06-10', 3, 'extra_board'),
('1004', 'Alice', 'Williams', '2018-09-05', 4, 'extra_board');

-- Sample Qualifications (all qualified for all desks for testing)
INSERT INTO dispatcher_qualifications (dispatcher_id, desk_id, qualified, qualified_date) VALUES
(1, 1, 1, '2010-03-15'),
(1, 2, 1, '2011-01-10'),
(2, 1, 1, '2012-06-20'),
(2, 2, 1, '2013-01-15'),
(3, 1, 1, '2015-09-10'),
(4, 1, 1, '2019-01-05');
```

## Verification Checklist

After setup, verify:

- [ ] Can view schedule page
- [ ] All 6 divisions created
- [ ] All ~54 desks created and assigned to divisions
- [ ] All dispatchers entered with correct seniority
- [ ] Qualifications set for each dispatcher
- [ ] Regular jobs assigned (first, second, third shift for each desk)
- [ ] Relief schedules generated
- [ ] ATW rotation configured
- [ ] Can create a test vacancy
- [ ] Can fill vacancy using order-of-call

## Troubleshooting

### "Database connection failed"
- Check MySQL service: `sudo systemctl status mysql`
- Verify credentials in `config/database.php`
- Check user permissions in MySQL

### "Blank page" or "500 Error"
- Check Apache error log: `sudo tail -f /var/log/apache2/error.log`
- Verify PHP is installed: `php -v`
- Check file permissions

### "Permission denied"
```bash
sudo chown -R www-data:www-data /var/www/html/noc-scheduler
```

### "Can't load schedule"
- Open browser console (F12) and check for errors
- Test API directly: navigate to `http://your-server/noc-scheduler/api/index.php?action=config_get`
- Should return JSON data

## Next Steps

1. **Add all your dispatchers** with correct seniority dates
2. **Set up all qualifications**
3. **Assign all regular jobs**
4. **Configure relief and ATW schedules**
5. **Start using for daily operations**

## Need Help?

Common questions:

**Q: How do I change a job assignment?**
A: Go to Desks → Manage Assignments → Select new dispatcher. The system ends the old assignment and starts the new one.

**Q: How do I handle a call-out?**
A: Go to Vacancies → Create Vacancy → Fill. The system runs order-of-call automatically.

**Q: How do I post a vacation hold-down?**
A: Go to Hold-Downs → Post Hold-Down → Enter dates. Qualified dispatchers can bid, then you award to the senior bidder.

**Q: What if FRA rules are violated?**
A: The system should prevent this automatically. Check Settings to verify 9/15 hour rules are configured correctly.

## Backup Your System

Set up automated daily backups:

```bash
# Create backup script
sudo nano /usr/local/bin/backup-noc.sh

# Add this content:
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u noc_user -pYourSecurePassword123! noc_scheduler > /backups/noc_scheduler_$DATE.sql
find /backups -name "noc_scheduler_*.sql" -mtime +30 -delete

# Make executable
sudo chmod +x /usr/local/bin/backup-noc.sh

# Add to cron (daily at 2 AM)
sudo crontab -e
# Add line: 0 2 * * * /usr/local/bin/backup-noc.sh
```

## You're All Set! 🚂

Your NOC Scheduler is now ready for 24/7 dispatcher scheduling.
