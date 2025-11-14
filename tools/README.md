# NOC Scheduler Tools

This directory contains utility scripts for managing the NOC Scheduler system.

## Import Dispatchers from CSV

### Overview

The `import_dispatchers.php` script automates the bulk import of dispatcher data from a CSV file. This saves significant time when setting up the system with existing dispatcher rosters.

### CSV Format

The script expects a CSV file with the following columns:

```
Dispatcher,Status,Seniority Date,Seq.,Effective,Division,Assignment Name,Assignment,OFF_DAYS
```

**Column Descriptions:**
- **Dispatcher**: Last name, First name (e.g., "DICKERSON, RUSSELL")
- **Status**: ACTIVE-DISPATCHER or INACTIVE-DISPATCHER (inactive dispatchers are skipped)
- **Seniority Date**: MM/DD/YY format (e.g., "12/24/87")
- **Seq.**: Sequence number (not currently used)
- **Effective**: Effective date of assignment (not currently used)
- **Division**: Division name (e.g., "BLUE RIDGE", "KEYSTONE", "GREATLAKES")
- **Assignment Name**: Desk name (e.g., "NEW RIVER", "KANSAS CITY")
- **Assignment**: Desk code with shift (e.g., "NR-1", "KC-3")
  - The number indicates shift: 1=First, 2=Second, 3=Third
- **OFF_DAYS**: Rest days (e.g., "SAT SUN", "FRI SAT", "MON TUE", "4 Days Rotating")

### What the Script Does

1. **Creates Divisions**: Automatically creates any divisions that don't exist
2. **Creates Desks**: Automatically creates desks with proper codes
3. **Imports Dispatchers**:
   - Generates employee numbers
   - Sets seniority dates
   - Sets classification as "job_holder"
4. **Creates Assignments**:
   - Assigns dispatchers to their desks/shifts
   - Qualifies them for their assigned desks
5. **Configures Rest Days**:
   - Parses OFF_DAYS column
   - Creates custom rest day configurations
   - Skips "rotating" schedules (manual configuration needed)
6. **Recalculates Seniority**: Updates seniority rankings based on dates

### Usage

**IMPORTANT**: This script will add data to your database. Make sure you have a backup before running!

```bash
# Navigate to the tools directory
cd /home/user/NOC/tools

# Run the import script
php import_dispatchers.php
```

### Expected Output

```
ℹ Starting dispatcher data import...
ℹ CSV Header: Dispatcher,Status,Seniority Date,Seq.,Effective,Division,Assignment Name,Assignment,OFF_DAYS
✓ Created division: BLUE RIDGE (ID: 1)
✓ Created desk: NEW RIVER (NR-1) in BLUE RIDGE
✓ Created dispatcher: 87001 - RUSSELL DICKERSON (Seniority: 1987-12-24)
ℹ   → Assigned to NEW RIVER first shift with rest days: SAT SUN (days: 6,0)
✓ Created dispatcher: 94002 - GARRETT MCQUILLEN (Seniority: 1994-02-14)
...
ℹ Recalculating seniority ranks...
✓ Import completed successfully!
✓ Imported: 250 dispatchers
✓ Created: 6 divisions
✓ Created: 54 desks
ℹ Skipped: 24 records
```

### Troubleshooting

**Problem**: "CSV file not found"
- **Solution**: Make sure `data.csv` exists in the root `/home/user/NOC/` directory

**Problem**: "Database connection failed"
- **Solution**: Check `config/database.php` settings and ensure MySQL is running

**Problem**: Inactive dispatchers are being imported
- **Solution**: The script automatically skips INACTIVE-DISPATCHER status entries

**Problem**: "Rotating" schedules not importing
- **Solution**: The script skips rotating schedules. You'll need to configure these manually through the web interface

**Problem**: Duplicate employee numbers
- **Solution**: The script generates employee numbers based on seniority year + counter. If you need specific employee numbers, modify the CSV to include them in the Dispatcher column

### After Import

Once the import is complete:

1. **Verify the data**: Go to the web interface and check:
   - Dispatchers list (should show all imported)
   - Desks view (should show assignments)
   - Schedule view (should show coverage)

2. **Configure relief dispatchers**: The import assigns regular jobs. You'll need to:
   - Assign relief dispatchers manually
   - Set up ATW rotation

3. **Configure rotating schedules**: Any "4 Days Rotating" schedules need manual configuration

4. **Update qualifications**: The import qualifies each dispatcher for their assigned desk only. Add additional qualifications as needed.

### Notes

- The script uses database transactions - if any error occurs, all changes are rolled back
- Employee numbers are auto-generated: `[YY][###]` format (e.g., 87001, 94002)
- All imported dispatchers are set as "job_holder" classification
- Shift is determined from desk code suffix (NR-1 = first shift, KC-3 = third shift)
- Rest days are parsed from OFF_DAYS column and stored in job_rest_days table

### Manual Cleanup (if needed)

If you need to remove all imported data and start over:

```sql
-- WARNING: This deletes ALL dispatcher data!
DELETE FROM job_rest_days;
DELETE FROM job_assignments;
DELETE FROM dispatcher_qualifications;
DELETE FROM dispatchers;
DELETE FROM desks;
DELETE FROM divisions;

-- Reset auto-increment
ALTER TABLE divisions AUTO_INCREMENT = 1;
ALTER TABLE desks AUTO_INCREMENT = 1;
ALTER TABLE dispatchers AUTO_INCREMENT = 1;
```
