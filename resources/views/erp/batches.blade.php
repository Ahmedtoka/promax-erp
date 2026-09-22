@extends('layouts.system')

@section('title', __('stock.batch_report'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $money = fn ($n) => number_format((float) $n, 0);
    $isRtl = app()->getLocale() === 'ar';

    $warn = (int) $meta['warn_days'];
    $danger = (int) $meta['danger_days'];

    // حالة الباتش من الأيام الفاضلة — نفس الحدود اللي الكنترولر بيستخدمها
    $stateOf = function (?int $days) use ($warn, $danger) {
        if ($days === null) {
            return 'undated';
        }

        return $days < 0 ? 'expired'
            : ($days <= $danger ? 'danger'
            : ($days <= $warn ? 'warn' : 'ok'));
    };

    $stateClass = [
        'expired' => 'b-red', 'danger' => 'b-red', 'warn' => 'b-orange',
        'ok' => 'b-green', 'undated' => 'b-gray',
    ];
    $stateText = [
        'expired' => 'neg', 'danger' => 'neg', 'warn' => 'mid',
        'ok' => 'pos', 'undated' => '',
    ];

    // اسم الصنف بيفتح كارته — الملف فيه الكود بس، والكنترولر بيجيب الـid (٢٢/٩)
    $pUrl = fn ($code) => isset($productIds[$code]) ? route('erp.products.show', $productIds[$code]) : null;
    $fltUrl = fn (array $x) => route('erp.batches', array_filter($x + $filters + ['from' => $range->fromValue(), 'to' => $range->toValue()])).'#bList';

    // شريط توزيع الوحدات على الحالات — عرض كل قطعة بنسبتها
    $totalUnits = max(array_sum(array_column($buckets, 'qty')), 1);
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.stock') }}">📦 {{ __('nav.inventory') }}</a>
    <a class="btn" href="{{ route('wh.expiry') }}">⏳ {{ __('nav.expiry') }}</a>
@endsection

@section('content')

<div class="card" style="padding-bottom:12px">
    <h3>🗓️ {{ __('stock.batch_report') }}
        <span class="side">{{ __('stock.batch_report_sub') }}</span></h3>
    <div style="font-size:11.5px;color:var(--muted);line-height:1.7">
        {{ __('stock.batch_report_source') }}<br>
        {{ __('stock.batch_report_snapshot', ['date' => $meta['generated_on']]) }}
        · {{ __('stock.expiry_legend', ['danger' => $danger, 'warn' => $warn]) }}
    </div>
</div>

{{-- الكروت على الملف كله — أول أربعة بيفتحوا تفصيل العائلات، والأخير بيودّي على أقرب الباتشات (٢٢/٩) --}}
<div class="kpis">
    <div class="kpi" data-explain onclick="openDlg('dlgBFam')" title="{{ __('ui.click_to_explain') }}">
        <div class="lbl">{{ __('stock.stock_value_new_price') }}</div>
        <div class="val" style="color:var(--primary)">{{ $money($kpi['value']) }} {{ __('common.currency') }}</div>
        {{-- كل كارت بمعادلته من نفس مصفوفة $kpi (٢٢/٩) --}}
        <div class="sub2">{{ __('uid.bk_value_how', ['n' => $kpi['skus']]) }}<br><span dir="ltr">{{ $money($kpi['value']) }} = {{ __('stock.sellable_units') }} {{ $money($kpi['value_live']) }} + {{ __('stock.reserved_units') }} {{ $money($kpi['value_hold']) }}</span></div>
    </div>
    <div class="kpi" data-explain onclick="openDlg('dlgBFam')" title="{{ __('ui.click_to_explain') }}">
        <div class="lbl">{{ __('stock.units_on_hand') }}</div>
        <div class="val">{{ $fmt($kpi['qty']) }}</div>
        <div class="sub2">{{ $fmt($kpi['batches']) }} {{ __('stock.batches_on_hand') }}<br><span dir="ltr">{{ $fmt($kpi['qty']) }} = {{ $fmt($kpi['qty_live']) }} + {{ $fmt($kpi['qty_hold']) }}</span></div>
    </div>
    <div class="kpi" data-explain onclick="openDlg('dlgBFam')" title="{{ __('ui.click_to_explain') }}">
        <div class="lbl">{{ __('stock.sellable_units') }}</div>
        <div class="val pos">{{ $fmt($kpi['qty_live']) }}</div>
        <div class="sub2">{{ __('uid.bk_live_how') }} — {{ $money($kpi['value_live']) }} {{ __('common.currency') }}</div>
    </div>
    <div class="kpi" data-explain onclick="openDlg('dlgBFam')" title="{{ __('ui.click_to_explain') }}">
        <div class="lbl">{{ __('stock.reserved_units') }}</div>
        <div class="val mid">{{ $fmt($kpi['qty_hold']) }}</div>
        <div class="sub2">{{ __('uid.bk_hold_how') }} — {{ $money($kpi['value_hold']) }} {{ __('common.currency') }}</div>
    </div>
    <a class="kpi" href="#bSoon">
        <div class="lbl">{{ __('stock.soonest_expiry') }}</div>
        @php $first = $soonest[0] ?? null; @endphp
        <div class="val {{ $first ? $stateText[$stateOf($first['days_left'])] : '' }}">
            {{ $first ? $fmt($first['days_left']).' '.__('stock.days_left_short') : '—' }}
        </div>
        <div class="sub2">{{ $first['name'] ?? '—' }}</div>
    </a>
</div>

{{-- ═══════════ شريط حالات الصلاحية ═══════════ --}}
<div class="grid2">
    <div class="card">
        <h3>⏳ {{ __('stock.expiry_state') }}</h3>

        <div style="display:flex;height:14px;border-radius:8px;overflow:hidden;margin-bottom:14px;background:var(--border)">
            @foreach (['expired', 'danger', 'warn', 'ok', 'undated'] as $k)
                @if ($buckets[$k]['qty'] > 0)
                    <div title="{{ __('stock.state_'.$k) }}"
                         style="width:{{ round($buckets[$k]['qty'] / $totalUnits * 100, 2) }}%;
                                background:{{ ['expired' => 'var(--red)', 'danger' => 'var(--red)', 'warn' => 'var(--orange)', 'ok' => 'var(--green)', 'undated' => 'var(--muted)'][$k] }}"></div>
                @endif
            @endforeach
        </div>

        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('stock.expiry_state') }}</th>
                    <th class="num">{{ __('stock.batches_on_hand') }}</th>
                    <th class="num">{{ __('stock.units') }}</th>
                    <th class="num">{{ __('stock.value_at_new') }}</th>
                </tr>
                @foreach (['expired', 'danger', 'warn', 'ok', 'undated'] as $k)
                    <tr>
                        <td><a class="badge {{ $stateClass[$k] }}" href="{{ $fltUrl(['state' => $k]) }}" title="{{ __('ui.click_to_filter') }}">{{ __('stock.state_'.$k) }}</a></td>
                        <td class="num">{{ $fmt($buckets[$k]['batches']) }}</td>
                        <td class="num">{{ $fmt($buckets[$k]['qty']) }}</td>
                        <td class="num">{{ $money($buckets[$k]['value']) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    <div class="card">
        <h3>🏷️ {{ __('stock.per_family') }}</h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('stock.family') }}</th>
                    <th class="num">{{ __('stock.skus') }}</th>
                    <th class="num">{{ __('stock.batches_on_hand') }}</th>
                    <th class="num">{{ __('stock.units') }}</th>
                    <th class="num">{{ __('stock.value_at_new') }}</th>
                </tr>
                @foreach ($families as $key => $f)
                    <tr>
                        <td><a href="{{ $fltUrl(['family' => $key]) }}" title="{{ __('ui.click_to_filter') }}"><b>{{ $isRtl ? $f['label'] : $f['label_en'] }}</b></a></td>
                        <td class="num">{{ $f['skus'] }}</td>
                        <td class="num">{{ $f['batches'] }}</td>
                        <td class="num">{{ $fmt($f['qty']) }}</td>
                        <td class="num"><b>{{ $money($f['value']) }}</b></td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
</div>

{{-- ═══════════ أقرب الباتشات للانتهاء ═══════════ --}}
@if (count($soonest) > 0)
<div class="card" id="bSoon">
    <h3>🔔 {{ __('stock.nearest_expiries') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.item') }}</th>
                <th>{{ __('stock.family') }}</th>
                <th data-nosum>{{ __('stock.produced_on') }}</th>
                <th data-nosum>{{ __('stock.expires_on') }}</th>
                <th class="num" data-nosum>{{ __('stock.days_left_col') }}</th>
                <th class="num">{{ __('stock.qty') }}</th>
                <th class="num">{{ __('stock.value_at_new') }}</th>
            </tr>
            @foreach ($soonest as $b)
                @php $st = $stateOf($b['days_left']); @endphp
                <tr>
                    <td>@if ($u = $pUrl($b['code'] ?? ''))<a href="{{ $u }}"><b>{{ $b['name'] }}</b></a>@else<b>{{ $b['name'] }}</b>@endif
                        <br><span style="font-size:10.5px;color:var(--muted)" class="num">{{ $b['barcode'] }}</span>
                    </td>
                    <td>{{ $isRtl ? $b['family_ar'] : $b['family_en'] }}</td>
                    <td class="num">{{ $b['produced_on'] ?? '—' }}</td>
                    <td class="num">{{ $b['expires_on'] ?? '—' }}</td>
                    <td class="num {{ $stateText[$st] }}"><b>{{ $fmt($b['days_left']) }}</b></td>
                    <td class="num">{{ $fmt($b['qty']) }}</td>
                    <td class="num">{{ $money($b['value']) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

{{-- ═══════════ الفلاتر ═══════════ --}}
<form class="searchbar" method="GET" id="bList">
    <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="🔍 {{ __('stock.search_batch_item') }}"></label>

    <label class="fl"><span>{{ __('ui.l_family') }}</span>
    <select name="family">
        <option value="">{{ __('stock.all_families') }}</option>
        @foreach ($families as $key => $f)
            <option value="{{ $key }}" @selected(($filters['family'] ?? '') === $key)>
                {{ $isRtl ? $f['label'] : $f['label_en'] }}
            </option>
        @endforeach
    </select></label>

    <label class="fl"><span>{{ __('stock.expiry_state') }}</span>
    <select name="state">
        <option value="">{{ __('stock.state_all') }}</option>
        @foreach (['expired', 'danger', 'warn', 'ok', 'undated'] as $k)
            <option value="{{ $k }}" @selected(($filters['state'] ?? '') === $k)>{{ __('stock.state_'.$k) }}</option>
        @endforeach
    </select></label>

    {{-- نافذة الصلاحية «من — إلى» على `expires_on` بتاع الباتش (٩/٩/٢٠٢٦) --}}
    @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])

    <button class="btn gold">{{ __('common.search') }}</button>
    <a class="btn" href="{{ route('erp.batches') }}">{{ __('common.clear') }}</a>
    {{-- الفترة على تاريخ الانتهاء — من غير التوضيح القايمة بتفضى ومحدش فاهم ليه (٢٢/٩) --}}
    <span style="font-size:11px;color:var(--muted);flex-basis:100%">ℹ️ {{ __('uid.exp_range_note') }}</span>
</form>

{{-- ═══════════ الأصناف وباتشاتها ═══════════ --}}
@forelse ($items as $i)
    @php $st = $stateOf($i['soonest']); @endphp
    <div class="card">
        <h3>
            @if ($u = $pUrl($i['code']))<a href="{{ $u }}">{{ $i['name'] }}</a>@else{{ $i['name'] }}@endif
            <span class="side">
                {{ $isRtl ? $i['family_ar'] : $i['family_en'] }}
                · {{ $i['unit'] }}
                · {{ __('stock.shelf_life') }} {{ $i['shelf_life_months'] }} {{ __('stock.months_short') }}
            </span>
        </h3>

        <div class="frow" style="margin-bottom:10px">
            <div>
                <label class="f">{{ __('stock.gs1_barcode') }}</label>
                <div class="num"><b>{{ $i['barcode'] }}</b></div>
            </div>
            <div>
                <label class="f">{{ __('stock.sku') }}</label>
                <div class="num">{{ $i['code'] }}</div>
            </div>
            <div>
                <label class="f">{{ __('stock.net_content_label') }}</label>
                <div class="num">{{ $i['net_content'] ? rtrim(rtrim(number_format($i['net_content'], 2, '.', ''), '0'), '.') : '—' }}
                    {{ $isRtl ? $i['uom_ar'] : $i['uom_en'] }}</div>
            </div>
            <div>
                <label class="f">{{ __('stock.price_new') }}</label>
                <div class="num"><b>{{ number_format($i['price'], 2) }}</b> {{ __('common.currency') }}</div>
            </div>
            <div>
                <label class="f">{{ __('stock.units_on_hand') }}</label>
                <div class="num"><b>{{ $fmt($i['qty']) }}</b>
                    @if ($i['qty_hold'] > 0)
                        <span class="badge b-orange">{{ __('stock.hold') }} {{ $fmt($i['qty_hold']) }}</span>
                    @endif
                </div>
            </div>
            <div>
                <label class="f">{{ __('stock.value_at_new') }}</label>
                <div class="num" style="color:var(--primary)"><b>{{ $money($i['value']) }}</b> {{ __('common.currency') }}</div>
            </div>
            <div>
                <label class="f">{{ __('stock.soonest_expiry') }}</label>
                <div>
                    <span class="badge {{ $stateClass[$st] }}">{{ __('stock.state_'.$st) }}</span>
                    @if ($i['soonest'] !== null)
                        <span class="num" style="font-size:11px">{{ $i['soonest_on'] }}
                            ({{ $fmt($i['soonest']) }} {{ __('stock.days_left_short') }})</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="tablewrap">
            <table>
                <tr>
                    <th data-nosum>{{ __('stock.produced_on') }}</th>
                    <th data-nosum>{{ __('stock.expires_on') }}</th>
                    <th class="num" data-nosum>{{ __('stock.days_left_col') }}</th>
                    <th class="num">{{ __('stock.qty') }}</th>
                    <th class="num">{{ __('stock.value_at_new') }}</th>
                    <th>{{ __('common.notes') }}</th>
                </tr>
                @foreach ($i['batches'] as $b)
                    @php $bs = $stateOf($b['days_left']); @endphp
                    <tr>
                        <td class="num">{{ $b['produced_on'] ?? '—' }}</td>
                        <td class="num">{{ $b['expires_on'] ?? '—' }}</td>
                        <td class="num {{ $stateText[$bs] }}">
                            @if ($b['days_left'] === null)
                                —
                            @else
                                <b>{{ $fmt($b['days_left']) }}</b>
                            @endif
                        </td>
                        <td class="num">{{ $fmt($b['qty']) }}</td>
                        <td class="num">{{ $money($b['value']) }}</td>
                        <td>
                            @if ($b['hold'])
                                <span class="badge b-orange">{{ __('stock.hold') }}</span>
                                @if ($b['produced_on'] === null)
                                    <span style="font-size:10.5px;color:var(--muted)">{{ __('stock.hold_no_date') }}</span>
                                @endif
                            @elseif ($b['note'])
                                <span style="font-size:11px">{{ $b['note'] }}</span>
                            @else
                                <span class="badge {{ $stateClass[$bs] }}">{{ __('stock.state_'.$bs) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@empty
    <div class="card">
        <div class="alert info">{{ __('stock.no_batch_items') }}</div>
    </div>
@endforelse

{{-- تفصيل أرقام الكروت بالعائلة — الصفوف مجموعها = الكارت (٢٢/٩) --}}
<dialog id="dlgBFam" class="wide">
    <div class="dlg">
        <h4>{{ __('uid.fam_breakdown') }}</h4>
        <div class="tablewrap">
            <table>
                <thead><tr>
                    <th>{{ __('stock.family') }}</th>
                    <th class="num">{{ __('stock.units_on_hand') }}</th>
                    <th class="num">{{ __('stock.sellable_units') }}</th>
                    <th class="num">{{ __('stock.reserved_units') }}</th>
                    <th class="num">{{ __('stock.value_at_new') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($families as $key => $f)
                    <tr>
                        <td><a href="{{ $fltUrl(['family' => $key]) }}">{{ $isRtl ? $f['label'] : $f['label_en'] }}</a></td>
                        <td class="num">{{ $fmt($f['qty']) }}</td>
                        <td class="num pos">{{ $fmt($f['qty_live']) }}</td>
                        <td class="num mid">{{ $fmt($f['qty_hold']) }}</td>
                        <td class="num">{{ $money($f['value']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr>
                    <td><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ $fmt($kpi['qty']) }}</b></td>
                    <td class="num"><b>{{ $fmt($kpi['qty_live']) }}</b></td>
                    <td class="num"><b>{{ $fmt($kpi['qty_hold']) }}</b></td>
                    <td class="num"><b>{{ $money($kpi['value']) }}</b></td>
                </tr></tfoot>
            </table>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgBFam')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

@endsection
