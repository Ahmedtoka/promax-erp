<?php

/**
 * Fleet — vehicles, odometers and driver assignments.
 */
return [

    'fleet' => 'Fleet',
    'vehicle' => 'Vehicle',
    'vehicles' => 'Vehicles',
    'plate' => 'Plate number',
    'kind' => 'Vehicle type',
    'model_year' => 'Model year',
    'fridge' => 'Refrigerated',
    'km' => 'km',

    // --- Odometer ---
    'odometer' => 'Odometer',
    'odometer_now' => 'Current reading',
    'odometer_at' => 'Last read',
    'record_reading' => 'Record a reading',
    'reading_start' => 'Start of day',
    'reading_end' => 'End of day',
    'reading_manual' => 'Manual entry',
    'readings' => 'Odometer readings',
    'distance' => 'Distance',
    'distance_month' => 'Kilometres this month',
    'no_reading' => 'No reading',

    // --- Assignment ---
    'assignment' => 'Assignment',
    'assignments' => 'Assignment history',
    'assign_driver' => 'Assign a driver',
    'current_driver' => 'Current driver',
    'from_date' => 'From',
    'to_date' => 'Until',
    'still_open' => 'Still active',
    'no_driver' => 'No driver',
    'unassign' => 'Unassign',
    'assigned_ok' => 'Assigned.',
    'reading_ok' => 'Reading recorded.',

    // --- Errors ---
    'odometer_went_back' => 'The odometer reads :now — you cannot record :new, which is lower. If the odometer was actually replaced, note that on the vehicle first.',
    'odometer_jump' => 'A jump of :diff km in a single reading is too large — it looks like a typo. Check the number.',
    'not_a_driver' => 'A vehicle can only be assigned to a driver or a sales agent.',
    'bad_reading_kind' => 'Unknown reading type.',
];
