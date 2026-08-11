<?php

/** Hierarchical annual target: company > managers > reps > clients (2026-08-11). */
return [
    'title' => 'Annual Target',
    'hint' => 'The company target splits down to managers, reps and clients - each level over 12 months',

    // Create/edit the company target
    'create_for' => 'Create :year target',
    'edit_company' => 'Edit company target',
    'annual_amount' => 'Annual target (EGP)',
    'company_dlg_hint' => 'The first save splits evenly over 12 months - editing the total later rescales the existing month curve',
    'company_saved' => 'Company target saved.',
    'no_company' => 'No target for this year yet',
    'no_company_hint_admin' => 'Press "Create target" and set the annual total - it will split evenly over the months',
    'no_company_hint_manager' => 'The admin has not set this year target yet',
    'no_manager_node' => 'No target has been allocated to you for this year yet',

    // KPIs
    'kpi_target' => 'Year target',
    'kpi_achieved' => 'Achieved so far',
    'kpi_remaining' => 'Remaining',
    'kpi_pct' => 'Achievement',

    // Month grid
    'months_title' => 'Monthly breakdown',
    'months_hint_admin' => 'Edit a future month and the delta rebalances over the following months - past months are 🔒 and the annual total stays fixed',
    'months_hint_manager' => 'Monthly targets are set by the admin - achieved is computed from your clients sales',
    'month_col' => 'Month',
    'month_target' => 'Target',
    'month_achieved' => 'Achieved',
    'month_pct' => 'Pct',
    'manual_badge' => 'Manual',
    'manual_hint' => 'Past months: type a manual actual to override the computed value - leave the box empty to fall back to the system figure',
    'save_manual' => 'Save manual actuals',
    'manual_saved' => 'Manual actuals saved.',
    'manual_company_only' => 'Manual actuals apply to the company level only.',
    'month_locked' => 'This month is in the past - its target is locked.',
    'last_month_fixed' => 'The last month has no following months to rebalance over - edit an earlier month.',
    'rebalanced' => 'Monthly breakdown rebalanced.',
    'not_your_subtree' => 'This target is not within your team.',

    // Managers split
    'managers_title' => 'Managers split',
    'managers_hint' => 'By percent or amount - the two are linked. Saving is flexible even if the sum is off',
    'managers_saved' => 'Managers split saved.',
    'manager' => 'Manager',
    'amount' => 'Amount (EGP)',
    'achieved' => 'Achieved',
    'progress' => 'Progress',
    'no_managers' => 'No active channel managers',
    'no_company_for_split' => 'Create the company target first.',

    // Manager team split
    'reps_title' => ':name team split',
    'rep' => 'Rep',
    'reps_saved' => 'Team split saved.',
    'no_reps' => 'No field team assigned to this manager',
    'clients_split' => 'Clients split',

    // Rep clients page
    'rep_page_title' => ':name target',
    'clients_title' => ':name clients split',
    'client' => 'Client',
    'chain' => 'Chain',
    'filter_clients' => 'Filter by name or code',
    'clients_saved' => 'Clients split saved.',
    'client_not_reps' => 'This client is not assigned to this rep.',
    'no_clients' => 'No clients assigned to this rep',
    'no_rep_node' => 'No target allocated to this rep for this year yet - allocate one from the annual target page first.',
    'back' => 'Back to annual target',

    // Distribution hint (JavaScript)
    'distributed' => 'Distributed',
    'of' => 'of',
    'left' => 'left',
    'over' => 'over by',

    // Months
    'm1' => 'Jan',
    'm2' => 'Feb',
    'm3' => 'Mar',
    'm4' => 'Apr',
    'm5' => 'May',
    'm6' => 'Jun',
    'm7' => 'Jul',
    'm8' => 'Aug',
    'm9' => 'Sep',
    'm10' => 'Oct',
    'm11' => 'Nov',
    'm12' => 'Dec',
];
