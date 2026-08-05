@extends('layouts.system')

@section('title', __('ops.purchase_orders'))

@php $fmt = fn ($n) => number_format((float) $n);         // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`،
    // و`isManager()` بترجّع له true — فكان بيشوف الزرار ويترمي على
    // 403 بعد ما يملا الفورم.
    $manager = auth()->user()->canDecideOps(); @endphp

@section('actions')
    @if ($manager)
        {{-- فلو الكي أكاونت: إنشاء بموافقة الحسابات --}}
        @if (\App\Support\Access::action(auth()->user(), 'act.ka.create'))<a class="btn" href="{{ route('ops.po.handout') }}">📦 {{ __('ops.po_handout') }}</a>@endif
        <button class="btn gold" onclick="openDlg('dlgNewPo')">+ {{ __('ops.purchase_order') }}</button>
    @endif
@endsection

@section('content')

<div class="card" style="padding:10px 12px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn {{ ! ($filters['status'] ?? null) ? 'gold' : '' }}" href="{{ route('ops.pos') }}">{{ __('common.all') }}</a>
        @foreach (array_keys(\App\Models\PurchaseOrder::STATUSES) as $k)
            <a class="btn {{ ($filters['status'] ?? '') === $k ? 'gold' : '' }}" href="{{ route('ops.pos', ['status' => $k]) }}">{{ __('enums.po_status.'.$k) }}</a>
        @endforeach
    </div>
</div>

<div class="card">
    <h3>🚚 {{ __('ops.orders') }} <span class="side">{{ $pos->total() }} {{ trans_choice('ops.order_count', $pos->total()) }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('ops.order') }}</th><th>{{ __('client.client') }}</th><th>{{ __('ops.source') }}</th>
                <th>{{ __('ops.rep') }}</th><th>{{ __('ops.due_at') }}</th><th>{{ __('ops.units') }}</th>
                <th>{{ __('stock.value') }}</th><th>{{ __('ops.decision') }}</th><th>{{ __('common.status') }}</th>@if ($manager)<th></th>@endif
            </tr>
            @forelse ($pos as $po)
                <tr>
                    <td class="num"><b>{{ $po->number }}</b><br><span style="font-size:10.5px;color:var(--muted)">{{ $po->created_at->format('m-d') }}</span></td>
                    <td><b>{{ $po->client->displayName() }}</b></td>
                    <td><span class="badge {{ $po->sourceClass() }}">{{ $po->sourceLabel() }}</span></td>
                    <td>{{ $po->courier?->displayName() ?? '—' }}</td>
                    {{-- معاد التوريد بالساعة + شارة التأخير — فلو الكي أكاونت --}}
                    <td style="font-size:11.5px">
                        {{ $po->due_at?->format('m-d H:i') ?? $po->due_date?->format('m-d') ?? '—' }}
                        @if ($po->isLate())<br><span class="badge b-red" style="font-size:9.5px">⏰ {{ __('ops.po_late') }}</span>@endif
                    </td>
                    <td class="num">{{ $po->qtyTotal() }}
                        {{-- الفرق بعد التسليم: سلم إيه وناقص إيه --}}
                        @if ($po->status === 'delivered' && $po->qtyTotal() !== $po->deliveredQtyTotal())
                            <br><span style="font-size:10px;color:#B86E00;font-weight:800">{{ __('ops.po_delivered_qty') }} {{ $po->deliveredQtyTotal() }} · {{ __('ops.po_variance') }} {{ $po->qtyTotal() - $po->deliveredQtyTotal() }}</span>
                        @endif
                    </td>
                    <td class="num pos">{{ $fmt($po->total) }}</td>
                    <td>
                        @if ($po->needsApproval())
                            <span class="badge {{ $po->approvalClass() }}">{{ $po->approvalLabel() }}</span>
                            @if ($po->was_edited)<br><span class="badge b-orange" style="font-size:9.5px">{{ __('ops.edited') }}</span>@endif
                        @else
                            <span class="badge b-gray">—</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $po->statusClass() }}">{{ $po->statusLabel() }}</span>
                        @if ($po->delivered_at)<br><span style="font-size:10.5px;color:var(--muted)">{{ $po->delivered_at->format('H:i') }}</span>@endif
                    </td>
                    @if ($manager)
                        <td>
                            @if ($po->status === 'pending')
                                <button class="btn sm" onclick="assignPo({{ $po->id }}, '{{ $po->number }}')">{{ __('ops.assign') }}</button>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.no_orders') }}</td></tr>
            @endforelse
        </table>
    </div>
    <div class="pag">{{ $pos->links('pagination::simple-default') }}</div>
</div>

@if ($manager)
<dialog id="dlgNewPo">
    <form class="dlg" method="POST" action="{{ route('ops.pos.store') }}">
        @csrf
        <h4>{{ __('ops.new_purchase_order') }}</h4>
        <div class="frow">
            <div><label class="f">{{ __('client.client') }}</label>
                <select name="client_id" required style="width:100%">
                    @foreach ($clients as $c)
                        <option value="{{ $c->id }}" @selected($c->name === 'Gourrmet Egypt')>{{ $c->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="f">{{ __('ops.source') }}</label><input type="text" name="source" value=""  placeholder="{{ __('ops.source') }}" style="width:100%"></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('ops.address_or_branch') }}</label><input type="text" name="address" style="width:100%"></div>
            <div><label class="f">{{ __('ops.driver') }}</label>
                <select name="assigned_to" style="width:100%">
                    <option value="">— {{ __('ops.not_yet') }} —</option>
                    @foreach ($couriers as $co)<option value="{{ $co->id }}">{{ $co->name }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('ops.pricing') }}</label>
                <select name="price_mode" style="width:100%">
                    {{-- الافتراضي: تسعيرة العميل بخصمه، زي الفاتورة بالظبط --}}
                    <option value="channel">{{ __('enums.price_mode.channel') }}</option>
                    <option value="new">{{ __('enums.price_mode.new') }}</option>
                    <option value="old">{{ __('enums.price_mode.old') }}</option>
                </select>
            </div>
            <div><label class="f">{{ __('ops.delivery_date') }}</label><input type="date" name="due_date" style="width:100%"></div>
        </div>

        <label class="f">{{ __('ops.items_and_qty') }}</label>
        <div style="max-height:40vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
            <table>
                @foreach ($products as $p)
                    @php $factors = $p->unitFactors(); @endphp
                    <tr>
                        <td>{{ $p->code }} — {{ $p->displayName() }}</td>
                        {{-- الوحدة: العرض بس — السيرفر بيعيد الضرب في storePurchaseOrder --}}
                        <td style="width:96px">
                            <select name="unit[{{ $p->id }}]" data-po-unit="{{ $p->id }}" style="width:100%" onchange="poEq({{ $p->id }})">
                                @foreach ($factors as $u => $f)
                                    <option value="{{ $u }}">{{ __('stock.unit_'.$u) }}@if ($f > 1) ({{ $f }})@endif</option>
                                @endforeach
                            </select>
                        </td>
                        <td style="width:100px">
                            <input type="number" min="0" name="qty[{{ $p->id }}]" placeholder="0" style="width:100%"
                                   data-po-qty="{{ $p->id }}" oninput="poEq({{ $p->id }})">
                            <div data-po-eq="{{ $p->id }}" data-factors='@json($factors)' style="font-size:10px;color:var(--muted)"></div>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
        <script>
        /* «= N قطعة» — عرض بس، الضرب في السيرفر */
        function poEq(id) {
            const eq = document.querySelector('[data-po-eq="' + id + '"]');
            const factors = JSON.parse(eq.dataset.factors);
            const unit = document.querySelector('[data-po-unit="' + id + '"]').value;
            const qty = Number(document.querySelector('[data-po-qty="' + id + '"]').value || 0);
            const f = factors[unit] || 1;

            eq.textContent = (f > 1 && qty > 0)
                ? '= ' + (qty * f).toLocaleString() + ' ' + @json(__('stock.unit_piece'))
                : '';
        }
        </script>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewPo')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.create') }}</button>
        </div>
    </form>
</dialog>

<dialog id="dlgAssign">
    <form class="dlg" method="POST" id="formAssign">
        @csrf
        <h4 id="aTitle">{{ __('ops.assign_to_driver') }}</h4>
        <label class="f">{{ __('ops.driver') }}</label>
        <select name="assigned_to" required style="width:100%">
            @foreach ($couriers as $co)<option value="{{ $co->id }}">{{ $co->name }} ({{ $co->code }})</option>@endforeach
        </select>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgAssign')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('ops.assign') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<script>
function assignPo(id, number) {
    const tpl = {!! json_encode(__('ops.assign_order', ['number' => '#N#']), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
    document.getElementById('aTitle').textContent = tpl.replace('#N#', number);
    document.getElementById('formAssign').action = '{{ url('ops/pos') }}/' + id + '/assign';
    openDlg('dlgAssign');
}
</script>
@endsection
