<?php

return [

    // ═══ UI review 22 Sep 2026 — products, warehouses, purchasing, online, fleet ═══
    'fam_breakdown' => 'Stock value by family',
    'wh_breakdown' => 'Item split across warehouses',
    'l_role' => 'role',
    'shelves' => 'shelves',
    'perm_group_setting' => 'Whole ":x" section setting',

    // ═══ Visual review 22 Sep — every number explains itself ═══
    // Product catalogue
    'needs_action' => 'Needs action',
    'na_all' => 'All items',
    'na_out' => 'Active items with zero stock',
    'na_out_tip' => 'Active item with no units in any warehouse',
    'na_hold' => 'Items on hold (:q units)',
    'na_hold_short' => 'Items on hold',
    'na_hold_tip' => 'Quantity held back from sale until a decision is made',
    'na_nocost' => 'Items without cost',
    'na_expiry' => 'Near expiry',
    'no_cost_tip' => 'No cost recorded on the item (0) — margin is meaningless until it is entered',
    'no_match' => 'Nothing matches the filter',
    'unclassified' => 'unclassified',
    'sv_how' => 'Σ each item qty × its price in the default list (:list) — :n items',
    'cv_how' => 'Σ each item qty × its recorded unit cost',
    'margin_void' => ':n of :all items have cost 0 — margin is meaningless until cost is entered',
    'margin_partial' => ':n items have cost 0 — margin is overstated',
    'units_how' => 'All units in all warehouses right now',
    'good_how' => 'Good, sellable stock (:q units) × list price',
    'hold_how' => 'Held, not for sale (:q units) × list price',
    'fam_share' => 'Family share of stock value',

    // Warehouse operations
    'wa_picks' => 'Open pick orders',
    'wa_putaway' => 'Batches awaiting putaway (:q units)',
    'wa_incoming' => 'Incoming transfers to receive',
    'wa_expired' => 'Expired batches still in stock (:q units)',
    'wa_expiring' => 'Batches expiring within :d days (:q units)',
    'wk_total_how' => 'All units recorded in :wh right now',
    'wk_avail' => 'available',
    'wk_await' => 'awaiting putaway',
    'wk_rest' => 'expired/blocked or without batch',
    'wk_avail_how' => 'Shelved units of sellable batches (not expired or blocked) — what picking draws from',
    'wk_await_how' => 'Received but not shelved yet — :n batches. Cannot be picked before putaway',
    'wk_expiring_how' => 'Batches whose shelf life ends within :d days — holding :q units',
    'wk_shelves_how' => 'Storage locations defined in this warehouse',

    // Expiry report
    'exp_range_note' => 'The period filters on batch expiry date — leave it empty to see every batch',
    'exp_expired' => 'Expiry date has passed (below 0 days)',
    'exp_danger' => '0 to :d days left',
    'exp_warn' => ':d1 to :w days left',
    'exp_ok' => 'More than :w days left (or no expiry date)',
    'exp_total' => 'Total (:n batches with stock) = expired + danger + near expiry + good:',
    'exp_in_window' => 'inside the chosen expiry window only',
    'bad_date' => 'Mistyped date',
    'bad_date_tip' => 'The year is not plausible — open the goods receipt and correct the expiry date',

    // Online accounts & collections
    'oa_goods' => 'shipped goods',
    'oa_ret' => 'returned',
    'oa_coll' => 'collected',
    'oa_shipped_n' => 'across :n orders in "shipped" status — a live balance, not tied to a period',
    'oa_all_time' => 'all time, not tied to a period',
    'oa_live_n' => 'across :n orders (ready + shipped + completed)',
    'oa_cost_zero' => 'Cost is 0 — items have no recorded cost, so margin is meaningless',
    'oa_margin_how' => ':n completed orders: (goods − returned) − their cost',
    'oc_goods' => 'Goods out with couriers',
    'oc_goods_how' => 'Goods value of shipped orders still open — shipping excluded',
    'oc_coll' => 'Partly collected so far',
    'oc_coll_how' => 'Collected from these open orders — an order closes as "completed" when nothing remains',

    // Permissions
    'perm_find' => 'Find a screen or action',
    'perm_find_ph' => 'Screen, section or action name',
    'perm_only_changed' => 'Overrides only (not inherited)',
    'perm_fold_all' => 'Collapse all',
    'perm_open_all' => 'Expand all',
    'perm_fold_tip' => 'Click to collapse or expand the section',

    // Product card
    'shelf_life_how' => 'used to derive the expiry date when a receipt has none',
    'no_batches_in_window' => 'No batches expire inside this period.',

    // Stock & shelf life (factory audit snapshot)
    'bk_value_how' => 'Σ each batch units × the item new price — :n items. An audit snapshot, not live stock',
    'bk_live_how' => 'Units not reserved in the audit',
    'bk_hold_how' => 'Units flagged on hold in the audit',
    'wh_hold_part' => 'part of total units, not on top of it',

    // Suppliers
    'sup_count_how' => 'All registered suppliers (active and inactive) — not tied to a period',

    // Shelves
    'loc_occ_how' => 'shelves holding more than zero units',
    'loc_total_how' => 'Sum of all shelf units = the "stock by shelf" table total below. Received goods not yet shelved are excluded',

    // Goods receipt
    'grn_total_how' => 'Total received across :n batches — and where it is now:',
    'grn_issued' => 'issued',
    'grn_damaged' => 'damaged',
    'grn_other' => 'adjustments',
    'grn_shelved_how' => 'What sits on shelves right now from this receipt',
    'grn_search_ph' => 'Receipt no., supplier, reference or batch no.',

    // Pick orders
    'pk_open' => 'Needs work now',
    'pk_requested' => 'Nobody has started it yet',
    'pk_picking' => 'The keeper is picking the goods',
    'pk_ready' => 'Picked and waiting for the rep to collect',
    'pk_handed' => 'Collected by the rep — done',
    'pk_cancelled' => 'Cancelled, no goods left',
    'pk_total' => 'All orders inside the filters =',
    'pk_range_note' => 'the period filters on pickup time; orders without one disappear when a period is set',
    'pk_search_ph' => 'Pick order or PO number',
    'wa_online' => 'Open online prep',

    // Online sync
    'os_new_how' => 'Orders synced from Shopify, customer not called yet',
    'os_post_how' => 'Customer asked for another date — waiting for it',
    'os_due_how' => 'Of the :n postponed: those due today or overdue — call these first',

    // Transfers
    'tr_total_how' => 'All transfers inside the filters and period (sent date) = in transit + received',
    'tr_other' => 'other statuses',
    'tr_sent_how' => 'Left the sending warehouse, not received yet (warehouse to warehouse)',
    'tr_recv_how' => 'The receiving warehouse confirmed receipt',
    'tr_van_how' => 'Van to warehouse or van to van — done in one step, part of the total',

    // Stock count
    'count_cancelled_note' => 'This count was cancelled — stock balances were not changed by it. View only.',
    'pk_short' => 'short',
    'pk_req_how' => 'Sum of requested units across all order lines',
    'cnt_lines_how' => 'Each batch with stock in :wh = one line',
    'cnt_done' => 'counted',
    'cnt_diff_how' => 'Lines where the counted qty differs from the system balance',
    'cnt_qty_how' => 'Σ (counted − system balance) — negative is a shortage, positive a surplus',
    'cnt_val_how' => 'Σ each line difference × unit cost — a zero cost gives a zero value',
];
