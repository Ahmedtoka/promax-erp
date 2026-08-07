<?php

/**
 * Warehouse visits — rep check in / out (2026-08-08).
 */
return [
    'visit' => 'Warehouse visit',
    'visits' => 'Warehouse visits',
    'in_warehouse' => 'Inside :warehouse',
    'check_in' => 'Check in to the warehouse',
    'check_out' => 'Check out',
    'pick_warehouse' => 'Pick the warehouse you are in',
    'since' => 'since :time',
    'spent' => 'spent :mins',

    // Guard
    'required' => 'Check in to the warehouse first',
    'required_hint' => 'Receiving goods requires you to be standing in the warehouse. Check in and pick the warehouse, then you can receive every custody and supply order you have.',
    'need_attendance' => 'Start your shift before checking into a warehouse.',
    'not_inside' => 'You are not checked into any warehouse right now.',
    'inactive' => 'This warehouse is inactive.',
    'go' => 'Check in',

    // Tracking
    'event_in' => 'Entered :warehouse',
    'event_out' => 'Left :warehouse after :mins min',

    // ERP screen
    'board' => 'Warehouse visits',
    'who' => 'Employee',
    'warehouse' => 'Warehouse',
    'in_at' => 'In',
    'out_at' => 'Out',
    'duration' => 'Duration',
    'still_in' => 'Still inside',
    'auto_closed' => 'Auto-closed',
    'distance' => 'Distance from warehouse',
    'no_location' => 'No location',
    'metres' => ':n m',
    'received' => 'Received',
    'orders_countable' => ':count orders',
    'total_time' => 'Total time',
    'avg_visit' => 'Average visit',
    'people_inside' => 'Inside warehouses now',
    'no_visits' => 'No visits in this period',
];
