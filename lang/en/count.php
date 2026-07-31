<?php

return [

    'page' => 'Stock counts',
    'page_sub' => 'Count the goods and settle the differences',
    'count' => 'Stock count',
    'counts' => 'Stock counts',
    'new_count' => 'New count',
    'open_count' => 'Open count',
    'sheet' => 'Count sheet',

    // ═══ Statuses ═══
    'status_draft' => 'Being prepared',
    'status_counting' => 'Counting',
    'status_approved' => 'Approved',
    'status_cancelled' => 'Cancelled',

    // ═══ Columns ═══
    'expected' => 'System qty',
    'expected_hint' => 'When the count was opened',
    'system_now' => 'Qty now',
    'counted' => 'Counted',
    'difference' => 'Difference',
    'value_diff' => 'Value difference',
    'reason' => 'Reason',
    'no_batch' => 'No batch',
    'not_counted' => 'Not counted yet',
    'lines' => 'Lines',
    'diff_lines' => 'Lines with differences',
    'qty_diff' => 'Quantity difference',
    'pending_lines' => 'Lines remaining',
    'moved_hint' => 'This stock moved while counting was in progress — the difference is not a shortage',

    // ═══ Difference reasons ═══
    'reason_damage' => 'Damaged',
    'reason_expiry' => 'Expired',
    'reason_theft' => 'Lost or stolen',
    'reason_entry_error' => 'Entry error',
    'reason_found' => 'Extra stock found',
    'reason_other' => 'Other reason',

    // ═══ Buttons ═══
    'save_counts' => 'Save counts',
    'approve' => 'Approve count',
    'cancel_count' => 'Cancel count',
    'include_zero' => 'Include empty batches',
    'include_zero_hint' => 'So you can record stock found that was never registered',
    'count_date' => 'Count date',
    'warehouse' => 'Warehouse',

    // ═══ Messages ═══
    'opened' => 'Count :number opened with :lines lines',
    'saved' => ':count lines saved',
    'approved' => 'Count approved: :lines lines differed, worth :value',
    'cancelled' => 'Count cancelled',
    'already_open' => 'There is already an open count on this warehouse (:number). Approve or cancel it first.',
    'not_open' => 'This count is not open — it cannot be changed',
    'nothing_counted' => 'Not a single line has been counted. Enter the numbers first.',
    'warehouse_empty' => 'This warehouse has no batches with stock. Try including empty batches.',
    'no_counts' => 'No counts yet',
    'approve_confirm' => 'Approving will actually change stock levels and cannot be undone. Are you sure?',
    'cancel_confirm' => 'The whole count will be cancelled and the counted numbers lost. Are you sure?',
    'pending_warning' => ':count lines have not been counted. Approving leaves them untouched.',
    'moved_warning' => 'Some stock moved since you opened this count. Check the "Qty now" column.',
    'approved_note' => 'This count was approved and stock levels were updated. The data below is read-only.',
    'open_now' => ':count counts are currently open',
];
