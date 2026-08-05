@extends('layouts.system')

{{--
    لوحة التوريد — كي أكاونت وأونلاين (تطوير كامل 2026-08-05):

    KPIs كفلاتر بضغطة ← فلاتر (بحث بالفرع/الرقم، قناة، سلسلة، موافقة،
    حالة، من/إلى) ← جدول بهيدر ثابت وتراك كامل (أنشأ/وافق/عدّل) ←
    الإنشاء من نفس المكان: يدوي سريع، تسليم PO للمندوب، أو رفع شيتات.
    الموافقات كلها بتروح للحسابات — والأزرار محكومة بأكشنز الصلاحيات.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`
    $manager = auth()->user()->canDecideOps();
    $f = $filters;
    // لينك KPI بيحافظ على باقي الفلاتر ويصفّر الصفحة
    $kpiLink = fn (array $set) => request()->fullUrlWithQuery(
        $set + ['status' => null, 'approval' => null, 'late' => null, 'page' => 1],
    );
@endphp

@section('title', __('ops.purchase_orders'))

@section('actions')
    @if ($manager && \App\Support\Access::action(auth()->user(), 'act.ka.create'))
        <a class="btn" href="{{ route('ops.po.import') }}">⬆️ {{ __('ops.po_import') }}</a>
        <button class="btn" onclick="openDlg('dlgNewPo')">+ {{ __('ops.purchase_order') }}</button>
        <a class="btn gold" href="{{ route('ops.po.handout') }}">📦 {{ __('ops.po_handout') }}</a>
    @endif
@endsection

@section('content')

{{-- ═══ الأوفرفيو: كل كارت فلتر بضغطة — وبنفس فلاتر القايمة ═══ --}}
<div class="kpis">
    <a class="kpi" href="{{ $kpiLink([]) }}" style="text-decoration:none;color:inherit">
        <div class="lbl">🚚 {{ __('ops.total_orders') }}</div>
        <div class="val">{{ $fmt($kpi['total']) }}</div>
        <div class="sub2">{{ __('ops.kpi_value') }}: <b>{{ $fmt($kpi['value']) }}</b></div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['approval' => 'pending']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['approval'] ?? '') === 'pending' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">🔏 {{ __('enums.po_approval.pending') }}</div>
        <div class="val mid">{{ $fmt($kpi['pending']) }}</div>
        <div class="sub2">{{ __('ops.kpi_pending_hint') }}</div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['approval' => 'approved']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['approval'] ?? '') === 'approved' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">✅ {{ __('enums.po_approval.approved') }}</div>
        <div class="val pos">{{ $fmt($kpi['approved']) }}</div>
        <div class="sub2">{{ __('ops.kpi_approved_hint') }}</div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['approval' => 'rejected']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['approval'] ?? '') === 'rejected' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">⛔ {{ __('enums.po_approval.rejected') }}</div>
        <div class="val neg">{{ $fmt($kpi['rejected']) }}</div>
        <div class="sub2">{{ __('ops.kpi_rejected_hint') }}</div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['status' => 'delivered']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['status'] ?? '') === 'delivered' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">📬 {{ __('enums.po_status.delivered') }}</div>
        <div class="val pos">{{ $fmt($kpi['delivered']) }}</div>
        <div class="sub2">{{ __('ops.kpi_delivered_hint') }}</div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['late' => 1]) }}"
       style="text-decoration:none;color:inherit;{{ ($f['late'] ?? false) ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">⏰ {{ __('ops.po_late') }}</div>
        <div class="val {{ $kpi['late'] > 0 ? 'neg' : 'pos' }}">{{ $fmt($kpi['late']) }}</div>
        <div class="sub2">{{ __('ops.kpi_late_hint') }}</div>
    </a>
</div>

<div class="card">
    <h3>🚚 {{ __('ops.orders') }} <span class="side">{{ $pos->total() }} {{ trans_choice('ops.order_count', $pos->total()) }}</span></h3>

    {{-- الفلاتر: بحث ← القناة ← السلسلة ← الموافقة ← الحالة ← من/إلى --}}
    <form class="searchbar" method="GET">
        @if ($f['late'] ?? false)<input type="hidden" name="late" value="1">@endif
        <input type="text" name="q" value="{{ $f['q'] ?? '' }}"
               placeholder="🔍 {{ __('ops.search_po_ph') }}" style="flex:1;min-width:220px">
        <select name="channel" style="min-width:130px">
            <option value="">{{ __('client.all_channels') }}</option>
            @foreach ($channels as $ch)
                <option value="{{ $ch->id }}" @selected((int) ($f['channel'] ?? 0) === $ch->id)>{{ $ch->displayName() }}</option>
            @endforeach
        </select>
        <select name="group" style="min-width:140px">
            <option value="">— {{ __('nav.chains') }} —</option>
            @foreach ($groups as $g)
                <option value="{{ $g->id }}" @selected((int) ($f['group'] ?? 0) === $g->id)>{{ $g->displayName() }}</option>
            @endforeach
        </select>
        <select name="approval" style="min-width:130px">
            <option value="">{{ __('ops.decision') }}: {{ __('common.all') }}</option>
            @foreach (['pending', 'approved', 'rejected'] as $a)
                <option value="{{ $a }}" @selected(($f['approval'] ?? '') === $a)>{{ __('enums.po_approval.'.$a) }}</option>
            @endforeach
        </select>
        <select name="status" style="min-width:120px">
            <option value="">{{ __('common.status') }}: {{ __('common.all') }}</option>
            @foreach (array_keys(\App\Models\PurchaseOrder::STATUSES) as $k)
                <option value="{{ $k }}" @selected(($f['status'] ?? '') === $k)>{{ __('enums.po_status.'.$k) }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $f['from'] ?? '' }}" style="width:135px" title="{{ __('common.from') }}">
        <input type="date" name="to" value="{{ $f['to'] ?? '' }}" style="width:135px" title="{{ __('common.to') }}">
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('ops.pos') }}">{{ __('common.clear') }}</a>
    </form>

    {{-- هيدر ثابت — القايمة طويلة والأعمدة بتضيع وانت نازل --}}
    <div class="tablewrap" style="max-height:62vh;overflow-y:auto">
        <table>
            <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
            <tr>
                <th>{{ __('ops.order') }}</th>
                <th>{{ __('ops.branch_client') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('ops.due_at') }}</th>
                <th class="num">{{ __('ops.units') }}</th>
                <th class="num">{{ __('stock.value') }}</th>
                <th>{{ __('ops.decision') }}</th>
                <th>{{ __('common.status') }}</th>
                <th>{{ __('ops.track') }}</th>
                <th style="width:110px"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($pos as $po)
                <tr>
                    <td class="num"><b>{{ $po->number }}</b>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $po->created_at->format('m-d') }}
                            @if ($po->source) · {{ $po->source }}@endif</span>
                    </td>
                    <td>
                        <b>{{ $po->client?->fullName() ?? '—' }}</b>
                        @if ($po->client?->channel)
                            <br><span class="badge {{ $po->client->channel->badgeClass() }}" style="font-size:9.5px">{{ $po->client->channel->displayName() }}</span>
                        @endif
                    </td>
                    <td>{{ $po->courier?->displayName() ?? '—' }}</td>
                    {{-- معاد التوريد بالساعة + شارة التأخير --}}
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
                    {{-- التراك: مين أنشأ / وافق / عدّل — كله موثق --}}
                    <td style="font-size:10.5px;color:var(--muted);line-height:1.9">
                        ✍️ {{ $po->creator?->name ?? '—' }}
                        @if ($po->approvedBy)<br>🔏 {{ $po->approvedBy->name }} <span dir="ltr">{{ $po->approved_at?->format('m-d H:i') }}</span>@endif
                        @if ($po->editor)<br>✏️ {{ $po->editor->name }} <span dir="ltr">{{ $po->edited_at?->format('m-d H:i') }}</span>@endif
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <a class="btn sm" href="{{ route('ops.po.print', $po) }}" target="_blank" title="{{ __('ops.print') }}">🖨️</a>
                            @if ($po->approval_status === 'pending' && \App\Support\Access::action(auth()->user(), 'act.ka.edit'))
                                <a class="btn sm" href="{{ route('ops.po.edit', $po) }}" title="{{ __('ops.po_edit') }}">✏️</a>
                            @endif
                            @if ($manager && $po->status === 'pending' && ! $po->needsApproval())
                                <button class="btn sm" onclick="assignPo({{ $po->id }}, '{{ $po->number }}')">{{ __('ops.assign') }}</button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.no_orders') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials._pagination', ['p' => $pos])
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
                        <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="f">{{ __('ops.source') }}</label><input type="text" name="source" value="" placeholder="{{ __('ops.source') }}" style="width:100%"></div>
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
                                @foreach ($factors as $unitKey => $factor)
                                    <option value="{{ $unitKey }}">{{ __('stock.unit_'.$unitKey) }}@if ($factor > 1) ({{ $factor }})@endif</option>
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
            const factor = factors[unit] || 1;

            eq.textContent = (factor > 1 && qty > 0)
                ? '= ' + (qty * factor).toLocaleString() + ' ' + @json(__('stock.unit_piece'))
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
