<?php

return [

    // ---------- Price change log (Lane 13) ----------
    'log_title' => 'Price change log',

    // What triggered the change
    'trigger_list' => 'Price list',
    'trigger_chain' => 'Chain',
    'trigger_branch' => 'Branch',
    'trigger_client' => 'Client',
    'trigger_invoice' => 'Single invoice',

    // Run status
    'status_draft' => 'Draft - awaiting confirmation',
    'status_applied' => 'Applied to documents',
    'status_master_only' => 'Saved for new sales only',
    'status_cancelled' => 'Cancelled',

    // Why a document was skipped (left untouched)
    'skip_eta_locked' => 'Exported or submitted to the tax authority',
    'skip_consignment' => 'Consignment invoice',
    'skip_has_return' => 'Has a linked return',
    'skip_not_priced' => 'Item without a price in the client list: :detail',
    'skip_closed_period' => 'In a closed accounting period: :detail',
    'skip_po_cancelled' => 'Cancelled purchase order',
    'skip_po_delivered' => 'Delivered purchase order - review manually',
    'skip_po_fixed_price' => 'Order with agreed fixed net prices',
    'skip_no_client' => 'Order without a client',

    // Messages
    'too_many' => 'This change would touch :n documents - more than the allowed limit. Narrow it with the "since" date.',
    'preview_stale' => 'The numbers changed since the preview - run a new preview before confirming.',
    'not_draft' => 'This change is not a draft - it was already confirmed or cancelled.',
    'nothing_changed' => 'No price changed - every entered price equals the stored one.',

    // ---------- Preview / confirm page (Task 4) ----------
    'preview_title' => 'Price change preview',
    'draft_note' => 'This is a draft - no price and no document has changed yet. Review the effect, then choose.',
    'applied_note' => 'Applied to prices and documents on :date.',
    'master_only_note' => 'Prices saved for new sales only on :date - existing documents are unchanged.',
    'cancelled_note' => 'This draft was cancelled - nothing changed.',
    'last_error' => 'The last confirm attempt failed and nothing was written: :msg',
    'apply_admin_only' => 'Applying to existing documents is admin only - you can save the prices for new sales only.',
    'draft_created' => 'A price change draft was created - review the effect and confirm.',
    'preview_refreshed' => 'A new preview was run.',
    'applied_ok' => 'Applied: :invoices invoices and :pos purchase orders changed, :skipped skipped.',
    'master_ok' => "Prices saved for new sales only - existing documents weren't touched.",
    'cancelled_ok' => 'Draft cancelled - nothing changed.',
    'client_saved_list_held' => 'Client details saved - the price list change is waiting for this preview.',

    'meta_by' => 'By',
    'meta_created' => 'Created',
    'meta_target' => 'On',
    'meta_scope' => 'Scope',
    'meta_since' => 'Documents since',
    'all_dates' => 'All dates',

    'scope_list' => 'Every client on the list',
    'scope_chain' => 'Every branch of the chain',
    'scope_branch' => 'This branch only',
    'scope_client' => 'This client',
    'scope_invoice' => 'One invoice',

    'branch_differs' => "This list differs from the chain's list (:list) - apply it to this branch only or to every branch of the chain (:n)?",
    'branch_only' => 'This branch only',
    'whole_chain' => 'Every branch of the chain (:n)',
    'since_label' => 'Documents since',
    'since_hint' => 'Empty = every document',
    'btn_refresh' => '🔄 Preview again',

    'k_clients' => 'Clients',
    'k_invoices' => 'Invoices changing',
    'k_pos' => 'Purchase orders changing',
    'k_unchanged' => 'No difference',
    'k_skipped' => 'Skipped',
    'k_ledger' => 'Client ledger effect',
    'ledger_hint' => "Invoice grand total difference - posted to the client's account (+ owed / - credit)",

    'totals_title' => 'Money effect',
    'col_docs' => 'Documents',
    'before' => 'Before',
    'after' => 'After',
    'diff' => 'Difference',
    'net' => 'Net',
    'tax' => 'Tax',
    'grand' => 'Total',
    'row_invoices' => 'Invoices',
    'row_pos' => 'Purchase orders',
    'row_all' => 'Total',

    'items_title' => 'What changed in the prices',
    'col_product' => 'Product',
    'col_old' => 'Old price',
    'col_new' => 'New price',
    'col_client' => 'Client',
    'col_old_list' => 'Old list',
    'col_new_list' => 'New list',
    'no_list' => '—',
    'no_items' => 'No list change - current pricing is applied to the documents only.',

    'docs_title' => 'Documents changing',
    'col_number' => 'Document',
    'col_type' => 'Type',
    'col_date' => 'Date',
    'col_payment' => 'Payment',
    'col_reason' => 'Reason',
    'type_invoice' => 'Invoice',
    'type_po' => 'Purchase order',
    'no_docs' => 'No document will change.',
    'more_docs' => '+:n more documents not shown here - the totals above include them.',
    'skipped_title' => "Skipped documents (won't change)",
    'no_skipped' => 'No skipped documents.',

    'btn_apply' => '✅ Confirm - apply to documents',
    'btn_master' => '💾 Save prices for new sales only',
    'btn_cancel' => '✖ Cancel',
    'confirm_apply' => ':inv invoices and :po purchase orders will change - total difference :diff. Are you sure?',
    'confirm_master' => "Prices will be saved for new sales only - existing documents won't be touched. Are you sure?",
    'confirm_cancel' => 'Cancel the draft? Nothing will change.',

    // Chain / client
    'chain_list' => "Chain's price list: :list (:n of :total branches)",
    'chain_list_contract' => 'From the chain contract',
    'chain_list_none' => 'The chain has no common price list',
    'btn_resync_chain' => "🏷 Apply current pricing to the branches' documents",
    'btn_resync_client' => "🏷 Apply current pricing to the client's documents",
    'confirm_resync' => 'A preview comes first - nothing changes before you confirm.',

    // Log (skeleton)
    'log_empty' => 'No price changes yet.',
    'col_run' => 'Change no.',
    'col_trigger' => 'Source',
    'col_status' => 'Status',
    'col_user' => 'By',
];
