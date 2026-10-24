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
];
