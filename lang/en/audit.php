<?php

return [

    // === Account review (Aug 28, 2026) ===
    'chains_title' => 'Chain account review',
    'clients_title' => 'Client account review',
    'report_title' => 'Review report',
    'report_hint' => 'Every box answers one question - the question itself is printed under the number. This report counts situations, not money; balances belong to the statement of account.',
    'branches_n' => ':n branches',

    // === Summary boxes - title plus the question it answers ===
    'k_chains' => 'Total chains',
    'k_clients' => 'Total clients',
    'k_total_short' => 'Total',
    'k_total_hint' => 'Standalone only - chain branches are reviewed on the chains page',

    'k_pending' => 'Not reviewed yet',
    'k_pending_hint' => 'No yes/no answer recorded yet',

    'k_has_account' => 'Account exists',
    'k_has_account_hint' => 'They said yes - an account is open on their side',
    'k_no_account' => 'No account',
    'k_no_account_hint' => 'No account at all - the chain of questions stops here',

    'k_has_statement' => 'Has a statement',
    'k_has_statement_hint' => 'Of those with an account: a detailed statement exists',
    'k_no_statement' => 'No statement',
    'k_no_statement_hint' => 'Has an account but no detailed statement - this is the work',
    'k_files' => 'Statements uploaded',
    'k_files_hint' => 'The statement file is actually stored on the system',

    'k_has_receipt' => 'Has a signed receipt',
    'k_has_receipt_hint' => 'Of those with a statement: the signed hard copy exists',
    'k_no_receipt' => 'No signed receipt',
    'k_no_receipt_hint' => 'Has a statement but no signed receipt',

    'k_full' => 'Fully in order',
    'k_full_hint' => 'Account + detailed statement + signed receipt - paperwork complete',

    'k_billed' => 'Tax invoice issued',
    'k_billed_hint' => 'Billed - the official invoice was issued',
    'k_unbilled' => 'No tax invoice',
    'k_unbilled_hint' => 'Unbilled - still needs a tax invoice',
    'k_billing_pending' => 'Billing not answered',
    'k_billing_pending_hint' => 'No record of whether it was issued',
    'k_ready' => 'Ready to bill',
    'k_ready_hint' => 'Paperwork complete and still unbilled - these are the priority',

    'k_confirmed' => 'Client confirmed',
    'k_confirmed_hint' => 'The channel manager visited and confirmed the account is right',

    // Report groups
    'g_coverage' => '1) Accounts - who has one and who does not',
    'g_papers' => '2) Paperwork - statement and signed receipt',
    'g_tax' => '3) Tax invoice',
    'g_done' => '4) Bottom line',
    'reviewed_n' => ':n of :t reviewed',
    'open_list' => 'Open the list',
    'progress_label' => 'Fully in order',

    // === Filters ===
    'f_all' => 'All',
    'f_pending' => 'Not reviewed',
    'f_no_account' => 'No account',
    'f_no_statement' => 'No statement',
    'f_no_receipt' => 'No receipt',
    'f_full' => 'Fully in order',
    'f_ready' => 'Ready to bill',
    'f_unbilled' => 'Unbilled',
    'search_ph' => 'Name or code…',

    // === Table ===
    'c_chain' => 'Chain',
    'c_client' => 'Client',
    'c_has_account' => 'Account exists?',
    'c_their' => 'Account amount',
    'c_has_statement' => 'Statement in hand?',
    'c_file' => 'Upload statement',
    'c_has_receipt' => 'Signed receipt?',
    'c_tax' => 'Tax invoice?',
    'c_ours' => 'Ours (reference)',
    'c_confirm' => 'Confirm',
    'c_sort' => 'Order',
    'sort_hint' => 'Type 1, 2, 3 in the order you want and hit "Save all" below - the order is stored and stays. Leave a box empty to keep that row in its default position.',

    'yes' => 'Yes',
    'no' => 'No',
    'billed' => 'Billed',
    'unbilled' => 'Unbilled',
    'empty' => 'No rows for this filter.',
    'save_all' => 'Save all',
    'save_hint' => 'The 💾 button saves that row only · uploading a statement sets "statement in hand" to Yes · "no account" locks the questions after it.',
    'saved' => ':n rows saved ✓',
    'remove_confirm' => 'Remove the uploaded statement?',
    'statement_removed' => 'Statement removed - the row is now "no statement".',
    'confirm_hint' => 'Tap when you visit the client and confirm the account is right',
    'confirmed_ok' => 'Account confirmed as correct ✓',
    'unconfirmed_ok' => 'Confirmation removed.',

];
