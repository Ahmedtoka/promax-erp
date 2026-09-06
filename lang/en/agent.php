<?php

/**
 * Promax Assistant — smart chat strings (2026-09-07).
 */
return [

    // UI
    'title' => 'Promax Assistant',
    'subtitle' => 'Accounting · Sales · Inventory · Field',
    'empty_hint' => 'Ask me about accounting, sales, inventory or the field team — or tap a suggestion below. I can also prepare a collection entry for you to confirm.',
    'placeholder' => 'Type your question…',
    'send' => 'Send',
    'thinking' => 'Working on it…',
    'new_chat' => 'New Chat',
    'close' => 'Close',
    'open_btn_label' => 'Open Promax Assistant',

    // "Open in system" buttons
    'open_client' => 'Open Client Card',
    'open_dashboard' => 'Open Dashboard',
    'open_chain' => 'Open Chain Page',
    'd_chain' => 'Chain Summary — :name (:n branches)',
    'd_chain_footer' => 'Balance EGP :balance · Purchases :purchases · Collections :collections · Sales Today :today',
    'c_branch' => 'Branch',

    // Data blocks
    'd_statement' => 'Statement of Account — :name',
    'd_stmt_footer' => 'Showing :shown of :total entries — Debit :debit · Credit :credit',
    'd_aging' => 'Receivables Aging — All Channels',
    'd_aging_ch' => 'Receivables Aging — :channel Channel',
    'd_aging_footer' => 'Total EGP :total across :clients clients',
    'd_candidates' => 'Found more than one client — pick one',
    'c_memo' => 'Memo',
    'c_kind' => 'Kind',
    'c_debit' => 'Debit',
    'c_credit' => 'Credit',
    'c_balance' => 'Balance',
    'c_purchases' => 'Purchases',
    'c_collections' => 'Collections',
    'c_returns' => 'Returns',
    'money' => 'EGP :n',
    'egp' => 'EGP',

    // Errors
    'err_timeout' => 'The assistant took too long — try again, and tell the admin if it keeps happening.',
    'err_api' => 'The assistant cannot answer right now — try again in a bit.',
    'err_generic' => 'Something unexpected went wrong — try again, and tell the admin if it keeps happening.',
    'err_not_configured' => 'The assistant is not set up yet — the admin needs to add the API key.',
    'empty_reply' => "I couldn't come up with an answer — try rephrasing your question.",

    // Suggestion chips + mic (Sep 7)
    'chip_expiry' => 'Which batches expire soon?',
    'chip_aging' => 'Show receivables aging',
    'chip_sales' => 'How much did we sell today?',
    'chip_attendance' => 'Who checked in today?',
    'mic_label' => 'Speak instead of typing',

    // Sales blocks
    'd_sales' => 'Sales Summary — :range',
    'c_invoices' => 'Invoices',
    'c_cash' => 'Cash',
    'c_credit_pay' => 'Credit',
    'c_pos' => 'Delivered Sales Orders',
    'c_total_sales' => 'Total Sales',
    'd_top_products' => 'Top Products — :range',
    'c_product' => 'Item',
    'c_qty' => 'Qty',
    'c_value' => 'Value',

    // Inventory blocks
    'd_prod_candidates' => 'Found more than one item — specify by code',
    'd_stock' => 'Stock — :name',
    'c_warehouse' => 'Warehouse',
    'c_good' => 'Good',
    'c_hold' => 'Hold',
    'd_stock_footer' => 'Total :total pcs',
    'd_expiring' => 'Batches expiring within :days days',
    'c_days' => 'Days',
    'd_van' => 'Van Stock — :rep',
    'c_loaded' => 'Loaded',
    'c_sold' => 'Sold',
    'c_remaining' => 'Remaining',
    'd_van_footer' => 'Total remaining in van :total pcs',

    // Field blocks
    'd_attendance' => 'Team Activity — :date',
    'c_rep' => 'Rep',
    'c_in' => 'Check-in',
    'c_out' => 'Check-out',
    'd_attendance_footer' => ':in of :team moved — not yet: :absent',
    'd_activity' => ':rep Activity — :range',
    'c_visits' => 'Visits',
    'c_done' => 'closed',
    'c_field_coll' => 'Field Collections',
    'd_rep_candidates' => 'Found more than one rep — pick one',
    'open_stock' => 'Open Product Catalogue',
    'open_expiry' => 'Open Expiry Report',
    'open_rep' => 'Open Rep Page',
    'open_attendance' => 'Open Attendance',

    // Actions with approval (phase 2)
    'act_collect_title' => 'Confirm Manual Collection',
    'act_confirm' => 'Confirm & Record',
    'act_cancel' => 'Cancel',
    'act_warn' => 'Nothing is recorded until you press Confirm — a real ledger entry is created and the client balance updates.',
    'act_already' => 'This action was already closed — ask the assistant to prepare it again if needed.',
    'act_cancelled' => 'Cancelled — nothing was recorded.',
    'act_failed' => 'Execution failed — nothing was recorded. Try the original screen and tell the admin.',

    // Review screen (admin)
    'runs_title' => 'Promax Assistant Review',
    'runs_hint' => 'Every question and its cost — refusals map what people want next',
    'runs_empty' => 'No runs yet',
    'r_total' => 'Runs',
    'r_tokens' => 'Tokens',
    'r_cost' => 'Approx. Cost',
    'r_cost_note' => 'Per config prices',
    'r_refused' => 'Refused (Out of Scope)',
    'r_refused_note' => 'These are the next feature requests',
    'r_failed' => 'Failed',
    'r_avg' => 'Avg. Response',
    'r_user' => 'User',
    'r_message' => 'Question & Reply',
    'r_domain' => 'Domain',
    'r_tools' => 'Tools',
    'st_ok' => 'OK',
    'st_refused' => 'Refused',
    'st_failed' => 'Failed',
];
