<?php

/**
 * Manufacturing (close-out 19/10, Lane 6) — specs, BOMs, production orders, QC.
 */
return [
    // ═══ Issue from the raw store ═══
    'qty_positive' => 'Quantity must be greater than zero',
    'raw_whole_units' => 'Quantity must be a whole number until raw material units land',
    'raw_short' => 'Not enough :product in stock — available :available',

    // ═══ Statuses ═══
    'spec_status_draft' => 'Draft',
    'spec_status_under_review' => 'Under Review',
    'spec_status_approved' => 'Approved',
    'spec_status_archived' => 'Archived',
    'bom_status_draft' => 'Draft',
    'bom_status_approved' => 'Approved',
    'bom_status_archived' => 'Archived',
    'order_status_planned' => 'Planned',
    'order_status_in_progress' => 'In Progress',
    'order_status_finished' => 'Finished',
    'order_status_closed' => 'Closed',
    'order_status_cancelled' => 'Cancelled',
    'qc_status_quarantine' => 'Quarantine',
    'qc_status_released' => 'Released',
    'qc_status_rejected' => 'Rejected',
    'issue_kind_issue' => 'Issue',
    'issue_kind_extra' => 'Extra Issue',
    'issue_kind_return' => 'Return to Store',

    // ═══ Screens ═══
    'orders_title' => 'Production Orders',
    'qc_title' => 'QC Release',
    'specs_title' => 'Product Specs',
    'col_number' => 'Order No.',
    'col_product' => 'Product',
    'col_line' => 'Line',
    'col_batch_no' => 'Batch No.',
    'col_planned_qty' => 'Planned Qty',
    'col_output_qty' => 'Output',
    'col_planned_on' => 'Planned On',
    'col_produced_on' => 'Produced On',
    'col_expires_on' => 'Expires',
    'col_status' => 'Status',
    'col_qc_status' => 'QC Status',
    'col_qc_at' => 'Decided At',
    'col_version' => 'Version',
    'col_shelf_life' => 'Shelf Life (days)',
    'col_net_weight' => 'Net Weight (g)',
    'col_updated_at' => 'Last Updated',
    'no_orders' => 'No production orders in this period',
    'no_qc' => 'No production batches in QC in this period',
    'no_specs' => 'No specs in this period',
    'all_statuses' => 'All statuses',
];
