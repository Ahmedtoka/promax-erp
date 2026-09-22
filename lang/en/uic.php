<?php

// Field-operations screens — UI review strings (22/9)
return [
    // plural nouns for the "All …" filter option
    'pay_kinds' => 'payment types',
    'decisions' => 'decisions',
    'statuses' => 'statuses',
    'products' => 'products',
    'policies' => 'policies',
    'conditions' => 'conditions',
    'managers' => 'managers',
    'outcomes' => 'outcomes',
    'sources' => 'sources',
    'types' => 'types',

    // card explanations
    'inv_explain_title' => 'Invoice figures explained — cash / credit',
    'inv_explain_hint' => 'The applied filter split by payment. Click a row to filter by it.',
    'ret_explain_title' => 'Returns value explained — by policy',
    'explain_row_hint' => 'These rows add up to the card figure. Click a row to filter by it.',
    'sum_of_col' => 'Sum of the ":col" column below',
    'of_n_reps' => 'of :n reps',

    // sales orders
    'po_scope_note' => 'Total of :n orders — the whole filtered result',
    'range_is_due' => 'The period here filters on the delivery due date, not the creation date.',

    // vans board
    'extra_open_title' => 'Older van stock still open and not shown on the board',
    'extra_open_units' => ':n units remaining',
    'extra_open_hint' => 'The board shows only the current van stock per rep. These older ones are counted in the "Stock on the street" card on the home page — close them from the rep page so both figures match.',
    // sales orders — one base for the value
    'po_orders_value' => 'Orders value',
    'po_excluded_note' => ':n rejected/cancelled orders are listed struck through and left out of the total.',
    'po_excluded_tip' => 'Rejected or cancelled — not in the total',

    // home dashboard
    'no_channel' => 'No channel',
    'eq_other' => 'Other entries (cash refunds / adjustments)',

    // rep page and reps board
    'board_field_colls' => 'Field collections',
    'docs_vs_ledger' => 'By documents: :v',
    'docs_vs_ledger_hint' => 'The card comes from the ledger (entry date, value actually delivered). The tables below go by document date and full document total, so their sum can differ.',

    // export file columns
    'x_inv_value' => 'Invoices value',
    'x_no_photo_reason' => 'Reason closed without photos',
    'x_approver' => 'Approved by',

    // Overview — every card's equation with its numbers
    'eq_sales_pay' => ':t = :a cash + :b credit + :c deliveries',
    'eq_sales_bill' => ':t = :a tax-billed + :b unbilled',
    'open_pos_note' => '"Open" = :n purchase orders not delivered yet — not counted in sales.',
    'coll_office' => 'Office / accounts',
    'eq_coll' => ':t = :a invoice cash + :b field + :c deliveries + :d office',
    'eq_coll_pct' => ':p% = :a collected ÷ :b sales',
    'h_coll2' => 'Everything actually collected, by entry date: cash with invoices + field collections + delivery collections + entries recorded by accounts — click to open collections.',
    'eq_rets' => ':t = total of :n documents — :p% of sales :s',
    'eq_net' => ':t = :a sales − :b collected − :c returns :d other entries',
    'h_eq_net2' => 'What was added (+) or cleared (−) on clients in the period = Σ debit − Σ credit. "Outstanding debt" :g = balances of :n clients who owe now — not tied to the period.',
    'eq_street' => ':v = Σ (remaining for sale × selling price) — :u pieces in :n open vans. As of now, not the period.',
    'eq_field' => ':n = visits opened in the period. Gifts (pieces) and return documents are side figures, not part of it.',
    'eq_new' => 'Clients whose registration date is inside the period. "Pending requests" are awaiting approval now and are not part of the number.',
    'eq_stock' => ':v = Σ (each product\'s warehouse balance × its current selling price). As of now, not the period.',
    'eq_aging' => ':t = sum of the five buckets',
    'eq_lines_pre_tax' => 'Families total :t — line values before tax, so it is lower than the sales card (which includes tax and entries without lines).',
    'eq_channels' => ':t = sum of channels = the period sales card.',

    // Rep page — each card's equation
    'rep_custody_eq' => ':t pcs = :l loaded − :s sold − :o other out',
    'rep_custody_val' => 'Value = Σ (remaining × list price) — live, not by period.',
    'rep_sales_eq' => ':t = :inv invoices + :po delivered orders',
    'rep_sales_scope' => 'From the ledger, by entry date.',
    'rep_coll_eq' => ':t = :cash cash + :other non-cash',
    'rep_coll_scope' => ':n collections inside the rep\'s visits — invoice cash excluded.',
    'rep_ret_scope' => 'Total of :n return documents in the period.',
    'rep_visits_scope' => ':done closed out of :all opened in the period.',
    'rep_bal_eq' => ':t = :p previous + :d due − :r received',
    'rep_bal_scope' => 'Last settlement — positive = the rep owes. What is due now is under "Settlement".',
    'rep_custody_other' => '"Other out" = gifts given + returned to warehouse + transferred + gifts still in the van — detailed in the custody equation below.',
    'rep_pct_eq' => ':p% = :a achieved ÷ :t target',
    'rep_drain_eq' => ':p% = (:l loaded − :r still in van) ÷ :l loaded',
    'rep_avg_eq' => ':v = :g invoices value ÷ :n invoices',
    'rep_seen_eq' => ':m not visited = :c rep clients − :s visited',

    // Purchase orders — total card equation
    'po_value_eq' => ':t = :all all orders − :x rejected/cancelled (:n orders)',
    'po_value_scope' => 'Order grand total incl. tax, by order creation date — not sales: sales are booked on delivery.',

    // Operations dashboard — today's cards explained
    'od_sales_sub' => 'Sum of the team\'s invoices dated today (incl. tax) — purchase orders excluded.',
    'od_pos_sub' => 'Purchase orders delivered today. Both cards together = :t, the sum of "today\'s performance" in the table.',
    'od_visits_sub' => ':d closed out of :a visits opened today — :o still open.',
    'od_req_sub' => 'New-client requests from the team in "pending" or "review" — not tied to a date.',
    'od_reps_sub' => ':n = the whole field team in the table — :c hold an open custody and :v are inside a visit now.',

    // Rep board — cards explained
    'rb_working_sub' => 'Checked in and not checked out yet — out of :n field staff in the table. Live, not by period.',
    'rb_vans_sub' => 'Custodies open right now — :u units left worth :v at selling price.',
    'rb_sales_eq' => ':t = :c cash + :r credit',
    'rb_sales_sub' => 'Reps\' invoices + purchase orders they delivered in the period — the sum of the "Sales" column below.',
    'rb_coll_eq' => ':t = :c cash + :o non-cash',
    'rb_coll_sub' => 'Collections inside reps\' visits in the period (invoice cash excluded) — the sum of the "Collections" column below.',

    // Rep day — cards explained
    'rd_in_sub' => 'First check-in of the selected day.',
    'rd_break_sub' => 'Time of the last break — minutes are the sum of all breaks.',
    'rd_out_sub' => 'Last check-out — empty means not checked out yet.',
    'rd_worked_sub' => 'From check-in to check-out (or now), breaks excluded (:b min).',
    'rd_planned_sub' => 'Journey-plan clients scheduled for this day.',
    'rd_pct_eq' => ':p% = :d done ÷ :n planned',
    'rd_pending_eq' => ':t = :n planned − :d done',
    'rd_coll_scope' => 'Collections inside the day\'s visits — invoice cash excluded.',

    // Visits log — cards explained
    'vb_clients_sub' => 'Distinct clients across :v visits — :a visits per client on average.',
    'vb_share_eq' => ':p% = :n ÷ :v visits',
    'vb_photos_sub' => 'Visits with at least one shelf photo uploaded.',
    'vb_invoiced_sub' => 'Visits that produced an invoice — the visit-to-sale conversion.',

    // Open visits / van custody / tracking — cards explained
    'ov_clients_sub' => ':t = :a opened today + :b from previous days — client visits not closed yet.',
    'ov_wh_sub' => 'Reps who entered a warehouse and have not checked out.',
    'ov_att_sub' => 'Checked in today and not checked out yet.',
    'vans_units_sub' => 'Remaining-for-sale units across :n open custodies = the sum of the "Remaining" column below.',
    'vans_none_sub' => 'Out of :n reps — with no current custody at all.',
    'vans_value_sub' => 'Σ (remaining × selling price): drivers on the old list, sales on the new one. Live.',
    'trk_reps_sub' => 'Reps with at least one event on the selected day.',
    'trk_events_sub' => 'All events in the timeline below for the selected day and rep.',
    'trk_type_sub' => 'Count of this event type from the same timeline.',

    // Purchase order / return document
    'po_units_sub' => 'Sum of requested quantities across :n items.',
    'po_total_eq' => ':t = :n net before tax + :x tax',
    'ret_total_eq' => ':t = :s before discount − :d discount + :x tax',
    'ret_total_sub' => 'This is what was deducted from the client account.',

    // Rep sales — net cash card
    'rs_net_eq' => ':t = :a cash sales + :b cash collected − :c cash refunds',
    'rs_net_sub' => 'Cash the reps should hand over for the period — credit, transfers and cheques are not included.',

    // Invoices — card equations
    'inv_count_sub' => 'Invoices created inside the period with the same filters — purchase orders are not here.',
    'inv_disc_eq' => ':p% = :d discount ÷ :s before discount',
    'inv_net_eq' => ':t = :s before discount − :d discount',
    'inv_grand_eq' => ':t = :n net + :x tax',

    // Returns — card equations
    'ret_rate_eq' => ':p% = :r returns ÷ :s period sales',
    'ret_units_eq' => ':u pcs = :g good + :d damaged',
    'ret_dmg_eq' => ':p% = :d damaged ÷ :u pcs',
    'rep_cash_due' => "Cash due from the rep now",
    'rep_cash_due_eq' => ":t = previous balance :p + cash sales :s + cash collections :c − cash refunds :r",
    'rep_cash_due_since' => "Since the last settlement :d until now — same calculation as the settlement screen",
    'rep_cash_due_never' => "Since day one — never settled yet",
];
