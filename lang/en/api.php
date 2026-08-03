<?php

/** Mobile API: authentication, permissions, and state errors returned to the app. */
return [

    // ---------- Authentication ----------
    'token_missing' => 'Authentication token is missing.',
    'token_invalid' => 'Your session has expired — please sign in again.',
    'not_signed_in' => 'You are not signed in.',
    'bad_credentials' => 'Wrong login or password.',
    'no_app_access' => 'This account has no access to the app.',
    'signed_out' => 'Signed out.',

    // ---------- Permissions ----------
    'forbidden' => 'You are not allowed to do this.',
    'not_your_visit' => 'This visit is not yours.',
    'return_needs_open_visit' => 'Returns must be recorded from an open visit on the same client.',
    'return_consignment' => 'Consignment client — returns are settled from the branch sales report, not here.',
    'order_not_yours' => 'This purchase order is not assigned to you.',
    'not_your_channel' => 'This request belongs to a channel you do not manage.',

    // ---------- State ----------
    'order_not_pending' => 'This purchase order is not pending.',
    'request_already_decided' => 'This request has already been decided.',
    'request_already_assigned' => 'This request has already been assigned.',
    'unknown_price_mode' => 'Unknown price mode.',

    'product_not_priced' => '":product" has no price in this client\'s price list — set it on the price-lists screen first.',
];
