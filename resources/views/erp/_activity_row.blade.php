{{--
    خانتين «عمل إيه» و«التفاصيل» لصف واحد من سجل النشاط — مشتركين بين
    السجل الكامل وخط زمن اليوزر. `$r` = ActivityLog.
--}}
@php
    use App\Http\Controllers\ActivityController as AC;

    // المستند اللي الحركة عليه بيتفتح — إلا لو اتمسح
    $subjectRoutes = [
        'Client' => 'erp.clients.show', 'Product' => 'erp.products.show', 'Invoice' => 'ops.invoice',
        'PurchaseOrder' => 'ops.pos.show', 'ClientGroup' => 'erp.groups.show', 'Contract' => 'erp.contracts.show',
        'Warehouse' => 'erp.warehouses.stock', 'ClientReturn' => 'ops.returns.show', 'User' => 'erp.activity.user',
        'Supplier' => 'erp.suppliers.show', 'SupplierOrder' => 'erp.purchasing.show', 'Task' => 'erp.tasks.show',
        'StockTransfer' => 'wh.transfers.show', 'GoodsReceipt' => 'wh.receipt', 'PriceList' => 'erp.prices.show',
    ];
    $subjectUrl = ($r->subject_id && $r->event !== 'deleted' && isset($subjectRoutes[$r->subject_type]))
        ? rescue(fn () => route($subjectRoutes[$r->subject_type], $r->subject_id), null, false) : null;
    $failed = $r->status !== null && (int) $r->status >= 400;
@endphp
<td style="font-size:11.5px;white-space:normal;max-width:300px">
    @if ($r->subject_type)
        <b>{{ AC::modelLabel($r->subject_type) }}</b>
        @if ($subjectUrl)
            <a href="{{ $subjectUrl }}" target="_blank" rel="noopener">{{ $r->title }}</a>
        @else
            <span style="color:var(--muted)">{{ $r->title }}</span>
        @endif
    @elseif ($r->event === 'action')
        <b>{{ AC::verbLabel((string) $r->routeName()) }}</b>
        <span style="color:var(--muted);font-size:10.5px" dir="ltr">{{ $r->routeName() ?: $r->url }}</span>
    @elseif ($r->event === 'viewed')
        <span style="color:var(--muted)">{{ __('activity.opened_screen') }}</span>
    @else
        <span style="color:var(--muted)">{{ $r->title ?: '—' }}</span>
    @endif
    @if ($failed)
        <span class="badge b-red" title="{{ __('activity.failed_hint') }}">✖ {{ $r->status }}</span>
    @endif
</td>
<td style="font-size:11px;white-space:normal;max-width:380px">
    @if ($r->changedCount() > 0)
        {{-- قبل ← بعد للتعديل، والقيم المبعوتة للأكشن. أول 4 والباقي في «المزيد» --}}
        <details>
            <summary style="cursor:pointer;list-style:none">
                @foreach (array_slice($r->changes, 0, 3, true) as $field => $pair)
                    <div>
                        <span style="color:var(--muted)" dir="ltr">{{ $field }}:</span>
                        @if (is_array($pair))
                            <s style="color:var(--muted)">{{ $pair[0] === null || $pair[0] === '' ? '—' : $pair[0] }}</s>
                            → <b>{{ $pair[1] === null || $pair[1] === '' ? '—' : $pair[1] }}</b>
                        @else
                            <b>{{ $pair === null || $pair === '' ? '—' : $pair }}</b>
                        @endif
                    </div>
                @endforeach
                @if ($r->changedCount() > 3)
                    <span class="badge b-gray">+{{ $r->changedCount() - 3 }} {{ __('activity.more') }}</span>
                @endif
            </summary>
            @foreach (array_slice($r->changes, 3, null, true) as $field => $pair)
                <div>
                    <span style="color:var(--muted)" dir="ltr">{{ $field }}:</span>
                    @if (is_array($pair))
                        <s style="color:var(--muted)">{{ $pair[0] === null || $pair[0] === '' ? '—' : $pair[0] }}</s>
                        → <b>{{ $pair[1] === null || $pair[1] === '' ? '—' : $pair[1] }}</b>
                    @else
                        <b>{{ $pair === null || $pair === '' ? '—' : $pair }}</b>
                    @endif
                </div>
            @endforeach
        </details>
    @else
        <span style="color:var(--muted)" dir="ltr">{{ $r->url ?: '—' }}</span>
    @endif
</td>
