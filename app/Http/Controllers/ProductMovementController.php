<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Product;
use App\Services\ProductMovements;
use App\Support\Csv;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * تصدير حركة الأصناف بالكمية — لعميل واحد أو لسلسلة كلها (٨ سبتمبر ٢٠٢٦).
 *
 * `?view=detail` (الافتراضي): كل سطر مستند — التاريخ، الحركة، المستند،
 * الفرع، الصنف، الكمية، السعر، الإجمالي. `?view=summary`: صنف بصنف
 * مجمّع بالعائلة. فترة اختيارية `from`/`to`.
 *
 * ⚠️ نفس سكوب الشاشة: العميل بـ`Scope::assertClient`، وفروع السلسلة
 * بـ`Client::visibleTo` — فلترة القايمة مش حماية.
 */
class ProductMovementController extends Controller
{
    public function client(Client $client, Request $request)
    {
        Scope::assertClient($request->user(), $client);

        return $this->export(
            [$client->id],
            [$client->id => $client->displayName()],
            'movements-'.$client->code,
            $request,
        );
    }

    public function group(ClientGroup $group, Request $request)
    {
        $branches = Client::visibleTo($group->clients(), $request->user())
            ->get(['id', 'code', 'name', 'name_en']);

        return $this->export(
            $branches->pluck('id')->all(),
            $branches->mapWithKeys(fn (Client $b) => [$b->id => $b->displayName()])->all(),
            'movements-chain-'.$group->code,
            $request,
        );
    }

    /**
     * @param  list<int>  $clientIds
     * @param  array<int, string>  $clientNames
     */
    private function export(array $clientIds, array $clientNames, string $name, Request $request)
    {
        $from = $this->dateOrNull($request->input('from'));
        $to = $this->dateOrNull($request->input('to'));
        $stamp = now()->format('Y-m-d-Hi');

        if ($request->input('view') === 'summary') {
            $summary = ProductMovements::summary($clientIds, $from, $to);
            $rows = [];

            foreach ($summary['families'] as $family) {
                foreach ($family['products'] as $p) {
                    $rows[] = [
                        $family['label'], $p['product']->code, $p['product']->displayName(),
                        $p['sold_qty'], $p['returned_good'], $p['returned_damaged'], $p['gift_qty'], $p['net_qty'],
                        Csv::money($p['avg_price']), Csv::money($p['sold_value']),
                        $p['first_at']?->format('Y-m-d') ?? '', $p['last_at']?->format('Y-m-d') ?? '',
                    ];
                }
            }

            $t = $summary['totals'];

            return Csv::download($name.'-summary-'.$stamp.'.csv', [
                __('stock.family'), __('common.code'), __('stock.item'),
                __('client.sold_qty'), __('client.returned_good'), __('client.returned_damaged'), __('client.gift_qty'), __('client.net_qty'),
                __('client.avg_price'), __('client.sold_value'), __('client.first_withdrawal'), __('client.last_withdrawal'),
            ], $rows, [
                __('common.total'), '', '',
                $t['sold_qty'], '', '', $t['gift_qty'], $t['net_qty'], '', Csv::money($t['sold_value']), '', '',
            ]);
        }

        $movements = ProductMovements::rows($clientIds, $from, $to);
        $products = Product::whereIn('id', $movements->pluck('product_id')->unique()->all())->get()->keyBy('id');
        $rows = [];
        $sold = 0;
        $returned = 0;
        $gifted = 0;
        $value = 0.0;

        foreach ($movements as $m) {
            $product = $products->get($m->product_id);
            $rows[] = [
                Carbon::parse($m->at)->format('Y-m-d'),
                ProductMovements::kindLabel($m->kind),
                (string) ($m->doc ?? ''),
                $clientNames[$m->client_id] ?? (string) $m->client_id,
                $product?->code ?? '', $product?->displayName() ?? '', $product?->familyLabel() ?? '',
                $m->qty,
                $m->kind === ProductMovements::KIND_GIFT ? '' : Csv::money($m->price),
                $m->kind === ProductMovements::KIND_GIFT ? '' : Csv::money($m->total),
                $m->condition ? __('client.cond_'.$m->condition) : '',
            ];

            if ($m->kind === ProductMovements::KIND_RETURN) {
                $returned += $m->qty;
            } elseif ($m->kind === ProductMovements::KIND_GIFT) {
                $gifted += $m->qty;
            } else {
                $sold += $m->qty;
                $value += $m->total;
            }
        }

        return Csv::download($name.'-'.$stamp.'.csv', [
            __('common.date'), __('client.movement_kind'), __('client.movement_doc'), __('client.branch'),
            __('common.code'), __('stock.item'), __('stock.family'),
            __('common.qty'), __('client.unit_price'), __('common.total'), __('client.condition'),
        ], $rows, [
            __('common.total'), '', '', '', '', '', '',
            __('client.sold_qty').' '.$sold.' / '.__('client.returned_qty').' '.$returned.' / '.__('client.gift_qty').' '.$gifted,
            '', Csv::money($value), '',
        ]);
    }

    private function dateOrNull(mixed $raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
