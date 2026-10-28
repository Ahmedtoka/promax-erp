@extends('layouts.system')

{{--
    تفاصيل مستند واحد (فاتورة/أمر توريد) اتلمس أو اتخطى في تعديل أسعار (Lane 13).
    البنود متخزنة وقت التعديل (قبل/بعد) فالصفحة بتشتغل حتى لو المستند اتغيّر بعدها.
    ⚠️ مفيش كويري هنا — كل حاجة جاية من الكنترولر.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $sign = fn ($n) => (float) $n > 0.004 ? 'neg' : ((float) $n < -0.004 ? 'pos' : '');
    $lines = $doc->lines ?? [];
    $changed = $doc->outcome === 'changed';
    $diff = (float) $doc->after_grand - (float) $doc->before_grand;
@endphp

@section('title', __('reprice.doc_title').' — '.$doc->number)

@section('actions')
    <a class="btn" href="{{ route('erp.price_changes.show', $run) }}">← {{ $run->number }}</a>
    @if ($docUrl)
        <a class="btn gold" href="{{ $docUrl }}">{{ __('reprice.open_doc') }}</a>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📄 {{ $doc->number }}
        <span class="badge {{ $changed ? 'b-green' : 'b-orange' }}">{{ __('reprice.outcome_'.$doc->outcome) }}</span>
        <span class="side">{{ __('reprice.type_'.$doc->doc_type) }}</span></h3>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <span class="badge b-blue">{{ $run->number }}</span>
        <span class="badge b-gray">{{ __('reprice.col_client') }}: {{ $clientName ?? '—' }}</span>
        <span class="badge b-gray">{{ __('reprice.col_date') }}: {{ $doc->doc_date?->toDateString() ?? '—' }}</span>
        @unless ($docUrl)
            <span class="badge b-gray">{{ __('reprice.doc_deleted') }}</span>
        @endunless
    </div>
    @unless ($changed)
        <div class="alert warn" style="margin-top:8px">{{ __('reprice.col_reason') }}: {{ $doc->reasonLabel() }}</div>
    @endunless
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('reprice.net') }}</div>
        <div class="val">{{ $fmt($doc->before_net) }} ← {{ $fmt($doc->after_net) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.tax') }}</div>
        <div class="val">{{ $fmt($doc->before_tax) }} ← {{ $fmt($doc->after_tax) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.grand') }}</div>
        <div class="val">{{ $fmt($doc->before_grand) }} ← {{ $fmt($doc->after_grand) }}</div>
        <div class="sub2 {{ $sign($diff) }}" dir="ltr">{{ $fmt($diff) }} {{ __('common.currency') }}</div></div>
</div>

<div class="card">
    <h3>🧾 {{ __('reprice.doc_lines') }} <span class="side">{{ count($lines) }}</span></h3>
    <div class="tablewrap"><table>
        <thead>
            <tr>
                <th rowspan="2">{{ __('reprice.col_product') }}</th>
                <th rowspan="2" class="num">{{ __('reprice.col_qty') }}</th>
                <th colspan="4">{{ __('reprice.before') }}</th>
                <th colspan="4">{{ __('reprice.after') }}</th>
            </tr>
            <tr>
                @foreach (['before', 'after'] as $g)
                    <th class="num">{{ __('reprice.col_list_price') }}</th><th class="num">{{ __('reprice.col_price') }}</th>
                    <th class="num">{{ __('reprice.col_line_total') }}</th><th class="num">{{ __('reprice.tax') }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $l)
                <tr>
                    <td>{{ $l['name'] ?? '' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) ($l['qty'] ?? 0), 3, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ $fmt($l['old_list'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($l['old_price'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($l['old_total'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($l['old_tax'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($l['list_price'] ?? 0) }}</td>
                    <td class="num {{ $sign(($l['price'] ?? 0) - ($l['old_price'] ?? 0)) }}">{{ $fmt($l['price'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($l['total'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($l['tax'] ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted)">{{ __('reprice.no_lines') }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
</div>

@endsection
