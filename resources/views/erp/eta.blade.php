@extends('layouts.system')

@section('title', __('tax.eta_page'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $blocked = collect($problems);
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.tax.settings') }}">⚙️ {{ __('tax.settings') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>🧾 {{ __('tax.eta_page') }} <span class="side">{{ __('tax.eta_sub') }}</span></h3>

    @if (! $taxOn)
        <div class="alert warn">{{ __('tax.tax_off_warning') }}</div>
    @endif
    @if (! $companyTaxId)
        <div class="alert warn">{{ __('tax.missing_tax_id') }}</div>
    @endif

    <div class="alert info">{{ __('tax.signing_notice') }}</div>

    {{-- ═══════════ الفترة ═══════════ --}}
    {{-- ⚠️ `all => false`: الفترة الفاضية هنا ليها افتراضي (`TaxController::period`) مش «كل الفترات» --}}
    <form method="GET" action="{{ route('erp.eta') }}" class="searchbar">
        @include('partials._range', ['from' => $from, 'to' => $to, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('erp.eta') }}">{{ __('common.clear') }}</a>
    </form>
</div>

{{-- ═══════════ الأرقام ═══════════ --}}
{{-- (٢٢/٩) كروت الحالة فلتر على جدول الفواتير تحت (`?st=`)، والصافي والضريبة هما إجمالي الجدول كله --}}
@php
    $stF = in_array(request('st'), ['ready', 'exported', 'submitted'], true) ? request('st') : null;
    $stUrl = fn (string $s) => request()->fullUrlWithQuery(['st' => $stF === $s ? null : $s]).'#eta-invoices';
    $shown = $stF ? $invoices->where('eta_status', $stF) : $invoices;
@endphp
<div class="kpis">
    <a @class(['kpi', 'on' => $stF === 'ready']) href="{{ $stUrl('ready') }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">{{ __('tax.invoices_ready') }}</div>
        <div class="val {{ $ready > 0 ? 'mid' : '' }}">{{ number_format($ready) }}</div>
        <div class="sub2">{{ __('tax.eta_status_ready') }}</div>
    </a>
    <a @class(['kpi', 'on' => $stF === 'exported']) href="{{ $stUrl('exported') }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">{{ __('tax.invoices_exported') }}</div>
        <div class="val">{{ number_format($exported) }}</div>
        <div class="sub2">{{ __('tax.eta_status_exported') }}</div>
    </a>
    <a @class(['kpi', 'on' => $stF === 'submitted']) href="{{ $stUrl('submitted') }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">{{ __('tax.invoices_submitted') }}</div>
        <div class="val pos">{{ number_format($submitted) }}</div>
        <div class="sub2">{{ __('tax.eta_status_submitted') }}</div>
    </a>
    <a class="kpi" href="{{ request()->fullUrlWithQuery(['st' => null]) }}#eta-invoices">
        <div class="lbl">{{ __('tax.net_sales') }}</div>
        <div class="val num">{{ $fmt($netTotal) }}</div>
        <div class="sub2">{{ __('common.currency') }}</div>
    </a>
    <a class="kpi" href="{{ request()->fullUrlWithQuery(['st' => null]) }}#eta-invoices">
        <div class="lbl">{{ __('tax.tax_collected') }}</div>
        <div class="val num">{{ $fmt($taxTotal) }}</div>
        <div class="sub2">{{ __('common.currency') }}</div>
    </a>
</div>

{{-- ═══════════ الفواتير المرفوضة ═══════════ --}}
@if ($blocked->count() > 0)
<div class="card">
    <h3>⚠️ {{ __('tax.blocked_rows') }} <span class="side">{{ $blocked->count() }}</span></h3>
    <div class="alert warn">{{ __('tax.blocked_hint') }}</div>
</div>
@endif

{{-- ═══════════ الأزرار ═══════════ --}}
<div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="POST" action="{{ route('erp.eta.export') }}">
        @csrf
        <input type="hidden" name="from" value="{{ $from }}">
        <input type="hidden" name="to" value="{{ $to }}">
        <button class="btn gold">⬇️ {{ __('tax.export') }}</button>
    </form>

    <form method="POST" action="{{ route('erp.eta.submitted') }}" onsubmit="return confirm(MARK_CONFIRM)">
        @csrf
        <input type="hidden" name="from" value="{{ $from }}">
        <input type="hidden" name="to" value="{{ $to }}">
        <button class="btn green" @disabled($exported === 0)>✅ {{ __('tax.mark_submitted') }}</button>
    </form>

    <span style="font-size:11.5px;color:var(--muted)">{{ __('tax.export_hint') }}</span>
</div>

{{-- ═══════════ الفواتير ═══════════ --}}
<div class="card" id="eta-invoices">
    <h3>📄 {{ __('ops.all_invoices') }} <span class="side">{{ $shown->count() }}@if ($stF) / {{ $invoices->count() }}@endif</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('tax.invoice_no') }}</th>
                <th>{{ __('common.date') }}</th>
                <th>{{ __('client.client') }}</th>
                <th data-nosum>{{ __('tax.client_tax_id') }}</th>
                <th class="num">{{ __('tax.net_before_tax') }}</th>
                <th class="num">{{ __('tax.tax') }}</th>
                <th class="num">{{ __('tax.total_due') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>

            @forelse ($shown as $inv)
                @php $rowProblems = $problems[$inv->id] ?? []; @endphp
                <tr>
                    <td>
                        <a href="{{ route('ops.invoice', $inv) }}"><b>{{ $inv->number }}</b></a>
                    </td>
                    <td class="num s">{{ $inv->created_at->format('Y-m-d') }}</td>
                    <td><a href="{{ route('erp.clients.show', $inv->client_id) }}">{{ $inv->client->displayName() }}</a></td>
                    <td class="num s">{{ $inv->client->tax_id ?: '—' }}</td>
                    <td class="num">{{ $fmt($inv->total) }}</td>
                    <td class="num">{{ $fmt($inv->tax_total) }}</td>
                    <td class="num"><b>{{ $fmt($inv->payable()) }}</b></td>
                    <td>
                        @if ($rowProblems)
                            <span class="badge b-red">{{ $rowProblems[0] }}</span>
                        @else
                            <span class="badge {{ $inv->etaStatusClass() }}">{{ $inv->etaStatusLabel() }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('tax.no_invoices') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection

@section('scripts')
<script>
    {{-- ⚠️ في ثابت مش جوه onsubmit — الأبوستروف بيكسّر الجافاسكريبت --}}
    const MARK_CONFIRM = @js(__('tax.mark_submitted_confirm', ['count' => $exported]));
</script>
@endsection
