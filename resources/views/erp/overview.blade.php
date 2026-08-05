@extends('layouts.system')

@section('title', __('report.overview'))

@php
    use App\Models\Client;
    use App\Models\Product;
    $fmt = fn ($n) => number_format((float) $n);
    $colRate = $totals->purchases > 0 ? $totals->collections / $totals->purchases : 0;
    $agingTotal = array_sum($aging) ?: 1;
    $isRtl = app()->getLocale() === 'ar';
    $cur = __('common.currency');
@endphp

@section('actions')
    @if (auth()->user()->isAdmin())
        {{-- فلو التيست (قرار المالك 2026-08-04): ديمو ← تجربة ← مسح ← استيراد رصيد أول المدة --}}
        @if (\App\Support\Access::action(auth()->user(), 'act.overview.wipe'))<button class="btn" onclick="openDlg('dlgDemo')">🧪 {{ __('ops.demo_btn') }}</button>@endif
        @if (\App\Support\Access::action(auth()->user(), 'act.overview.wipe'))<button class="btn red" onclick="openDlg('dlgWipe')">🧨 {{ __('ops.wipe_btn') }}</button>@endif
    @endif
@endsection

@section('content')

@if (auth()->user()->isAdmin())
    {{-- ═══ مسح الترانزاكشنز — تأكيد بالكتابة، مش ضغطة ═══ --}}
    <dialog id="dlgWipe">
        <form class="dlg" method="POST" action="{{ route('erp.wipe') }}">
            @csrf
            <h4>🧨 {{ __('ops.wipe_btn') }}</h4>
            <div class="alert warn" style="margin:10px 0">
                <span>⚠️</span><span>{{ __('ops.wipe_warning') }}</span>
            </div>
            <ul style="font-size:12px;color:var(--muted);margin:0 0 10px;padding-inline-start:18px;line-height:1.9">
                <li>{{ __('ops.wipe_goes') }}</li>
                <li>{{ __('ops.wipe_stays') }}</li>
            </ul>
            <label class="f">{{ __('ops.wipe_type_confirm') }}</label>
            <input type="text" name="confirm" dir="ltr" autocomplete="off" required
                   placeholder="WIPE" style="width:100%;font-weight:900;letter-spacing:2px">
            @error('confirm')<div class="errline">{{ $message }}</div>@enderror
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button class="btn" type="button" onclick="closeDlg('dlgWipe')">{{ __('common.cancel') }}</button>
                <button class="btn red" type="submit">🧨 {{ __('ops.wipe_do') }}</button>
            </div>
        </form>
    </dialog>

    {{-- ═══ داتا ديمو — بتمشي في الفلو الحقيقي (إذن ← ترصيف ← عهدة) ═══ --}}
    <dialog id="dlgDemo">
        <form class="dlg" method="POST" action="{{ route('erp.demo') }}">
            @csrf
            <h4>🧪 {{ __('ops.demo_btn') }}</h4>
            <p style="font-size:12.5px;color:var(--muted)">{{ __('ops.demo_hint') }}</p>
            <label class="f">{{ __('ops.rep') }}</label>
            <select name="email" required style="width:100%">
                @foreach (\App\Models\User::whereIn('role', ['sales_agent', 'driver'])->where('active', true)->orderBy('name')->get() as $r)
                    <option value="{{ $r->email }}">{{ $r->name }}</option>
                @endforeach
            </select>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button class="btn" type="button" onclick="closeDlg('dlgDemo')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">🧪 {{ __('ops.demo_do') }}</button>
            </div>
        </form>
    </dialog>

    {{-- الفاليديشن رفضت كلمة التأكيد؟ نرجّع الديالوج مفتوح --}}
    @if ($errors->has('confirm'))
        <script>document.addEventListener('DOMContentLoaded', () => openDlg('dlgWipe'));</script>
    @endif
@endif

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('report.total_purchases_ledger') }}</div>
        <div class="val" style="color:var(--primary)">{{ $fmt($totals->purchases) }} {{ $cur }}</div>
        <div class="sub2">{{ $totals->n_clients }} {{ __('report.registered_clients') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.cash_collection') }}</div>
        <div class="val pos">{{ $fmt($totals->collections) }} {{ $cur }}</div>
        <div class="sub2">{{ __('report.collection_rate') }} {{ number_format($colRate * 100, 1) }}%</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('report.remaining_balance') }}</div>
        <div class="val neg">{{ $fmt($totals->balance) }} {{ $cur }}</div>
        <div class="sub2">{{ __('report.debt_over_90') }}: {{ $fmt($aging['a90'] + $aging['a180'] + $aging['a180p']) }} {{ $cur }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('report.stock_value') }}</div>
        <div class="val">{{ $fmt($stockValue) }} {{ $cur }}</div>
        <div class="sub2">{{ __('report.stock_value_at', ['price' => __('stock.price_new')]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('report.sales_today') }}</div>
        <div class="val pos">{{ $fmt($todayInvoices) }} {{ $cur }}</div>
        <div class="sub2">{{ __('ops.purchase_orders') }} {{ __('report.delivered') }}: {{ $fmt($todayPos) }} {{ $cur }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('ops.pending_client_requests') }}</div>
        <div class="val mid">{{ $openRequests }}</div>
        <div class="sub2"><a href="{{ route('ops.requests') }}" style="color:var(--blue);font-weight:800">{{ __('ops.review_them') }} {{ $isRtl ? '←' : '→' }}</a></div>
    </div>
</div>

<div class="card">
    <h3>⚠️ {{ __('report.alerts') }}</h3>
    <div class="alerts">
        <div class="alert"><div>🔴 <b>{{ $catCounts['danger'] ?? 0 }} {{ __('client.client_count') }}</b> {{ __('report.collect_now_note') }}.</div></div>
        <div class="alert warn"><div>⏳ <b>{{ $fmt($aging['a180'] + $aging['a180p']) }} {{ $cur }}</b> {{ __('report.debt_over_90_note') }}.</div></div>
        <div class="alert good"><div>🟢 <b>{{ $catCounts['grow'] ?? 0 }} {{ __('client.client_count') }}</b> {{ __('report.in_grow') }} — {{ __('report.grow_opportunity') }}.</div></div>
        <div class="alert info"><div>📜 <b>{{ Client::where('discount','>',0)->count() }}</b> {{ __('report.clients_with_contracts') }} — {{ __('report.of_total') }} {{ $totals->n_clients }}.</div></div>
    </div>
</div>

<div class="grid2">
    <div class="card"><h3>{{ __('report.revenue_by_family') }}</h3><div class="chartbox"><canvas id="chFam"></canvas></div></div>
    <div class="card"><h3>{{ __('report.aging_estimated') }}</h3><div class="chartbox"><canvas id="chAging"></canvas></div></div>
</div>

<div class="card">
    <h3>{{ __('report.monthly_movement') }}</h3>
    <div class="chartbox"><canvas id="chMonthly"></canvas></div>
</div>

<div class="card">
    <h3>{{ __('report.top_clients', ['count' => $top->count()]) }} <span class="side">{{ __('report.click_client_hint') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr><th>#</th><th>{{ __('client.client') }}</th><th>{{ __('client.category') }}</th><th>{{ __('client.zone') }}</th><th>{{ __('client.purchases') }}</th><th>{{ __('client.collected') }}</th><th>{{ __('report.remaining_balance') }}</th><th>{{ __('report.collection_rate') }} %</th></tr>
            @foreach ($top as $i => $c)
                <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                    <td>{{ $i + 1 }}</td>
                    <td><b>{{ $c->fullName() }}</b></td>
                    <td><span class="badge {{ $c->categoryClass() }}">{{ $c->categoryLabel() }}</span></td>
                    <td style="color:var(--muted)">{{ $c->zone?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $fmt($c->purchases) }}</td>
                    <td class="num pos">{{ $fmt($c->collections) }}</td>
                    <td class="num {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</td>
                    <td class="num">{{ number_format($c->collectionRate() * 100, 1) }}%</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

@endsection

@section('scripts')
@php
    // المسميات بتيجي من enums.family — والثابت القديم fallback
    $famLabels = array_map(
        fn ($f) => \Illuminate\Support\Facades\Lang::has('enums.family.'.$f)
            ? __('enums.family.'.$f)
            : (Product::FAMILIES[$f] ?? $f),
        array_keys($byFamily),
    );
    $famValues = array_map(fn ($v) => round((float) $v), array_values($byFamily));
    $agLabels = [
        __('report.days_0_30'), __('report.days_31_60'), __('report.days_61_90'),
        __('report.days_91_180'), __('report.days_180_plus'),
    ];
    $agValues = [round($aging['a30']), round($aging['a60']), round($aging['a90']), round($aging['a180']), round($aging['a180p'])];
    $mLabels = $monthly->pluck('m')->all();
    $mSales = $monthly->map(fn ($r) => round((float) $r->sales))->all();
    $mColl = $monthly->map(fn ($r) => round((float) $r->coll))->all();

    // نصوص الشارتس بتتحوّل JSON عشان ما تكسرش الجافاسكريبت
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
    $lblOutstanding = json_encode(__('report.outstanding'), $jsonFlags);
    $lblSales = json_encode(__('report.sales'), $jsonFlags);
    $lblCollections = json_encode(__('report.collections'), $jsonFlags);
@endphp
<script>
new Chart(document.getElementById('chFam'), {
    type:'doughnut',
    data:{ labels:{!! json_encode($famLabels, $jsonFlags) !!},
           datasets:[{ data:{!! json_encode($famValues) !!}, backgroundColor:PALETTE, borderColor:'#fff', borderWidth:3, hoverOffset:6 }] },
    options:{ cutout:'58%', plugins:{ legend:{ position:'bottom' } } },
});
new Chart(document.getElementById('chAging'), {
    type:'bar',
    data:{ labels:{!! json_encode($agLabels, $jsonFlags) !!},
           datasets:[{ label:{!! $lblOutstanding !!}, data:{!! json_encode($agValues) !!},
           backgroundColor:[BRAND.green,BRAND.blue,BRAND.royal,BRAND.orange,BRAND.red], borderRadius:8, maxBarThickness:64 }] },
    options:{ plugins:{ legend:{ display:false } }, scales:AXES },
});
new Chart(document.getElementById('chMonthly'), {
    type:'line',
    data:{ labels:{!! json_encode($mLabels) !!},
        datasets:[
            { label:{!! $lblSales !!}, data:{!! json_encode($mSales) !!}, borderColor:BRAND.royal, backgroundColor:'rgba(18,57,155,.12)', fill:true, tension:.35, borderWidth:2.5, pointRadius:3 },
            { label:{!! $lblCollections !!}, data:{!! json_encode($mColl) !!}, borderColor:BRAND.green, backgroundColor:'rgba(22,163,74,.10)', fill:true, tension:.35, borderWidth:2.5, pointRadius:3 },
        ] },
    options:{ interaction:{ mode:'index', intersect:false }, plugins:{ legend:{ position:'bottom' } }, scales:AXES },
});
</script>
@endsection
