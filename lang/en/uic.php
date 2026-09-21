<?php

// Field-operations screens — UI review strings (22/9)
return [
    // plural nouns for the "All …" filter option
    'pay_kinds' => 'payment types',
    'decisions' => 'decisions',
    'statuses' => 'statuses',
    'products' => 'products',
    'policies' => 'policies',
    'conditions' => 'conditions',
    'managers' => 'managers',
    'outcomes' => 'outcomes',
    'sources' => 'sources',
    'types' => 'types',

    // card explanations
    'inv_explain_title' => 'Invoice figures explained — cash / credit',
    'inv_explain_hint' => 'The applied filter split by payment. Click a row to filter by it.',
    'ret_explain_title' => 'Returns value explained — by policy',
    'explain_row_hint' => 'These rows add up to the card figure. Click a row to filter by it.',
    'sum_of_col' => 'Sum of the ":col" column below',
    'of_n_reps' => 'of :n reps',

    // sales orders
    'po_scope_note' => 'Total of :n orders — the whole filtered result',
    'range_is_due' => 'The period here filters on the delivery due date, not the creation date.',

    // vans board
    'extra_open_title' => 'Older van stock still open and not shown on the board',
    'extra_open_units' => ':n units remaining',
    'extra_open_hint' => 'The board shows only the current van stock per rep. These older ones are counted in the "Stock on the street" card on the home page — close them from the rep page so both figures match.',
    // sales orders — one base for the value
    'po_orders_value' => 'Orders value',
    'po_excluded_note' => ':n rejected/cancelled orders are listed struck through and left out of the total.',
    'po_excluded_tip' => 'Rejected or cancelled — not in the total',

    // home dashboard
    'no_channel' => 'No channel',
    'eq_other' => 'Other entries (cash refunds / adjustments)',

    // rep page and reps board
    'board_field_colls' => 'Field collections',
    'docs_vs_ledger' => 'By documents: :v',
    'docs_vs_ledger_hint' => 'The card comes from the ledger (entry date, value actually delivered). The tables below go by document date and full document total, so their sum can differ.',

    // export file columns
    'x_inv_value' => 'Invoices value',
    'x_no_photo_reason' => 'Reason closed without photos',
    'x_approver' => 'Approved by',
];
