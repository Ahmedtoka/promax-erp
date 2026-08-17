<?php

/** Mobile API: authentication, permissions, and state errors returned to the app. */
return [

    // ---------- Authentication ----------
    'token_missing' => 'Authentication token is missing.',
    'token_invalid' => 'Your session has expired — please sign in again.',
    'account_blocked' => "Your app access has been suspended. Please contact management.",
    'not_signed_in' => 'You are not signed in.',
    'bad_credentials' => 'Wrong login or password.',
    'no_app_access' => 'This account has no access to the app.',
    'signed_out' => 'Signed out.',

    // ---------- Permissions ----------
    'forbidden' => 'You are not allowed to do this.',
    'not_your_visit' => 'This visit is not yours.',
    'return_needs_open_visit' => 'Returns must be recorded from an open visit on the same client.',
    // ═══ Relationship anchors (audit 2026-08-08) ═══
    'not_your_client' => 'This client is not assigned to you and is not in your zone.',
    'invoice_needs_open_visit' => 'Invoices must be recorded from an open visit on the same client.',
    'return_policy_unknown' => 'Unknown return method.',
    'return_policy_not_allowed' => 'This method is not allowed for this client.',
    'return_condition_unknown' => 'Item condition must be sound or damaged.',
    'return_no_items' => 'No items on the return.',
    'return_over_purchased' => 'Quantity exceeds what the client purchased of :product — available to return: :qty',
    'return_qty_cap' => 'Quantity of :product exceeds the return cap (:max pieces per product).',
    'return_exceeds_purchases' => 'The return value exceeds what this client has purchased. Available to return: :amount',
    'return_consignment' => 'Consignment client — returns are settled from the branch sales report, not here.',
    'qty_too_large' => 'Quantity exceeds the allowed limit — check the unit and the number.',
    'po_over_delivery' => 'You cannot deliver more than the order asks - extras go on a regular invoice.',
    'po_item_not_in_order' => 'This product is not part of the sales order.',
    'po_nothing_delivered' => 'Nothing was delivered - if the branch refused the whole delivery, tell management.',
    'order_not_yours' => 'This sales order is not assigned to you.',
    'not_your_channel' => 'This request belongs to a channel you do not manage.',

    // ---------- State ----------
    'order_not_pending' => 'This sales order is not pending.',
    'request_already_decided' => 'This request has already been decided.',
    'request_already_assigned' => 'This request has already been assigned.',
    'unknown_price_mode' => 'Unknown price mode.',

    'product_not_priced' => '":product" has no price in this client\'s price list — set it on the price-lists screen first.',
    'returns_closed' => 'Returns intake is currently closed by management - contact accounting.',
    'bad_request' => 'Request not understood - update the app and try again.',
    // ═══ Draft product - 17 Aug 2026 ═══
    'product_missing' => 'That item does not exist.',
    'product_draft' => '":product" is a draft item and cannot be sold - remove it from the request, or activate it from the products screen first.',
];
