@extends('layouts.system')

{{--
    ═══════════════════════════════════════════════════════════════
    الموعود مقابل المتاح  ·  ١٥ أغسطس ٢٠٢٦
    ═══════════════════════════════════════════════════════════════

    طلب المالك بعد إصلاح حجز البضاعة: «المناديب اللي رصيدهم بايظ
    محتاجين تسوية — عاوز أشوف مين متورّط قبل ما يقف قدام العميل».

    الحجز الجديد بيمنع التورّط من هنا ورايح. الشاشة دي للفجوة
    القديمة اللي اتعملت قبله.

    ⚠️ الشاشة **تشخيص مش تنفيذ**: مافيهاش أي زرار بيغيّر بيانات.
    التسوية قرار بشري — يا إما أمر تجهيز من المخزن، يا تحويل من
    مندوب تاني، يا تعديل كمية الأمر. الأزرار بتوديه للشاشة الصح.
--}}

@section('title', __('ops.cm_title'))

@section('actions')
    <a class="btn" href="{{ route('ops.vans') }}">🚐 {{ __('nav.vans_board') }}</a>
    <a class="btn" href="{{ route('ops.pos') }}">🚚 {{ __('nav.purchase_orders') }}</a>
@endsection

@section('content')

@php
    $fm = fn ($n) => number_format((float) $n);
    $dtm = fn ($d) => $d?->copy()->timezone('Africa/Cairo')->format('m-d h:i A');
@endphp

{{-- ═══ المؤشرات ═══ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">🚨 {{ __('ops.cm_reps_at_risk') }}</div>
        <div class="val {{ $repsAtRisk > 0 ? 'neg' : 'pos' }}">{{ $fm($repsAtRisk) }}</div>
        <div class="sub2">{{ __('ops.cm_reps_at_risk_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">📦 {{ __('ops.cm_units_short') }}</div>
        <div class="val {{ $unitsShort > 0 ? 'neg' : 'pos' }}">{{ $fm($unitsShort) }}</div>
        <div class="sub2">{{ __('ops.cm_units_short_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🚚 {{ __('ops.cm_orders_at_risk') }}</div>
        <div class="val {{ $ordersAtRisk > 0 ? 'mid' : 'pos' }}">{{ $fm($ordersAtRisk) }}</div>
        <div class="sub2">{{ __('ops.cm_orders_at_risk_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">✅ {{ __('ops.cm_clean') }}</div>
        <div class="val pos">{{ $fm($clean->count()) }}</div>
        <div class="sub2">{{ __('ops.cm_clean_hint') }}</div>
    </div>
</div>

{{-- ═══ شرح المعادلة — الشاشة دي بتتقري غلط من غيره ═══ --}}
<div class="alert info" style="margin:14px 0">
    <span>ℹ️</span>
    <span>{{ __('ops.cm_formula') }}</span>
</div>

@forelse ($rows as $r)
    <div class="card" style="margin-bottom:14px;border-inline-start:4px solid var(--red)">
        <h3>
            <span style="display:inline-flex;gap:8px;align-items:center;vertical-align:middle">
                @include('partials._avatar', ['u' => $r['rep'], 'size' => 30])
                <a href="{{ route('ops.rep', $r['rep']) }}" target="_blank" rel="noopener">{{ $r['rep']->displayName() }}</a>
            </span>
            <span class="side">
                {{ $r['rep']->roleLabel() }}
                @if ($r['rep']->zone) · {{ $r['rep']->zone->displayName() }}@endif
                · <b class="neg">{{ __('ops.cm_gap_units', ['n' => $fm($r['gap_units'])]) }}</b>
            </span>
        </h3>

        @if ($r['custody'] === null)
            {{-- أسوأ حالة: أوامر مفتوحة والعربية مالهاش عهدة أصلاً --}}
            <div class="alert" style="margin-bottom:10px">
                <span>⛔</span><span>{{ __('ops.cm_no_custody') }}</span>
            </div>
        @endif

        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th style="text-align:start">{{ __('stock.item') }}</th>
                    <th>{{ __('ops.cm_promised') }}</th>
                    <th>{{ __('ops.cm_available') }}</th>
                    <th>{{ __('ops.cm_gap') }}</th>
                    <th style="text-align:start" data-nosum>{{ __('ops.cm_orders') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($r['short'] as $s)
                    <tr>
                        <td style="text-align:start">
                            <b>{{ $s['product']?->displayName() ?? '#'.$s['pid'] }}</b>
                            <div style="font-size:10.5px;color:var(--muted)">{{ $s['product']?->code ?? '—' }}</div>
                        </td>
                        <td class="num">{{ $fm($s['need']) }}</td>
                        <td class="num {{ $s['have'] === 0 ? 'neg' : '' }}">{{ $fm($s['have']) }}</td>
                        <td class="num neg"><b>{{ $fm($s['gap']) }}</b></td>
                        <td style="text-align:start;white-space:normal">
                            @foreach ($s['orders'] as $o)
                                <a class="badge b-gray" style="text-decoration:none;margin-inline-end:3px"
                                   href="{{ route('ops.pos.show', $o['id']) }}" target="_blank" rel="noopener"
                                   title="{{ $o['client'] }}{{ $o['due'] ? ' · '.$dtm($o['due']) : '' }}">
                                    <span dir="ltr">{{ $o['number'] }}</span> · {{ $fm($o['qty']) }}
                                </a>
                            @endforeach
                        </td>
                        <td class="act">@include('partials._view', [
                            'url' => route('erp.products.show', $s['pid']),
                            'label' => __('stock.product'),
                        ])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{-- ⚠️ مسارات التسوية التلاتة — الشاشة بتوجّه، مابتنفّذش --}}
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn sm gold" href="{{ route('ops.handout') }}?rep={{ $r['rep']->id }}">
                📤 {{ __('ops.cm_fix_pick') }}
            </a>
            <a class="btn sm" href="{{ route('wh.transfers.van') }}?rep={{ $r['rep']->id }}">
                🔄 {{ __('ops.cm_fix_transfer') }}
            </a>
            <span style="font-size:11px;color:var(--muted);align-self:center">
                {{ __('ops.cm_fix_hint') }}
            </span>
        </div>
    </div>
@empty
    <div class="card" style="text-align:center;padding:40px">
        <div style="font-size:40px">✅</div>
        <div style="font-weight:900;font-size:16px;margin-top:8px">{{ __('ops.cm_none_title') }}</div>
        <div style="color:var(--muted);margin-top:6px">{{ __('ops.cm_none_hint') }}</div>
    </div>
@endforelse

{{-- ═══ المناديب السليمين — مطويين، عشان الشاشة تفضل عن المشاكل ═══ --}}
@if ($clean->isNotEmpty())
    <details class="card" style="margin-top:14px">
        <summary style="cursor:pointer;font-weight:800">
            ✅ {{ __('ops.cm_clean') }} <span class="side">{{ $clean->count() }}</span>
        </summary>
        <div class="tablewrap" style="margin-top:10px">
            <table>
                <thead>
                <tr>
                    <th style="text-align:start">{{ __('ops.rep') }}</th>
                    <th data-nosum>{{ __('team.role') }}</th>
                    <th>{{ __('ops.cm_covered_lines') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($clean as $c)
                    <tr class="clickable" onclick="location.href='{{ route('ops.rep', $c['rep']) }}'">
                        <td style="text-align:start">
                            <div style="display:flex;gap:8px;align-items:center">
                                @include('partials._avatar', ['u' => $c['rep'], 'size' => 26])
                                <b>{{ $c['rep']->displayName() }}</b>
                            </div>
                        </td>
                        <td>{{ $c['rep']->roleLabel() }}</td>
                        <td class="num pos">{{ $fm($c['ok_lines']) }}</td>
                        <td class="act">@include('partials._view', ['url' => route('ops.rep', $c['rep'])])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif

@endsection
