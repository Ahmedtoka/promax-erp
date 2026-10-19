<?php

// Costing (Lane 9) - grows with each task
return [
    'title' => 'Costing',
    'method_batch' => 'Batch cost (default)',
    'method_wavg' => 'Weighted average',
    'alloc_sales' => 'By net sales',
    'alloc_qty' => 'By quantity',
    'flag_zero_cost' => 'Zero cost',
    'flag_no_transfer_price' => 'No transfer price',
    'tp_price_required' => 'Enter a transfer price greater than zero.',
    'seed_empty_tree' => 'The chart of accounts is empty - set up the GL tree first, then try again.',
    'flag_no_hours' => 'No line hours',
    'flag_no_history' => 'No rate history',
    'flag_no_rate' => 'No default kg rate',
    'flag_no_weight' => 'No weight',
    'oh_method_hours_estimate' => 'Hours x estimated rate',
    'oh_method_kg_default' => 'Kg x default rate',
    'month_not_past' => 'Only a finished month can be closed for costing.',
    'month_closed' => 'This costing month is already closed.',
    'alert_near_title' => 'Order :number cost is near the transfer price',
    'alert_above_title' => 'Order :number cost is above the transfer price',
    'alert_body' => ':product - unit cost :cost, transfer price :price (order :number).',
];
