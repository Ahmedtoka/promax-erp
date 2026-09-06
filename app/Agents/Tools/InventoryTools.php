<?php

namespace App\Agents\Tools;

use App\Models\Batch;
use App\Models\Product;
use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * أدوات المخزون — دومين «مخزون» في مساعد بروماكس (قراءة فقط)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ المصادر: رصيد الصنف من `stocks` (نفس شاشة الكتالوج)،
 * الصلاحية من `batches` (نفس تقرير الصلاحية)، والعهدة من
 * `custody_items` بمعادلة الأرضية (المحمّل − المباع − المرتجع).
 */
class InventoryTools
{
    use Shared;

    public static function specs(): array
    {
        return [
            [
                'name' => 'product_stock',
                'description' => 'رصيد صنف في المخازن (سليم/هولد/إجمالي لكل مخزن) — بالبحث بالكود أو الاسم. نفس أرقام شاشة كتالوج المنتجات.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'كود الصنف أو جزء من اسمه'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'expiring_batches',
                'description' => 'الباتشات اللي قربت تنتهي صلاحيتها (الافتراضي خلال 60 يوم) وفيها كمية متبقية — الصنف والباتش وتاريخ الانتهاء والأيام الفاضلة والكمية.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'خلال كام يوم (الافتراضي 60)'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'van_stock',
                'description' => 'عهدة مندوب دلوقتي: كل صنف والكمية الفاضلة في عربيته (المحمّل − المباع − المرتجع). محتاج rep_id — استخدم find_rep الأول لو معاك اسم.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'rep_id' => ['type' => 'integer', 'description' => 'رقم المندوب'],
                    ],
                    'required' => ['rep_id'],
                ],
            ],
        ];
    }

    public static function productStock(string $query, User $user): array
    {
        $s = trim($query);

        if (mb_strlen($s) < 2) {
            return ['error' => 'اكتب حرفين على الأقل من الكود أو الاسم.'];
        }

        // الكود مطابقة دقيقة الأول، وبعدها الاسم بالتطبيع الموحّد
        $norm = \App\Models\Client::normalizeArabic($s);

        $products = Product::where('active', true)
            ->where(function ($w) use ($s, $norm) {
                $w->where('code', $s)
                    ->orWhere('code', 'like', "%$s%")
                    ->orWhereRaw(\App\Models\Client::normSql('name').' like ?', ["%{$norm}%"])
                    ->orWhere('name_en', 'like', "%$s%");
            })
            ->with('stocks.warehouse')
            ->orderBy('code')->limit(6)->get();

        if ($products->isEmpty()) {
            return ['error' => 'مفيش صنف بالكود أو الاسم ده.'];
        }

        if ($products->count() > 1) {
            return [
                'multiple_products' => $products->map(fn (Product $p) => [
                    'code' => $p->code,
                    'name' => $p->displayName(),
                ])->values()->all(),
                'note' => 'فيه أكتر من صنف مطابق — اسأل المستخدم يحدد بالكود.',
            ];
        }

        $p = $products->first();

        // نفس مصدر شاشة الكتالوج: صفوف `stocks` لكل مخزن
        $rows = $p->stocks->map(fn ($st) => [
            'warehouse' => $st->warehouse?->displayName() ?? '#'.$st->warehouse_id,
            'good' => (int) $st->good_qty,
            'hold' => (int) $st->hold_qty,
            'qty' => (int) $st->qty,
        ])->values();

        return [
            'code' => $p->code,
            'name' => $p->displayName(),
            'unit' => $p->unitLabel(),
            'total_qty' => (int) $rows->sum('qty'),
            'warehouses' => $rows->all(),
        ];
    }

    public static function expiringBatches(?int $days, User $user): array
    {
        $days = max(1, min($days ?? 60, 365));
        $limit = today()->addDays($days);

        // المصدر نفسه: `batches` — كمية متبقية وتاريخ انتهاء جوه النافذة
        $rows = Batch::with(['product', 'warehouse'])
            ->where('qty_remaining', '>', 0)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', $limit)
            ->orderBy('expires_on')
            ->limit(40)->get();

        return [
            'within_days' => $days,
            'count' => $rows->count(),
            'batches' => $rows->map(fn (Batch $b) => [
                'product' => $b->product?->displayName() ?? '#'.$b->product_id,
                'batch' => $b->batch_no,
                'warehouse' => $b->warehouse?->displayName(),
                'expires' => $b->expires_on?->format('Y-m-d'),
                'days_left' => $b->daysLeft(),
                'qty' => (int) $b->qty_remaining,
            ])->values()->all(),
        ];
    }

    public static function vanStock(int $repId, User $user): array
    {
        $rep = self::guardedRep($repId, $user);

        if ($rep === null) {
            return self::notAvailable();
        }

        // نفس عهدة الأبلكيشن: العهدة الحالية وبنودها بمعادلة الأرضية
        $custody = $rep->currentCustody();

        if ($custody === null) {
            return ['rep' => $rep->displayName(), 'no_custody' => true,
                'note' => 'المندوب مالوش عهدة حالياً.'];
        }

        $custody->load('items.product');

        // تجميع بالصنف — نفس أرقام تاب «عهدتي» في الأبلكيشن
        $byProduct = [];

        foreach ($custody->items as $it) {
            $pid = (int) $it->product_id;
            $byProduct[$pid] ??= [
                'code' => $it->product?->code,
                'name' => $it->product?->displayName() ?? '#'.$pid,
                'loaded' => 0, 'sold' => 0, 'returned' => 0,
                'transferred' => 0, 'remaining' => 0,
            ];
            $byProduct[$pid]['loaded'] += (int) $it->assigned;
            $byProduct[$pid]['sold'] += (int) $it->sold;
            $byProduct[$pid]['returned'] += (int) $it->returned;
            $byProduct[$pid]['transferred'] += (int) $it->transferred_out;
            $byProduct[$pid]['remaining'] += $it->remaining();
        }

        $items = collect($byProduct)->filter(fn ($r) => $r['loaded'] > 0 || $r['remaining'] != 0)
            ->sortByDesc('remaining')->values();

        return [
            'rep_id' => $rep->id,
            'rep' => $rep->displayName(),
            'status' => $custody->status,
            'items_count' => $items->count(),
            'total_remaining' => (int) $items->sum('remaining'),
            'items' => $items->all(),
        ];
    }
}
