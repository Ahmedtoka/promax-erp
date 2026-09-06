<?php

namespace App\Agents;

/**
 * ═══════════════════════════════════════════════════════════════
 * عرض نتايج الأدوات — بلوكات البيانات في الشات (٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * البلوك بيتبني هنا في السيرفر **من نتيجة الأداة نفسها** مش من
 * كلام الموديل — فمستحيل الجدول يعرض رقم مأّلف. بيرجع تلاتة:
 * [data (جدول/كارت), link (افتح في السيستم), action (كارت تأكيد)].
 */
class Presenter
{
    /** @return array{0: ?array, 1: ?array, 2: ?array} */
    public static function present(?string $tool, ?array $r): array
    {
        if ($tool === null || $r === null || isset($r['not_available'])) {
            return [null, null, null];
        }

        $m = fn ($v) => number_format((float) $v, 2);

        return match ($tool) {
            // ═══════════════ حسابات ═══════════════
            'client_statement' => [
                [
                    'type' => 'table',
                    'title' => __('agent.d_statement', ['name' => $r['name']]),
                    'columns' => [__('common.date'), __('agent.c_memo'), __('agent.c_kind'),
                        __('agent.c_debit'), __('agent.c_credit')],
                    'rows' => collect($r['rows'])->map(fn ($t) => [
                        $t['date'], $t['memo'], $t['kind'],
                        $t['debit'] ? $m($t['debit']) : '—',
                        $t['credit'] ? $m($t['credit']) : '—',
                    ])->all(),
                    'footer' => __('agent.d_stmt_footer', [
                        'shown' => $r['rows_shown'], 'total' => $r['rows_total'],
                        'debit' => $m($r['sum_debit']), 'credit' => $m($r['sum_credit']),
                    ]),
                ],
                self::clientLink($r['client_id']),
                null,
            ],
            'client_balance' => [
                [
                    'type' => 'card',
                    'title' => $r['name'],
                    'rows' => [
                        [__('agent.c_balance'), __('agent.money', ['n' => $m($r['balance'])])],
                        [__('agent.c_purchases'), __('agent.money', ['n' => $m($r['purchases'])])],
                        [__('agent.c_collections'), __('agent.money', ['n' => $m($r['collections'])])],
                        [__('agent.c_returns'), __('agent.money', ['n' => $m($r['returns'])])],
                    ],
                ],
                self::clientLink($r['client_id']),
                null,
            ],
            'chain_summary' => isset($r['multiple_chains']) ? [null, null, null] : [
                [
                    'type' => 'table',
                    'title' => __('agent.d_chain', ['name' => $r['chain'], 'n' => $r['branches_count']]),
                    'columns' => [__('agent.c_branch'), __('common.code'), __('agent.c_balance')],
                    'rows' => collect($r['branches'])->map(fn ($b) => [
                        $b['name'], $b['code'], $m($b['balance']),
                    ])->all(),
                    'footer' => __('agent.d_chain_footer', [
                        'balance' => $m($r['totals']['balance']),
                        'purchases' => $m($r['totals']['purchases']),
                        'collections' => $m($r['totals']['collections']),
                        'today' => $m($r['totals']['today_sales']),
                    ]),
                ],
                ['label' => __('agent.open_chain'), 'url' => route('erp.groups.show', $r['chain_id'])],
                null,
            ],
            'debt_aging' => [
                [
                    'type' => 'table',
                    'title' => $r['channel']
                        ? __('agent.d_aging_ch', ['channel' => $r['channel']])
                        : __('agent.d_aging'),
                    'columns' => [__('report.days_0_30'), __('report.days_31_60'),
                        __('report.days_61_90'), __('report.days_91_180'), __('report.days_180_plus')],
                    'rows' => [array_map($m, array_values($r['buckets']))],
                    'footer' => __('agent.d_aging_footer', [
                        'total' => $m($r['total']), 'clients' => $r['clients_with_debt'],
                    ]),
                ],
                ['label' => __('agent.open_dashboard'), 'url' => route('erp.overview')],
                null,
            ],
            'find_client' => [
                count($r['candidates'] ?? []) > 1 ? [
                    'type' => 'table',
                    'title' => __('agent.d_candidates'),
                    'columns' => ['#', __('client.client'), __('common.code'), __('agent.c_balance')],
                    'rows' => collect($r['candidates'])->map(fn ($c) => [
                        $c['client_id'], $c['name'], $c['code'], $m($c['balance']),
                    ])->all(),
                    'footer' => null,
                ] : null,
                count($r['candidates'] ?? []) === 1
                    ? self::clientLink($r['candidates'][0]['client_id'])
                    : null,
                null,
            ],

            // ═══════════════ مبيعات ═══════════════
            'sales_summary' => [
                [
                    'type' => 'card',
                    'title' => __('agent.d_sales', [
                        'range' => $r['from'] === $r['to'] ? $r['from'] : $r['from'].' → '.$r['to'],
                    ]).($r['rep'] ? ' — '.$r['rep'] : ''),
                    'rows' => [
                        [__('agent.c_invoices').' ('.$r['invoices']['count'].')',
                            __('agent.money', ['n' => $m($r['invoices']['grand'])])],
                        ['· '.__('agent.c_cash'), __('agent.money', ['n' => $m($r['invoices']['cash'])])],
                        ['· '.__('agent.c_credit_pay'), __('agent.money', ['n' => $m($r['invoices']['credit'])])],
                        [__('agent.c_pos').' ('.$r['delivered_pos']['count'].')',
                            __('agent.money', ['n' => $m($r['delivered_pos']['grand'])])],
                        [__('agent.c_total_sales'), __('agent.money', ['n' => $m($r['total_sales'])])],
                        [__('agent.c_collections'), __('agent.money', ['n' => $m($r['collections']['total'])])],
                        [__('agent.c_returns').' ('.$r['returns']['count'].')',
                            __('agent.money', ['n' => $m($r['returns']['grand'])])],
                    ],
                ],
                ['label' => __('agent.open_dashboard'), 'url' => route('erp.overview')],
                null,
            ],
            'top_products' => [
                [
                    'type' => 'table',
                    'title' => __('agent.d_top_products', [
                        'range' => $r['from'].' → '.$r['to'],
                    ]),
                    'columns' => [__('agent.c_product'), __('agent.c_qty'), __('agent.c_value')],
                    'rows' => collect($r['products'])->map(fn ($p) => [
                        $p['name'], $p['qty'], $m($p['value']),
                    ])->all(),
                    'footer' => null,
                ],
                ['label' => __('agent.open_dashboard'), 'url' => route('erp.overview')],
                null,
            ],

            // ═══════════════ مخزون ═══════════════
            'product_stock' => isset($r['multiple_products']) ? [
                [
                    'type' => 'table',
                    'title' => __('agent.d_prod_candidates'),
                    'columns' => [__('common.code'), __('agent.c_product')],
                    'rows' => collect($r['multiple_products'])->map(fn ($p) => [
                        $p['code'], $p['name'],
                    ])->all(),
                    'footer' => null,
                ], null, null,
            ] : [
                [
                    'type' => 'table',
                    'title' => __('agent.d_stock', ['name' => $r['code'].' — '.$r['name']]),
                    'columns' => [__('agent.c_warehouse'), __('agent.c_good'),
                        __('agent.c_hold'), __('agent.c_qty')],
                    'rows' => collect($r['warehouses'])->map(fn ($w) => [
                        $w['warehouse'], $w['good'], $w['hold'], $w['qty'],
                    ])->all(),
                    'footer' => __('agent.d_stock_footer', ['total' => number_format($r['total_qty'])]),
                ],
                ['label' => __('agent.open_stock'), 'url' => route('erp.stock')],
                null,
            ],
            'expiring_batches' => [
                [
                    'type' => 'table',
                    'title' => __('agent.d_expiring', ['days' => $r['within_days']]),
                    'columns' => [__('agent.c_product'), __('stock.batch'), __('agent.c_warehouse'),
                        __('stock.expiry'), __('agent.c_days'), __('agent.c_qty')],
                    'rows' => collect($r['batches'])->map(fn ($b) => [
                        $b['product'], $b['batch'], $b['warehouse'],
                        $b['expires'], $b['days_left'], $b['qty'],
                    ])->all(),
                    'footer' => null,
                ],
                ['label' => __('agent.open_expiry'), 'url' => route('wh.expiry')],
                null,
            ],
            'van_stock' => isset($r['no_custody']) ? [null, null, null] : [
                [
                    'type' => 'table',
                    'title' => __('agent.d_van', ['rep' => $r['rep']]),
                    'columns' => [__('common.code'), __('agent.c_product'), __('agent.c_loaded'),
                        __('agent.c_sold'), __('agent.c_remaining')],
                    'rows' => collect($r['items'])->map(fn ($i) => [
                        $i['code'], $i['name'], $i['loaded'], $i['sold'], $i['remaining'],
                    ])->all(),
                    'footer' => __('agent.d_van_footer', [
                        'total' => number_format($r['total_remaining']),
                    ]),
                ],
                ['label' => __('agent.open_rep'), 'url' => route('ops.rep', $r['rep_id'])],
                null,
            ],

            // ═══════════════ ميداني ═══════════════
            'attendance_today' => [
                [
                    'type' => 'table',
                    'title' => __('agent.d_attendance', ['date' => $r['date']]),
                    'columns' => [__('agent.c_rep'), __('agent.c_in'), __('agent.c_out')],
                    'rows' => collect($r['checked_in'])->map(fn ($u) => [
                        $u['name'], $u['in_at'], $u['out_at'] ?? '—',
                    ])->all(),
                    'footer' => __('agent.d_attendance_footer', [
                        'in' => count($r['checked_in']),
                        'team' => $r['team_count'],
                        'absent' => collect($r['not_yet'])->pluck('name')->implode('، ') ?: '—',
                    ]),
                ],
                ['label' => __('agent.open_attendance'), 'url' => route('erp.attendance')],
                null,
            ],
            'rep_activity' => [
                [
                    'type' => 'card',
                    'title' => __('agent.d_activity', [
                        'rep' => $r['rep'],
                        'range' => $r['from'] === $r['to'] ? $r['from'] : $r['from'].' → '.$r['to'],
                    ]),
                    'rows' => [
                        [__('agent.c_visits'), $r['visits']['count'].' ('.__('agent.c_done').' '.$r['visits']['done'].')'],
                        [__('agent.c_invoices').' ('.$r['invoices']['count'].')',
                            __('agent.money', ['n' => $m($r['invoices']['grand'])])],
                        [__('agent.c_pos').' ('.$r['delivered_pos']['count'].')',
                            __('agent.money', ['n' => $m($r['delivered_pos']['grand'])])],
                        [__('agent.c_field_coll'), __('agent.money', ['n' => $m($r['field_collections']['total'])])],
                        [__('agent.c_returns').' ('.$r['returns']['count'].')',
                            __('agent.money', ['n' => $m($r['returns']['grand'])])],
                    ],
                ],
                ['label' => __('agent.open_rep'), 'url' => route('ops.rep', $r['rep_id'])],
                null,
            ],
            'find_rep' => [
                count($r['candidates'] ?? []) > 1 ? [
                    'type' => 'table',
                    'title' => __('agent.d_rep_candidates'),
                    'columns' => ['#', __('agent.c_rep'), __('common.code')],
                    'rows' => collect($r['candidates'])->map(fn ($c) => [
                        $c['rep_id'], $c['name'], $c['code'],
                    ])->all(),
                    'footer' => null,
                ] : null,
                null,
                null,
            ],

            // ═══════════════ أكشن بموافقة ═══════════════
            'propose_collection' => [
                null,
                null,
                self::collectionActionCard($r),
            ],

            default => [null, null, null],
        };
    }

    /** كارت تأكيد أكشن التحصيل — الزرارين بينفذوا في السيرفر */
    private static function collectionActionCard(array $r): ?array
    {
        if (! isset($r['action_id'])) {
            return null;
        }

        $action = \App\Models\AgentAction::find($r['action_id']);

        if ($action === null) {
            return null;
        }

        $p = $action->payload;

        $rows = [
            [__('client.client'), $p['client_name']],
            [__('ops.md_rep'), $p['rep_name']],
            [__('ops.md_amount'), __('agent.money', ['n' => number_format($p['amount'], 2)])],
            [__('ops.md_method'), __('client.pay_method_'.$p['method'])],
            [__('common.date'), $p['date']],
        ];

        if (! empty($p['reference'])) {
            $rows[] = [__('ops.reference'), $p['reference']];
        }

        if (! empty($p['cheque_bank'])) {
            $rows[] = [__('ops.md_chq_bank'), $p['cheque_bank']];
            $rows[] = [__('ops.md_chq_due'), $p['cheque_due']];
        }

        return [
            'action_id' => $action->id,
            'title' => __('agent.act_collect_title'),
            'rows' => $rows,
            'confirm_url' => route('agent.action.confirm', $action),
            'cancel_url' => route('agent.action.cancel', $action),
            'confirm_label' => __('agent.act_confirm'),
            'cancel_label' => __('agent.act_cancel'),
            'warn' => __('agent.act_warn'),
        ];
    }

    private static function clientLink(int $clientId): array
    {
        return [
            'label' => __('agent.open_client'),
            'url' => route('erp.clients.show', $clientId),
        ];
    }
}
