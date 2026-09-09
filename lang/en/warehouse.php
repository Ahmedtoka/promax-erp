<?php

/**
 * Warehouse visits — rep check in / out (2026-08-08).
 */
return [

    // Guard
    'required' => 'Check in to the warehouse first',
    'required_hint' => 'Receiving goods requires you to be standing in the warehouse. Check in and pick the warehouse, then you can receive every van stock and sales order you have.',
    'need_attendance' => 'Start your shift before checking into a warehouse.',
    'not_inside' => 'You are not checked into any warehouse right now.',
    'inactive' => 'This warehouse is inactive.',

    // Tracking
    'event_in' => 'Entered :warehouse',
    'event_out' => 'Left :warehouse after :mins min',

    // ERP screen
];
