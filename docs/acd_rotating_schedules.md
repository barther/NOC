# Assistant Chief Dispatcher (ACD) Rotating Schedules

## Overview

Assistant Chief Dispatchers (ACDs) work on a 4-day rotating schedule with 12-hour shifts, unlike regular dispatchers who work 8-hour shifts.

## Schedule Pattern

### 12-Hour Shifts
- **Day Shift**: 0600-1800 (12 hours)
- **Night Shift**: 1800-0600 (12 hours)

### 4-Day Rotation
ACDs work 4 consecutive days, then have time off, rotating through day and night shifts.

## Identification in CSV Data

In the `data.csv` file, ACDs are identified by:
- Assignment codes containing "ACD" (e.g., "PB ACD-1-GOLD", "LK ACD-1-BLUE")
- OFF_DAYS column shows "4 Days Rotating"

## Examples from Real Data

```
MCQUILLEN, GARRETT - KEYSTONE WEST ACD (PB ACD-1-GOLD) - 4 Days Rotating
INCARNATO, PATTI - GREATLAKES WEST ACD (LK ACD-1-BLUE) - 4 Days Rotating
STACY, KEVIN - GREATLAKES WEST ACD (LK ACD-1-GOLD) - 4 Days Rotating
```

## Assignment Colors

ACDs often have color-coded assignments:
- **GOLD**: One rotation group
- **BLUE**: Another rotation group

These colors help distinguish between the different rotation crews to ensure coverage.

## Import Handling

The CSV import tool (`tools/import_dispatchers.php`) currently:
- ✅ Imports ACDs as regular dispatchers
- ✅ Creates their desk assignments
- ✅ Qualifies them for their ACD desks
- ⏭️ **Skips** the rotating schedule configuration (requires manual setup)

**After Import**: 78 ACDs require manual schedule configuration through the web interface.

## Manual Configuration (To Be Implemented)

To properly configure ACD rotating schedules, the system would need:

1. **Extended Shift Types**:
   - Add "day_12hr" (0600-1800)
   - Add "night_12hr" (1800-0600)

2. **Rotation Tracking**:
   - Track 4-day rotation cycles
   - Assign dispatchers to rotation groups (GOLD, BLUE, etc.)
   - Calculate which group is on duty each day

3. **Schedule Generation**:
   - Generate 4-days-on, days-off patterns
   - Rotate between day and night shifts
   - Coordinate between multiple rotation groups

## Temporary Workaround

Until rotating schedule support is fully implemented:

1. **Import the ACDs** - They will be created with basic assignments
2. **Manual tracking** - Use external spreadsheet or calendar for 4-day rotations
3. **Update regularly** - Manually adjust assignments in the system as rotations change
4. **Document patterns** - Keep notes on which color group is on which days

## Future Enhancement

A complete ACD rotating schedule implementation should include:
- Rotation calendar management
- Automatic schedule generation based on rotation cycle
- Rotation group management (GOLD, BLUE, etc.)
- 12-hour shift support
- Rotation pattern configuration (4-on, how many off, day/night alternation)
- Conflict detection (ensuring adequate coverage across rotation groups)

## Statistics from Current Data

- **Total ACDs**: 78 dispatchers
- **Divisions with ACDs**: Keystone, Great Lakes, Midwest, Gulf, Relief ACD
- **Common patterns**:
  - Assignment codes: `XX ACD-[SHIFT]-[COLOR]`
  - Colors: GOLD, BLUE
  - All marked as "4 Days Rotating"
