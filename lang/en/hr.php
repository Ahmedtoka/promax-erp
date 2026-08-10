<?php

// Attendance - the first HR module (2026-08-08)

return [

    'attendance' => 'Attendance',
    'attendance_hint' => 'Who is working right now · hours per employee',
    'today_board' => 'Today Board',
    'log' => 'Attendance Log',
    'review' => 'Needs Review',

    // -- Punches --
    'punch_in' => 'Check in',
    'punch_break' => 'Break',
    'punch_back' => 'Back from break',
    'punch_out' => 'Check out',

    // -- States --
    'state_working' => 'Working',
    'state_break' => 'On break',
    'state_off' => 'Not working',
    'status_open' => 'Open',
    'status_closed' => 'Closed by employee',
    'status_auto' => 'Auto closed',

    // -- Blocking --
    'blocked_off' => 'You must check in before you start working.',
    'blocked_break' => 'You are on break - tap "Back" to continue working.',
    'bad_punch' => 'Unknown punch type.',
    'bad_state_in' => 'You are already checked in.',
    'bad_state_break' => 'You must be working to take a break.',
    'bad_state_back' => 'You are not on a break.',
    'bad_state_out' => 'You have not checked in yet.',

    // -- Columns --
    'employee' => 'Employee',
    'role' => 'Role',
    'first_in' => 'First in',
    'last_out' => 'Last out',
    'worked' => 'Hours worked',
    'breaks' => 'Break',
    'sessions' => 'Check-ins',
    'state' => 'State',
    'date' => 'Date',
    'from' => 'From',
    'to' => 'To',
    'worked_so_far' => 'Worked :t so far',
    'hours_short' => 'h',

    // -- Summary --
    'working_now' => 'Working now',
    'on_break_now' => 'On break',
    'not_in_yet' => 'Not checked in',
    'done_today' => 'Finished',
    'total_hours' => 'Total hours',
    'avg_hours' => 'Average hours',
    'no_rows' => 'No attendance in this period',

    // -- Approval --
    'approve' => 'Approve',
    'approve_title' => 'Approve hours for :name',
    'approve_hint' => 'The system auto-closed this shift because the employee forgot to check out. Review the hours and adjust if needed.',
    'computed' => 'Computed from the log',
    'approved_minutes' => 'Approved hours',
    'approved_hint' => 'Format hours:minutes - e.g. 7:30',
    'approve_note' => 'Note',
    'approved' => 'Approved',
    'approved_by' => 'Approved by :name',
    'not_approved' => 'Pending',
    'saved' => 'Approval saved',
    'bad_time' => 'Use hours:minutes format - e.g. 7:30',
    'review_count' => ':n shifts need review',
    'no_review' => 'No shifts need review',

    // -- Notification --
    'notif_forgot_title' => 'You forgot to check out',
    'notif_forgot_body' => 'Shift :date was auto-closed at :t - your manager will review it.',

    // -- Timeline --
    'timeline' => 'Today timeline',
    'auto_closed_mark' => 'Auto closed',
    'punches_count' => ':n punches',


    // ═══ بورد الأونلاين + المجموعة المستقلة (2026-08-09) ═══
    'online' => 'Online',
    'online_now' => 'Online Now',
    'in_since' => 'In since',
    'working_for' => 'Working for',
    'nobody_online' => 'Nobody is clocked in right now.',
    'auto_refresh' => 'Auto-refreshes every minute',
    'all_team' => 'All Team',
    'export_excel' => 'Export to Excel',

    // Reverse attendance guard (Aug 9, 2026): open work blocks checkout
    'block_out_intro' => 'You cannot check out while work is still open:',
    'block_out_visit' => 'Open visit at ":client" - check out of it first',
    'block_out_merch' => 'Open shelf visit at ":client" - close it with the after photo first',
    'block_out_po' => 'Sales Order :number is mid-delivery - finish the delivery first',
    'block_out_custody' => 'Your van stock is still open - return the van and have your manager close it on the dashboard',
];
