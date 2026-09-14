<?php

/*
 * Expected cash (15 Sep 2026) - due-date calendar of open client debt.
 * Keep lang/{ar,en}/cashflow.php keys identical.
 */
return [
    'title' => 'Expected Cash',
    'sub' => 'Open credit-client debt spread over its due dates. If every client pays on time, this is what comes in and when.',
    'disclaimer' => 'A contractual forecast from payment terms, not a collection promise. Overdue amounts are counted as due immediately.',

    // KPIs
    'kpi_window' => 'Due in period',
    'kpi_window_sub' => ':n entries from :from to :to',
    'kpi_overdue' => 'Overdue (past due date)',
    'kpi_overdue_sub' => ':n open entries before today',
    'kpi_cash_by' => 'Cash I could have by :date',
    'kpi_cash_by_sub' => 'Overdue + due through the end of the period',
    'kpi_no_terms' => 'Credit without payment terms',
    'kpi_no_terms_sub' => ':n entries with no date to place them on',
    'kpi_later' => 'Due after the period',
    'kpi_later_sub' => ':n entries after :to',
    'kpi_total_open' => 'All open credit debt',
    'kpi_total_open_sub' => 'Overdue + due + no terms',

    // quick horizon
    'horizon' => 'If everything is paid on time, I would have',
    'horizon_days' => 'within :n days',
    'horizon_incl_overdue' => 'including overdue',

    // filters
    'quick' => 'Quick period',
    'q15' => '15 days',
    'q30' => '30 days',
    'q60' => '60 days',
    'q90' => '90 days',
    'rep' => 'Rep',
    'manager' => 'Channel manager',
    'granularity' => 'View',
    'view_calendar' => 'Calendar',
    'view_list' => 'List',

    // calendar
    'calendar' => 'Due-date calendar',
    'calendar_hint' => 'Each cell: total due that day and the number of entries. Click a day to see who.',
    'cum' => 'Cumulative',
    'cum_hint' => 'Overdue + everything due from the start of the period through this day',
    'day_empty' => 'Nothing due on this day',
    'day_title' => 'Due on :date',
    'outside' => 'Outside the period',
    'today' => 'Today',
    'weekday_sat' => 'Sa', 'weekday_sun' => 'Su', 'weekday_mon' => 'Mo', 'weekday_tue' => 'Tu',
    'weekday_wed' => 'We', 'weekday_thu' => 'Th', 'weekday_fri' => 'Fr',

    // curve
    'curve' => 'Cumulative cash curve',
    'curve_hint' => 'Starts at the overdue amount and grows with every due date in the period',

    // tables
    'table_due' => 'Entries due in the period',
    'table_overdue' => 'Overdue - past due and still open',
    'table_no_terms' => 'Credit without payment terms - needs payment days on the client or contract',
    'col_due' => 'Due',
    'col_status' => 'Status',
    'col_client' => 'Client',
    'col_rep' => 'Rep',
    'col_channel' => 'Channel',
    'col_doc' => 'Document',
    'col_doc_date' => 'Entry date',
    'col_debit' => 'Entry amount',
    'col_open' => 'Open',
    'col_terms' => 'Terms',
    'col_days_late' => 'Days late',
    'status_due' => 'Due',
    'status_overdue' => 'Overdue',
    'status_no_terms' => 'No terms',
    'days_invoice' => 'days from invoice',
    'days_first_supply' => 'days from first supply',
    'kind_sale' => 'Invoice',
    'kind_opening' => 'Opening balance',
    'total' => 'Total',
    'empty' => 'Nothing is due in this period',
    'show_more' => 'Show all rows (:n)',
    'set_terms' => 'Set terms',
];
