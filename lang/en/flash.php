<?php

/**
 * Success / confirmation messages flashed back after a write action,
 * plus the ledger memos that are persisted with a transaction.
 */
return [

    // ---------- Clients ----------
    'client_saved' => 'Client details saved.',
    'client_added' => 'Client added.',

    // ---------- Contracts ----------
    'contract_saved' => 'Contract saved.',
    'contract_deleted' => 'Contract deleted.',

    // ---------- Products ----------
    'product_added' => 'Product added.',
    'product_updated' => 'Product updated.',

    // ---------- Channels ----------
    'channel_updated' => 'Channel updated.',
    'manager_channels_updated' => 'Channels updated for :name.',

    // ---------- Chains ----------
    'chain_created' => 'Chain created.',
    'chain_saved' => 'Chain saved.',
    'chain_deleted' => 'Chain deleted.',
    'chain_suspended' => 'The chain still has branches — it was suspended instead of deleted.',
    'branches_attached' => ':count branches attached to the chain.',
    'branches_detached' => ':count branches detached from the chain.',

    // ---------- Van stock ----------
    'van_loaded' => 'Van stock loaded.',
    'van_closed' => "Today's van stock is closed.",

    // ---------- Purchase orders ----------
    'po_created' => 'Purchase order created.',
    'po_sent_accounting' => 'Order sent to accounting - the pick order drops once approved.',
    'po_approved' => 'Order approved and the pick order went to the warehouse.',
    'po_updated' => 'Order updated and repriced - review and approve.',
    'pos_imported' => ':count orders created and queued for accounting approval.',
    'wiped_done' => ':n movement rows wiped - stock and balances zeroed, master data untouched.',
    'demo_loaded' => 'Demo data loaded - a receipt and a full custody for the rep.',
    'memo_po_partial' => 'PO :number delivered - :diff pcs short of the order',
    'po_rejected' => 'Order rejected and the channel manager was notified.',
    'po_assigned' => 'The order was assigned to the driver.',

    // ---------- Client requests ----------
    'decision_recorded' => 'Decision recorded.',

    // ---------- Collections ----------
    'collection_recorded' => 'Collection recorded.',

    // ---------- Replenishment ----------
    'replenishment_assigned' => 'The request became purchase order :number and was assigned to the rep.',
    'replenishment_cancelled' => 'Request cancelled.',

    // ---------- Ledger memos (persisted with the transaction) ----------
    'memo_invoice' => 'Invoice :number — :user',
    'memo_cash_with_invoice' => 'Cash collection with invoice :number',
    'memo_return' => 'Return of :count units — :user',
    'memo_return_refund' => 'Return value refunded in cash — cash client',
    'memo_po_delivered' => 'Purchase order :number delivered',
    'memo_cash_collection' => 'Cash collection',

    'clause_saved' => 'Clause saved and the contract rates were recalculated.',
    'clause_deleted' => 'Clause deleted and the contract rates were recalculated.',
    'memo_consignment' => 'Consignment :number — :amount at the client, not yet a receivable',
    'due_settled' => 'Posted :amount to :client — their balance was recalculated.',
    'due_waived' => 'The due was waived and will never be posted.',
    'dues_generated' => ':created new dues calculated across :contracts contracts.',
    'memo_rebate' => ':label — :period',
    'memo_opening' => 'Opening balance recorded on import',
    'opening_saved' => 'The opening balance was recorded.',
];
