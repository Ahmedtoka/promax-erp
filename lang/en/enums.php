<?php

/**
 * Labels for coded values (roles, statuses, categories, families).
 * Keys MUST match the constant keys in the models exactly.
 * Terminology is fixed by the promax-i18n skill — do not improvise.
 */
return [

    'role' => [
        'admin' => 'Admin',
        'manager' => 'Channel Manager',
        'branch_manager' => 'Branch manager',
        'accountant' => 'Accountant',
        'warehouse_keeper' => 'Warehouse Keeper',
        'sales_agent' => 'Sales Agent',
        'driver' => 'Delivery Driver',
        'promoter' => 'Merchandiser',
    ],

    'channel' => [
        'key_account' => 'Key Account',
        'online' => 'Online',
        'cash_van' => 'Cash Van',
        'wholesale' => 'Wholesale',
    ],

    'sub_channel' => [
        'chain' => 'Hypermarkets & Supermarkets',
        'convenience' => 'Convenience & Fuel Stations',
    ],

    'category' => [
        'danger' => '🔴 Collect Now',
        'watch' => '🟠 Watch Closely',
        'grow' => '🟢 Grow',
        'ok' => '✅ Healthy',
        'idle' => '⚪ Dormant',
        'internal' => '🚚 Internal Channel',
        'credit' => '🔵 In Credit',
    ],

    'client_status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'blocked' => 'Blocked',
    ],

    'family' => [
        'promax_bar' => 'PROMAX Bar',
        'promax_cup' => 'PROMAX Cup',
        'spreads' => 'PRO Spreads',
        'pmx_bar' => 'PMX Bar',
        'energy_bar' => 'Energy Bar',
    ],

    'po_approval' => [
        'pending' => 'Awaiting accounting',
        'approved' => 'Approved by accounting',
        'rejected' => 'Rejected',
    ],

    'po_status' => [
        'pending' => 'Pending',
        'arrived' => 'Arrived',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ],

    'request_status' => [
        'pending' => 'Awaiting Approval',
        'review' => 'Under Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'replenishment_status' => [
        'pending' => 'Awaiting Dispatch',
        'assigned' => 'Assigned',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ],

    'payment' => [
        'cash' => 'Cash',
        'credit' => 'Credit',
    ],

    'transaction' => [
        'sale' => 'Sale',
        'collection' => 'Collection',
        'return' => 'Return',
        'rebate' => 'Trade Discount',
        'settlement' => 'Settlement',
        'opening' => 'Opening Balance',
        'transfer' => 'Transfer Entry',
        'taxded' => 'Withheld Tax',
        'consignment' => 'Consignment Stock',
        'refund' => 'Return Cash Refund',
    ],

    'price_mode' => [
        'client' => "The client's own price list and discount",
        'channel' => "Client's Channel Price",
        'old' => 'Old Price List',
        'new' => 'New Price List',
    ],

    'track' => [
        'start' => 'Van stock received',
        'open' => 'App opened',
        'check_in' => 'Checked in',
        'check_out' => 'Checked out',
        'sale' => 'Sale',
        'return' => 'Return',
        'gift' => 'Gift',
        'deliver' => 'Sales order delivered',
        'refill' => 'Shelf Refill',
        'request' => 'New client request',
        'shift_in' => 'Shift start',
        'shift_break' => 'Break',
        'shift_back' => 'Back from break',
        'shift_out' => 'Shift end',
        'wh_in' => 'Entered warehouse',
        'wh_out' => 'Left warehouse',
        'collect' => 'Collected money',
        'shelf' => 'Shelf arrangement',
        'po_abort' => 'Returned an order undelivered',
        'custody_adjust' => 'Van stock admin correction',
    ],

];
