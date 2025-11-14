# NOC Scheduler v1.4.0 - Implementation Summary

## Contract-Compliant Railroad Dispatcher Scheduling System

This document describes the complete implementation of a contract-compliant vacancy filling and scheduling system for Norfolk Southern railroad dispatchers, based on the ATDA (American Train Dispatchers Association) collective bargaining agreement.

---

## Table of Contents

1. [Overview](#overview)
2. [Key Contract Rules Implemented](#key-contract-rules-implemented)
3. [System Architecture](#system-architecture)
4. [Database Schema](#database-schema)
5. [Core Components](#core-components)
6. [Order-of-Call Engine](#order-of-call-engine)
7. [GAD Management](#gad-management)
8. [ACD Support](#acd-support)
9. [FRA Hours of Service](#fra-hours-of-service)
10. [Cost Tracking](#cost-tracking)
11. [Usage Examples](#usage-examples)
12. [Next Steps](#next-steps)

---

## Overview

The NOC Scheduler is a standalone LAMP-based web application that manages 24/7 coverage for railroad dispatcher operations. It implements the complete order-of-call procedure from Article 3(g) of the Norfolk Southern ATDA contract, including GAD (Guaranteed Assigned Dispatcher) baseline rules, ACD (Assistant Chief Train Dispatcher) rotation, and FRA hours of service compliance.

**Key Features:**
- Contract-compliant vacancy filling (GAD checked FIRST)
- 7 rotating GAD rest day groups (A-G)
- 12-hour ACD shift support with 4-on/4-off rotation
- Actual cost calculation for "least cost" option
- FRA 9-hour duty / 15-hour rest enforcement
- Training protection
- Forcing tracker (consecutive days worked)
- Complete audit trail with decision logs

---

## Key Contract Rules Implemented

### Article 3(f) - GAD Scheduling
- **7 Rotating Rest Day Groups**: A, B, C, D, E, F, G
- Each group gets 2 consecutive rest days that rotate weekly
- Example: Group A = Sunday-Monday, Group B = Monday-Tuesday, etc.

### Article 3(g) - Order of Call for Filling Vacancies

**CORRECT ORDER (GAD FIRST!):**

1. **GAD (Guaranteed Assigned Dispatcher)** - if available
   - Skip if training protected (unless desperate)
   - Must be qualified for desk
   - Must meet FRA hours of service
   - Must not be on rest day
   - Pay: Straight time

2. If no GAD available, then proceed:

   a. **Incumbent Overtime**
      - Regular job holder for this desk/shift
      - Must be on rest day
      - Pay: Overtime

   b. **Senior Rest Day Overtime**
      - Most senior qualified dispatcher on rest day
      - Pay: Overtime

   c. **Junior Same-Shift Diversion (with GAD backfill)**
      - Most junior dispatcher on same shift, different desk
      - GAD fills their vacated position
      - Pay: Determined by GAD baseline (see Appendix 9)

   d. **Junior Same-Shift Diversion (no backfill)**
      - Creates cascading vacancy at their desk
      - Pay: Determined by GAD baseline

   e. **Senior Off-Shift Diversion (with GAD backfill)**
      - Most senior dispatcher on different shift
      - GAD fills their vacated position
      - Pay: Determined by GAD baseline

   f. **Least Cost Fallback**
      - **ACTUAL COST CALCULATION** (not just a cover-all clause!)
      - System evaluates all qualified dispatchers
      - Calculates straight time vs overtime costs
      - Picks cheapest option
      - Displays cost breakdown for transparency

### Appendix 9 - GAD Baseline Rules

**Baseline Ratio:** 1.0 GAD per desk (including ACD desks)

**Diversion Pay Type:**
- **Above baseline**: Diversions paid straight time
- **At or below baseline**: Diversions paid overtime

**Improper Diversion Penalty:**
- 4 hours straight time if order-of-call violated

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                       Web Interface                         │
│                 (index.php + public/js/app.js)              │
└─────────────────────────────────────────────────────────────┘
                              │
                              ├─ API Endpoints (tools/)
                              │
┌─────────────────────────────────────────────────────────────┐
│                     Core Business Logic                      │
├─────────────────────────────────────────────────────────────┤
│  VacancyEngine.php    - Order-of-call vacancy filling       │
│  GAD.php              - GAD pool & baseline management       │
│  ACD.php              - 12-hour shift & rotation tracking    │
│  FRAHours.php         - Hours of service compliance          │
│  Dispatcher.php       - Dispatcher management                │
│  Desk.php             - Desk/division management             │
│  Schedule.php         - Schedule generation                  │
└─────────────────────────────────────────────────────────────┘
                              │
┌─────────────────────────────────────────────────────────────┐
│                    MySQL Database                            │
│  - dispatchers, desks, divisions                            │
│  - job_assignments, job_rest_days                           │
│  - vacancies, vacancy_fills, vacancy_fill_options           │
│  - gad_baseline, gad_availability_log                       │
│  - acd_rotation                                             │
│  - fra_hours_tracking                                       │
│  - dispatcher_pay_rates                                     │
│  - assignment_log                                           │
└─────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### New Tables (v1.4.0)

#### `gad_baseline`
Tracks GAD baseline ratio per division (1.0 per desk).

```sql
- division_id
- total_desks (8-hour regular desks)
- acd_desks (12-hour ACD desks)
- baseline_gad_count (1.0 per total desks)
- current_gad_count (actual GAD count)
- above_baseline (computed: current > baseline)
- effective_date
```

#### `acd_rotation`
Tracks 4-on/4-off rotation for ACDs.

```sql
- dispatcher_id
- crew_color (GOLD/BLUE)
- shift_type (day/night)
- rotation_start_date (4-day block start)
- rotation_end_date (4-day block end)
- on_rotation (1=working, 0=resting)
```

#### `vacancy_fill_options`
Records all considered options for each vacancy (audit trail).

```sql
- vacancy_id
- option_rank (1-7 for order-of-call step)
- option_type (gad, incumbent_ot, etc)
- dispatcher_id
- available (yes/no)
- unavailable_reason
- pay_type (straight/overtime)
- calculated_cost (actual dollar amount)
- requires_backfill
```

#### `dispatcher_pay_rates`
Hourly and overtime rates for cost calculations.

```sql
- dispatcher_id
- hourly_rate
- overtime_rate (typically 1.5x base)
- effective_date
- end_date
```

#### `gad_availability_log`
Audit log of GAD availability checks.

```sql
- dispatcher_id
- check_date
- shift
- available (yes/no)
- unavailable_reason (rest_day, training, hos, etc)
```

### Enhanced Tables

#### `dispatchers`
Added fields:
- `gad_rest_group` (A-G)
- `training_status` (none/in_training/training_complete)
- `training_protected` (skip in order-of-call if 1)
- `training_start_date`
- `training_end_date`
- `consecutive_days_worked` (forcing tracker)
- `last_work_date`

#### `desks`
Added fields:
- `shift_hours` (8 or 12)
- `is_acd_desk` (flag for ACD desks)

#### `vacancy_fills`
Added fields:
- `pay_type` (straight/overtime)
- `hours_worked`
- `calculated_cost`
- `improper_diversion` (penalty flag)
- `penalty_hours` (4.0 for violations)
- `penalty_cost`

#### `vacancies`
Added fields:
- `filled_by_option_rank` (1-7)
- `filled_by_option_type`
- `total_cost`

#### `assignment_log`
Added fields:
- `hourly_rate`
- `calculated_cost`
- `gad_baseline_status` (above/at/below)
- `forced` (captured before leaving)
- `consecutive_day_count`

---

## Core Components

### VacancyEngine.php

**Purpose:** Fill vacancies using contract-compliant order-of-call.

**Key Methods:**
```php
fillVacancy($vacancyId)              // Main entry point
tryGAD($vacancy)                     // Step 1: Check GAD
tryIncumbentOT($vacancy)             // Step 2: Incumbent OT
trySeniorRestOT($vacancy)            // Step 3: Senior rest day OT
tryJuniorDiversionWithGAD($vacancy)  // Step 4: Jr diversion + GAD backfill
tryJuniorDiversion($vacancy)         // Step 5: Jr diversion (cascade)
trySeniorOffShiftWithGAD($vacancy)   // Step 6: Sr off-shift + GAD backfill
findLeastCostOption($vacancy)        // Step 7: Actual cost calculation
calculateCost($dispatcher, $payType, $hours) // Cost calculator
executeFill($vacancy, $option, $rank, $type) // Record the fill
```

**Returns:**
```php
[
    'fill_id' => 123,
    'dispatcher_id' => 456,
    'dispatcher_name' => 'John Smith',
    'option_rank' => 1,          // Which step filled it
    'option_type' => 'gad',      // Type of fill
    'pay_type' => 'straight',
    'cost' => 240.00,
    'decision_log' => [...]      // Full audit trail
]
```

### GAD.php

**Purpose:** Manage GAD pool, rest day groups, and baseline calculations.

**Key Methods:**
```php
assignToGAD($dispatcherId, $restGroup)           // Add to GAD pool
removeFromGAD($dispatcherId)                      // Remove from GAD
getAvailableGAD($date, $shift, $deskId)          // Find available GADs
isRestDay($dispatcherId, $date)                  // Check rest day
calculateBaseline($divisionId)                    // Calc 1.0 per desk ratio
getBaselineStatus($divisionId, $date)            // Current baseline status
getDiversionPayType($divisionId, $date)          // Straight or OT?
getDispatchersOnRest($date)                      // Who's on rest today
```

**Rest Day Schedule:**
```php
const REST_SCHEDULE = [
    'A' => [0, 1],  // Sunday-Monday
    'B' => [1, 2],  // Monday-Tuesday
    'C' => [2, 3],  // Tuesday-Wednesday
    'D' => [3, 4],  // Wednesday-Thursday
    'E' => [4, 5],  // Thursday-Friday
    'F' => [5, 6],  // Friday-Saturday
    'G' => [6, 0],  // Saturday-Sunday
];
```

### ACD.php

**Purpose:** Manage 12-hour ACD shifts and 4-on/4-off rotation.

**Key Methods:**
```php
assignToACD($dispatcherId, $crewColor, $shiftType, $rotationStartDate)
removeFromACD($dispatcherId)
isOnRotation($dispatcherId, $date)           // Working or resting?
getACDByCrew($crewColor)                     // Get GOLD or BLUE crew
getACDsForDate($date)                        // Who's working today
advanceRotation($dispatcherId)               // Move to next 4-day block
interruptRotation($dispatcherId, $date)      // Skip rotation day (rest)
getCrewScheduleMatrix($startDate, $endDate) // Visual crew schedule
verifyCrewAlternation($startDate, $endDate) // Check GOLD/BLUE alternate
```

**Shift Times:**
- Day: 0600-1800 (12 hours)
- Night: 1800-0600 (12 hours)

### FRAHours.php

**Purpose:** Track hours of service and enforce FRA compliance.

**Key Methods:**
```php
recordHours($dispatcherId, $date, $shift, $startTime, $endTime)
getLastShift($dispatcherId)
getNextAvailableTime($dispatcherId)              // When available after rest
isAvailableForShift($dispatcherId, $date, $shift) // Can they work this?
getHoursWorked($dispatcherId, $startDate, $endDate)
checkViolations($dispatcherId, $startDate, $endDate)
getAvailabilityStatus($dispatcherId)
wouldCauseViolation($dispatcherId, $date, $shift)
```

**FRA Rules:**
- Max duty: 9 hours (regular), 12 hours (ACD)
- Min rest: 15 hours between shifts

---

## Order-of-Call Engine

### Flow Diagram

```
┌─────────────────────────────────────┐
│   Vacancy Created                   │
│   (Desk X, Shift Y, Date Z)         │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  Step 1: Check GAD                  │
│  - Qualified for desk?              │
│  - Not on rest day?                 │
│  - Not training protected?          │
│  - FRA HOS OK?                      │
└──────┬──────────────────────────────┘
       │
       ├─ YES ──► FILL (straight time) ──► END
       │
       └─ NO ──► Continue
                  │
                  ▼
       ┌────────────────────────────────┐
       │  Step 2: Incumbent OT          │
       │  - Regular holder on rest day? │
       │  - FRA HOS OK?                 │
       └──────┬─────────────────────────┘
              │
              ├─ YES ──► FILL (overtime) ──► END
              │
              └─ NO ──► Continue
                         │
                         ▼
              ┌────────────────────────────────┐
              │  Step 3: Senior Rest Day OT    │
              │  - Most senior on rest?        │
              │  - Qualified?                  │
              │  - FRA HOS OK?                 │
              └──────┬─────────────────────────┘
                     │
                     ├─ YES ──► FILL (overtime) ──► END
                     │
                     └─ NO ──► Continue
                                │
                                ▼
                     ┌────────────────────────────────────┐
                     │  Step 4: Jr Diversion + GAD        │
                     │  - Most junior same shift?         │
                     │  - GAD available to backfill?      │
                     │  - Check GAD baseline for pay type │
                     └──────┬─────────────────────────────┘
                            │
                            ├─ YES ──► FILL (OT if below baseline) ──► END
                            │
                            └─ NO ──► Continue
                                       │
                                       ▼
                            ┌──────────────────────────────┐
                            │  Step 5: Jr Diversion        │
                            │  - Creates cascade vacancy   │
                            └──────┬───────────────────────┘
                                   │
                                   ├─ YES ──► FILL ──► END
                                   │
                                   └─ NO ──► Continue
                                              │
                                              ▼
                                   ┌─────────────────────────┐
                                   │  Step 6: Sr Off-Shift   │
                                   │  + GAD backfill         │
                                   └──────┬──────────────────┘
                                          │
                                          ├─ YES ──► FILL ──► END
                                          │
                                          └─ NO ──► Step 7
                                                     │
                                                     ▼
                                          ┌─────────────────────────┐
                                          │  Step 7: Least Cost     │
                                          │  - Calculate ALL costs  │
                                          │  - Pick cheapest        │
                                          │  - Show breakdown       │
                                          └──────┬──────────────────┘
                                                 │
                                                 └─ FILL ──► END
```

### Example Decision Log

```json
[
    {
        "timestamp": "2025-01-14 10:30:00",
        "message": "Starting order-of-call for Vacancy #42"
    },
    {
        "timestamp": "2025-01-14 10:30:00",
        "message": "Desk: Atlanta East, Shift: first, Date: 2025-01-15"
    },
    {
        "timestamp": "2025-01-14 10:30:00",
        "message": "Step 1: Checking GAD (Guaranteed Assigned Dispatchers)..."
    },
    {
        "timestamp": "2025-01-14 10:30:01",
        "message": "  ✗ No GAD available"
    },
    {
        "timestamp": "2025-01-14 10:30:01",
        "message": "Step 2: Checking incumbent overtime..."
    },
    {
        "timestamp": "2025-01-14 10:30:02",
        "message": "  ✓ Incumbent John Smith available for OT"
    },
    {
        "timestamp": "2025-01-14 10:30:02",
        "message": "✓ Vacancy filled successfully using incumbent_ot"
    }
]
```

---

## GAD Management

### Baseline Calculation Example

**Division: Atlanta**
- Regular 8-hour desks: 9
- ACD 12-hour desks: 2
- **Total desks: 11**
- **Baseline GAD count: 11.0** (1.0 per desk)

**Current GAD Pool:**
- 12 GADs assigned to Atlanta

**Baseline Status:**
- Current (12) > Baseline (11.0) = **ABOVE BASELINE**
- Diversions: **Straight time**

**If only 10 GADs:**
- Current (10) < Baseline (11.0) = **BELOW BASELINE**
- Diversions: **Overtime**

### GAD Availability Check

```php
// Check if GAD #123 is available for first shift on 2025-01-15
$available = GAD::getAvailableGAD('2025-01-15', 'first', $deskId);

// Returns:
[
    [
        'id' => 123,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'seniority_rank' => 5,
        'gad_rest_group' => 'C',
        'hourly_rate' => 32.50,
        'overtime_rate' => 48.75
    ]
]

// Or empty array if none available
```

---

## ACD Support

### 4-on/4-off Rotation Example

**GOLD Crew - Day Shift:**
```
Week 1: Mon Tue Wed Thu (ON)  | Fri Sat Sun Mon (OFF)
Week 2: Tue Wed Thu Fri (ON)  | Sat Sun Mon Tue (OFF)
Week 3: Wed Thu Fri Sat (ON)  | Sun Mon Tue Wed (OFF)
```

**BLUE Crew - Day Shift:**
```
Week 1: Fri Sat Sun Mon (ON)  | Tue Wed Thu Fri (OFF)
Week 2: Sat Sun Mon Tue (ON)  | Wed Thu Fri Sat (OFF)
Week 3: Sun Mon Tue Wed (ON)  | Thu Fri Sat Sun (OFF)
```

**Key Points:**
- GOLD and BLUE alternate
- Each crew works 4 consecutive days
- Then rests 4 consecutive days
- Rotation continues indefinitely

### Rotation Interruption

When an ACD fills a regular 8-hour job:
- Their 4-on/4-off rotation is interrupted
- System marks rotation as skipped
- Allows for 15-hour rest before next ACD shift

---

## FRA Hours of Service

### Example Scenarios

#### Scenario 1: Available
```
Last shift ended: 2025-01-14 15:00
Proposed shift: 2025-01-15 07:00 (first shift)
Hours between: 16 hours
Minimum required: 15 hours
Result: ✓ AVAILABLE
```

#### Scenario 2: Not Available (Insufficient Rest)
```
Last shift ended: 2025-01-14 23:00 (third shift)
Proposed shift: 2025-01-15 07:00 (first shift)
Hours between: 8 hours
Minimum required: 15 hours
Result: ✗ NOT AVAILABLE (HOS violation)
```

#### Scenario 3: Next Regular Shift Conflict
```
Last shift: OK (20 hours ago)
Proposed shift: 2025-01-15 15:00 (second shift, ends 23:00)
Next regular shift: 2025-01-16 07:00 (first shift)
Hours between proposed end and next: 8 hours
Minimum required: 15 hours
Result: ✗ NOT AVAILABLE (would violate rest before next shift)
```

---

## Cost Tracking

### Pay Rate Example

**Dispatcher: John Smith**
- Base hourly rate: $30.00
- Overtime rate: $45.00 (1.5x)

**Vacancy: 8-hour first shift**

**Cost Calculations:**
```
Straight time: $30.00 × 8 = $240.00
Overtime:      $45.00 × 8 = $360.00
```

### Least Cost Calculation

The system evaluates ALL qualified dispatchers and picks the cheapest option:

```
Option 1: Jane Doe (GAD)     - $32.50 × 8 (straight) = $260.00
Option 2: John Smith (OT)    - $45.00 × 8 (overtime) = $360.00
Option 3: Bob Jones (GAD)    - $28.00 × 8 (straight) = $224.00 ← CHEAPEST
Option 4: Mary Wilson (OT)   - $48.75 × 8 (overtime) = $390.00

Selected: Bob Jones ($224.00 straight time)
```

This actual calculation replaces the "company cover-all clause" mentioned in the contract.

---

## Usage Examples

### Example 1: Fill a Vacancy

```php
require_once 'includes/VacancyEngine.php';

$engine = new VacancyEngine();
$result = $engine->fillVacancy(42);

print_r($result);
/*
Array (
    [fill_id] => 123
    [dispatcher_id] => 456
    [dispatcher_name] => John Smith
    [option_rank] => 2
    [option_type] => incumbent_ot
    [pay_type] => overtime
    [hours] => 8
    [cost] => 360.00
    [decision_log] => Array (...)
)
*/
```

### Example 2: Assign Dispatcher to GAD

```php
require_once 'includes/GAD.php';

// Assign dispatcher #123 to GAD rest group C
GAD::assignToGAD(123, 'C');

// Check their rest days
$isRest = GAD::isRestDay(123, '2025-01-15');
// Returns true if 2025-01-15 is Tuesday or Wednesday (Group C rest days)
```

### Example 3: Assign ACD to Rotation

```php
require_once 'includes/ACD.php';

// Assign to GOLD crew, day shift, starting Monday
ACD::assignToACD(456, 'GOLD', 'day', '2025-01-20');

// Check if working on a specific date
$isWorking = ACD::isOnRotation(456, '2025-01-22');
// Returns true if within 4-day ON block
```

### Example 4: Check FRA Availability

```php
require_once 'includes/FRAHours.php';

// Can dispatcher #789 work first shift on 2025-01-15?
$available = FRAHours::isAvailableForShift(789, '2025-01-15', 'first');
// Returns true if they have 15+ hours rest

// Get availability status
$status = FRAHours::getAvailabilityStatus(789);
print_r($status);
/*
Array (
    [available] => false
    [status] => on_rest
    [message] => On required rest, available in 3.5 hours
    [available_at] => 2025-01-15 10:30:00
    [hours_until_available] => 3.5
)
*/
```

### Example 5: Calculate GAD Baseline

```php
require_once 'includes/GAD.php';

// Calculate baseline for Atlanta division (ID: 1)
$baseline = GAD::calculateBaseline(1);
print_r($baseline);
/*
Array (
    [division_id] => 1
    [total_desks] => 11
    [acd_desks] => 2
    [baseline_gad_count] => 11.0
    [current_gad_count] => 12
    [above_baseline] => true
    [at_baseline] => false
    [below_baseline] => false
)
*/

// Get diversion pay type
$payType = GAD::getDiversionPayType(1);
// Returns 'straight' because above baseline
```

---

## Next Steps

### Remaining Implementation Tasks

1. **UI Components** (pending)
   - GAD management interface
   - ACD rotation calendar
   - Vacancy cost breakdown display
   - Decision log viewer
   - Training status manager
   - Forcing tracker dashboard

2. **API Endpoints** (pending)
   - `api/gad.php` - GAD management
   - `api/acd.php` - ACD rotation
   - `api/training.php` - Training status
   - `api/pay_rates.php` - Dispatcher pay rates
   - `api/vacancy_fill.php` - Fill vacancy endpoint
   - `api/reports.php` - Cost reports, baseline reports

3. **Database Migration** (created, needs to be run)
   - Run `database/migrations/002_add_gad_acd_features.sql`
   - Populate initial GAD assignments
   - Set up initial pay rates
   - Configure ACD rotations

4. **Testing**
   - Unit tests for order-of-call logic
   - Integration tests for vacancy filling
   - GAD baseline calculations
   - FRA HOS enforcement
   - Cost calculation accuracy

5. **Documentation**
   - User manual
   - Admin guide
   - API documentation
   - Database schema docs

### Future Enhancements

- **Hold-Down Bidding System**
  - Seniority-based bidding
  - Bid period management
  - Automatic awarding

- **Forcing Analytics**
  - Track consecutive days by dispatcher
  - Alert at 14 days (approaching fatigue limits)
  - Historical forcing reports

- **Cost Analytics Dashboard**
  - Monthly OT costs by division
  - GAD baseline efficiency
  - Improper diversion penalties tracked
  - Actual vs projected costs

- **Mobile Interface**
  - Dispatcher self-service
  - View their schedule
  - View GAD rest days
  - Request time off

- **Notifications**
  - Email/SMS for forced assignments
  - GAD availability alerts
  - HOS violation warnings

---

## Technical Notes

### Performance Considerations

- **Database Indexes**: All foreign keys and frequently queried columns have indexes
- **Query Optimization**: GAD availability uses INNER JOINs instead of subqueries
- **Caching**: Consider caching GAD baseline calculations (changes infrequently)
- **Decision Logs**: JSON format allows flexible querying without schema changes

### Security

- **SQL Injection**: All queries use PDO prepared statements
- **Transaction Safety**: Vacancy fills use database transactions with rollback
- **Audit Trail**: Complete decision log for every vacancy fill
- **Data Integrity**: Foreign key constraints prevent orphaned records

### Scalability

- Current architecture supports:
  - 300+ dispatchers (user's scale)
  - 50+ desks across 6 divisions
  - Hundreds of vacancies per day
  - 10+ years of historical data

### Maintenance

- **Version Control**: Semantic versioning (MAJOR.MINOR.PATCH)
- **Database Migrations**: Sequential numbered migrations
- **Code Comments**: Inline contract article references
- **Decision Logs**: Self-documenting vacancy fill logic

---

## Contract References

This implementation is based on the Norfolk Southern Railway Company and American Train Dispatchers Association (ATDA) collective bargaining agreement.

**Key Articles:**
- Article 1(b): Definitions
- Article 2: Hours of Service
- Article 3(f): GAD Scheduling
- Article 3(g): Order of Call for Filling Vacancies
- Article 7(e): Off Assignment Pay
- Appendix 9: GAD Baseline Rules

**Critical Insight from User:**
> "Extra Board is checked FIRST" - This was the key correction that led to the GAD-first order-of-call implementation.

---

## Credits

Built for railroad dispatch operations with real-world contract compliance requirements.

**Version:** 1.4.0 (Build 20250114-010)
**Date:** January 14, 2025
