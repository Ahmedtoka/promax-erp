@extends('layouts.system')

{{--
    سجل تعديلات الأسعار (Lane 13) — قراءة للأدمن والمحاسب.
    الكروت والجدول والتصدير كلهم على نفس الكويري المفلترة.
    ⚠️ مفيش كويري هنا — كل حاجة جاية من الكنترولر.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $sign = fn ($n) => (float) $n > 0.004 ? 'neg' : ((float) $n < -0.004 ? 'pos' : '');
    $statusBadge = fn ($s) => match ($s) {
        'draft' => 'b-orange',
        'applied' => 'b-green',
        'master_only' => 'b-blue',
        default => 'b-gray',
    };
@endphp

@section('title', __('reprice.log_title'))

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
@endsection

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('reprice.k_runs') }}</div><div class="val">{{ (int) $kpi->runs }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_invoices') }}</div><div class="val">{{ (int) $kpi->invs }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_pos') }}</div><div class="val">{{ (int) $kpi->pos }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_ledger') }}</div>
        <div class="val {{ $sign($kpi->ledger) }}" dir="ltr">{{ $fmt($kpi->ledger) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('reprice.ledger_hint') }}</div></div>
</div>

<div class="card">
    <h3>🧾 {{ __('reprice.log_title') }} <span class="side">{{ $runs->total() }}</span></h3>

    <form class="searchbar" method="GET">
        <label class="fl"><span>{{ __('reprice.col_trigger') }}</span>
            <select name="trigger">
                <option value="">{{ __('reprice.f_all') }}</option>
                @foreach ($triggers as $t)
                    <option value="{{ $t }}" @selected($filters['trigger'] === $t)>{{ __('reprice.trigger_'.$t) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('reprice.col_status') }}</span>
            <select name="status">
                <option value="">{{ __('reprice.f_all') }}</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ __('reprice.status_'.$s) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('reprice.f_price_list') }}</span>
            <select name="price_list_id">
                <option value="">{{ __('reprice.f_all') }}</option>
                @foreach ($lists as $l)
                    <option value="{{ $l->id }}" @selected($filters['price_list_id'] === (int) $l->id)>{{ $l->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('reprice.f_group') }}</span>
            <select name="group_id">
                <option value="">{{ __('reprice.f_all') }}</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected($filters['group_id'] === (int) $g->id)>{{ $g->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('reprice.f_invoice') }}</span>
            <input type="text" name="invoice" value="{{ $filters['invoice'] }}" placeholder="INV-…" dir="ltr"></label>
        @if ($clientFilter)
            {{-- فلتر العميل جاي من صفحة العميل — بيتشال بزرار المسح --}}
            <input type="hidden" name="client_id" value="{{ $clientFilter->id }}">
            <span class="badge b-blue">👤 {{ $clientFilter->displayName() }}</span>
        @endif
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('erp.price_changes') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap"><table>
        <thead><tr>
            <th>{{ __('reprice.col_run') }}</th><th>{{ __('reprice.col_date') }}</th><th>{{ __('reprice.col_user') }}</th>
            <th>{{ __('reprice.col_trigger') }}</th><th>{{ __('reprice.col_target') }}</th><th>{{ __('reprice.col_status') }}</th>
            <th class="num">{{ __('reprice.k_invoices') }}</th><th class="num">{{ __('reprice.k_pos') }}</th>
            <th class="num">{{ __('reprice.k_skipped') }}</th>
            <th class="num">{{ __('reprice.log_before') }}</th><th class="num">{{ __('reprice.log_after') }}</th>
            <th class="num">{{ __('reprice.diff') }}</th><th class="num">{{ __('reprice.k_ledger') }}</th>
        </tr></thead>
        <tbody>
            @forelse ($runs as $r)
                @php $diff = (float) $r->after_grand - (float) $r->before_grand; @endphp
                <tr>
                    <td><a href="{{ route('erp.price_changes.show', $r) }}">{{ $r->number }}</a></td>
                    <td>{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $r->user?->displayName() ?? '—' }}</td>
                    <td>{{ $r->triggerLabel() }}</td>
                    <td>{{ $targets[$r->id] ?? '—' }}</td>
                    <td><span class="badge {{ $statusBadge($r->status) }}">{{ $r->statusLabel() }}</span></td>
                    @if ($r->status === 'applied')
                        <td class="num">{{ (int) $r->invoices_changed }}</td>
                        <td class="num">{{ (int) $r->pos_changed }}</td>
                        <td class="num">{{ (int) $r->skipped_count }}</td>
                        <td class="num">{{ $fmt($r->before_grand) }}</td>
                        <td class="num">{{ $fmt($r->after_grand) }}</td>
                        <td class="num {{ $sign($diff) }}" dir="ltr">{{ $fmt($diff) }}</td>
                        <td class="num {{ $sign($r->ledger_diff) }}" dir="ltr">{{ $fmt($r->ledger_diff) }}</td>
                    @else
                        {{-- مسودة/ملغي/للبيع الجديد بس: مفيش مستند اتلمس — مفيش أرقام --}}
                        @for ($i = 0; $i < 7; $i++)<td class="num" style="color:var(--muted)">—</td>@endfor
                    @endif
                </tr>
            @empty
                <tr><td colspan="13" style="text-align:center;color:var(--muted)">{{ __('reprice.log_empty') }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
    @include('partials._pagination', ['p' => $runs])
</div>

@endsection
