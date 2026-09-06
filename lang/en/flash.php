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

    // ---------- Sales orders ----------
    'po_created' => 'Sales order created.',
    'po_sent_accounting' => 'Order sent to accounting - the pick order drops once approved.',
    'po_approved' => 'Order approved and the pick order went to the warehouse.',
    'po_updated' => 'Order updated and repriced - review and approve.',
    'pos_imported' => ':count orders created and queued for accounting approval.',
    'pos_bulk_approved' => ':count orders approved and their pick orders sent to the warehouse.',
    'wiped_done' => ':n movement rows wiped - stock and balances zeroed, master data untouched.',
    'demo_loaded' => 'Demo data loaded - a receipt and a full van stock for the rep.',
    'memo_po_partial' => 'Sales order :number delivered - :diff pcs short of the order',
    'po_rejected' => 'Order rejected and the channel manager was notified.',
    'po_assigned' => 'The order was assigned to the driver.',

    // ---------- Client requests ----------
    'decision_recorded' => 'Decision recorded.',

    // ---------- Collections ----------
    'collection_recorded' => 'Collection recorded.',

    // ---------- Replenishment ----------
    'replenishment_assigned' => 'The request became sales order :number and was assigned to the rep.',
    // 15 Aug flow: approval raises a picking order - no sales order, no ledger entries
    'replenishment_picked' => 'Request approved and sent to the warehouse as picking order :number.',
    // Reversing a sales order that duplicated an invoice - 15 August correction
    'memo_po_dupe_reversed' => 'Reversal of :kind for order :number - duplicated by an invoice for the same goods',
    'replenishment_cancelled' => 'Request cancelled.',

    // ---------- Ledger memos (persisted with the transaction) ----------
    'memo_invoice' => 'Invoice :number — :user',
    'memo_cash_with_invoice' => 'Cash collection with invoice :number',
    'memo_return' => 'Return of :count units — :user',
    'memo_return_refund' => 'Return value refunded in cash — cash client',
    'memo_po_delivered' => 'Sales order :number delivered',
    'memo_return_doc' => 'Return :number — :count pcs (:user)',
    'memo_po_cash' => 'Cash collected on delivery of sales order :number',
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

    // ═══ أوبشنات الزيارة الجديدة (2026-08-09) ═══
    'memo_field_collection' => 'Field collection during a visit',
    'po_back_to_accounting' => 'Edit saved - the order went back to the accounting approval queue and the old pick order was cancelled.',

    // ═══ Custody correction + replenishment edit (Aug 12, 2026) ═══
    'custody_adjusted' => 'Van stock corrected as a record-only adjustment - warehouse stock was not touched, and the rep was notified.',
    'replenishment_updated' => 'The request was updated and the requester was notified.',

    // Sales order cancellation (Aug 21)
    'po_cancelled' => 'Order cancelled and its pick voided — gathered goods returned to the shelf.',
    'po_cancelled_wh' => 'Order cancelled and a transfer document created — goods returned to the warehouse by batch.',
    'po_cancelled_custody' => 'Order cancelled — goods stay in the rep\'s van stock to sell.',

    // Manual document (Aug 21)
    'md_invoice_done' => 'Invoice :number saved for :amount — deducted from the rep\'s van stock and posted to the client.',
    'md_return_done' => 'Return :number saved — back into the rep\'s van stock and credited to the client.',
    'md_gift_done' => 'Gift saved and deducted from the rep\'s gift balance.',
];
