@extends('layouts.system')

{{--
    تصفية المناديب — شاشة الحسابات (2026-08-06):
    صف لكل مندوب بأرقام فترته المفتوحة (من آخر تصفية) ورصيده
    المترحّل — والتصفية نفسها من صفحة المندوب بالتفصيل.
--}}

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@section('title', __('settle.title'))

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

{{-- ═══ ملخّص الفترات المفتوحة (٢٢/٩) — المطلوب من المناديب كام واتركّب إزاي، من نفس صفوف الجدول ═══ --}}
@php
    $rc = collect($rows);
    $tCash = round($rc->sum('cash_sales'), 2); $tColl = round($rc->sum('cash_collections'), 2);
    $tRef = round($rc->sum('cash_refunds'), 2); $tExp = round($rc->sum('expected'), 2);
    $tPrev = round($rc->sum('prev_balance'), 2); $tDue = round($rc->sum('due_total'), 2);
    $tOther = round($rc->sum('other_collections_value'), 2); $tCredit = round($rc->sum('credit_sales'), 2);
@endphp
<div class="kpis">
    <a class="kpi" href="#rc-open" style="grid-column:span 2">
        <div class="lbl">{{ __('settle.due_total') }}</div>
        <div class="val" style="color:var(--royal-blue)">{{ $fmt($tDue) }}</div>
        <div class="sub2">@include('erp._eq', ['total' => $tDue, 'zeros' => true, 'parts' => [[__('settle.expected'), $tExp], [__('settle.prev_balance'), abs($tPrev), $tPrev < 0 ? '-' : '+']]])</div>
        <div class="sub2">{{ __('uib.rc_due_sub') }}</div>
    </a>
    <a class="kpi" href="#rc-open" style="grid-column:span 2">
        <div class="lbl">{{ __('settle.expected') }}</div>
        <div class="val pos">{{ $fmt($tExp) }}</div>
        <div class="sub2">@include('erp._eq', ['total' => $tExp, 'zeros' => true, 'parts' => [[__('settle.cash_sales'), $tCash], [__('uib.rc_cash_coll'), $tColl], [__('settle.cash_refunds'), $tRef, '-']]])</div>
    </a>
    <a class="kpi" href="{{ route('erp.collections', ['source' => 'field']) }}">
        <div class="lbl">{{ __('settle.other_collections') }}</div>
        <div class="val">{{ $fmt($tOther) }}</div>
        <div class="sub2">{{ __('settle.other_collections_hint') }}</div>
    </a>
    <a class="kpi" href="{{ route('ops.invoices', ['pay' => 'credit']) }}">
        <div class="lbl">{{ __('settle.credit_sales') }}</div>
        <div class="val mid">{{ $fmt($tCredit) }}</div>
        <div class="sub2">{{ __('uib.rc_credit_sub') }}</div>
    </a>
</div>

<div class="card" id="rc-open">
    <h3>🤝 {{ __('settle.title') }}
        <span class="side">{{ __('settle.hint') }}</span></h3>

    <div class="tablewrap st-tbl">
        <table>
            <tr>
                <th style="text-align:start">{{ __('settle.rep') }}</th>
                <th>{{ __('settle.open_window') }}</th>
                <th>{{ __('settle.cash_sales') }}</th>
                <th>{{ __('settle.credit_sales') }}</th>
                {{-- ⚠️ **تحصيلات غير نقدية** (طلب المالك ١١/٨): التحويل
                     والشيك والفيزا فلوس ماوصلتش إيد المندوب فمش داخلة
                     «المتوقع» النقدي — بس المحاسب لازم يشوفها عشان
                     يعرف المندوب حصّل كام بره الكاش وقت التصفية. --}}
                {{-- (٢٢/٩) التحصيل النقدي داخل «المتوقع» وماكانش له عمود — الصف ماكانش بيتقفل بالعين --}}
                <th>{{ __('uib.rc_cash_coll') }}</th>
                <th>{{ __('settle.cash_refunds') }}</th>
                <th title="{{ __('uib.rc_expected_eq') }}">{{ __('settle.expected') }}<div class="eqh">{{ __('uib.rc_expected_eq') }}</div></th>
                <th>{{ __('settle.other_collections') }}</th>
                <th data-nosum>{{ __('settle.prev_balance') }}</th>
                <th title="{{ __('uib.rc_due_eq') }}">{{ __('settle.due_total') }}<div class="eqh">{{ __('uib.rc_due_eq') }}</div></th>
                {{-- ⚠️ **القايمة كانت فلوس بس.** المحاسب بيفتح كل
                     مندوب واحد واحد عشان يعرف مين عنده عجز بضاعة —
                     والرقم موجود أصلاً في `openFigures`. --}}
                <th>{{ __('settle.goods_match') }}</th>
                <th></th>
            </tr>
            @foreach ($rows as $r)
                @php $rep = $r['rep']; @endphp
                <tr>
                    <td style="text-align:start">
                        <a href="{{ route('ops.rep', $rep->id) }}"><b>{{ $rep->displayName() }}</b></a>
                        <div style="font-size:10px;color:var(--muted)">{{ $rep->code }}</div>
                    </td>
                    <td class="s">
                        {{ __('settle.invoice_count', ['count' => $r['invoices']->count()]) }}
                        <div style="font-size:10px;color:var(--muted)">
                            {{ $r['from_at'] ? __('settle.since_last').' '.$r['from_at']->format('m-d h:i A') : __('settle.since_start') }}
                        </div>
                    </td>
                    <td class="num"><b>{{ $fmt($r['cash_sales']) }}</b></td>
                    <td class="num" style="color:var(--muted)">{{ $fmt($r['credit_sales']) }}</td>
                    <td class="num">{{ $fmt($r['cash_collections']) }}</td>
                    <td class="num mid">{{ $fmt($r['cash_refunds']) }}</td>
                    <td class="num pos"><b>{{ $fmt($r['expected']) }}</b></td>
                    <td class="num" title="{{ __('settle.other_collections_hint') }}">
                        @if ((float) ($r['other_collections_value'] ?? 0) > 0)
                            <b style="color:#0F766E">{{ $fmt($r['other_collections_value']) }}</b>
                        @else
                            <span style="color:var(--muted)">—</span>
                        @endif
                    </td>
                    <td class="num">
                        @if ((float) $r['prev_balance'] > 0)
                            <span class="badge b-red">{{ __('settle.rep_owes') }} {{ $fmt($r['prev_balance']) }}</span>
                        @elseif ((float) $r['prev_balance'] < 0)
                            <span class="badge b-green">{{ __('settle.rep_credit') }} {{ $fmt(abs((float) $r['prev_balance'])) }}</span>
                        @else
                            <span class="badge b-gray">0</span>
                        @endif
                    </td>
                    <td class="num"><b style="color:var(--royal-blue);font-size:14px">{{ $fmt($r['due_total']) }}</b></td>
                    {{-- المحمَّل والعجز — والمرتجع اللي هيتسلّم معاه --}}
                    <td class="num">
                        @if ((int) $r['goods']['diff_qty'] !== 0)
                            <span class="badge b-red">{{ __('settle.shortage') }}
                                {{ number_format((int) $r['goods']['diff_qty']) }}</span>
                        @elseif ((int) $r['goods']['assigned'] > 0)
                            <span class="badge b-green">0 ✓</span>
                        @else
                            <span class="badge b-gray">—</span>
                        @endif
                        @if ((int) $r['goods']['returned_qty'] > 0 || (int) $r['goods']['damaged_qty'] > 0)
                            <div style="font-size:10px;color:var(--muted);margin-top:3px;white-space:normal;max-width:110px;margin-inline:auto">
                                {{ __('settle.returned_in') }}:
                                {{ number_format((int) $r['goods']['returned_qty']) }}
                                @if ((int) $r['goods']['damaged_qty'] > 0)
                                    · {{ __('field.return_damaged_units') }}
                                    {{ number_format((int) $r['goods']['damaged_qty']) }}
                                @endif
                            </div>
                        @endif
                    </td>
                    <td>
                        <a class="btn sm gold" href="{{ route('erp.repclose.show', $rep) }}">🤝 {{ __('settle.settle_now') }}</a>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

{{-- ═══ آخر التصفيات — للمراجعة وإعادة طباعة المحضر ═══ --}}
<div class="card">
    <h3>🗂️ {{ __('settle.recent') }}</h3>
    {{-- فلتر «من — إلى» على `to_at` لحظة القفل (٩/٩/٢٠٢٦) — فاضي = آخر ١٥ زي ما كان --}}
    {{-- ⚠️ `all => false`: الفترة الفاضية هنا = آخر ١٥ تصفية مش «كل الفترات» — «مسح» بيرجّعلها --}}
    <form method="GET" class="searchbar" data-noprint>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('erp.repclose') }}">{{ __('common.clear') }}</a>
    </form>
    <div class="tablewrap st-tbl">
        <table>
            <tr>
                <th>#</th>
                <th>{{ __('settle.rep') }}</th>
                {{-- النافذة كاملة من→إلى بدل «إلى» بس (طلب المالك ١١/٨) --}}
                <th>{{ __('settle.window') }}</th>
                <th>{{ __('settle.cash_sales') }}</th>
                <th>{{ __('settle.credit_sales') }}</th>
                <th>{{ __('settle.collections') }}</th>
                <th>{{ __('settle.cash_refunds') }}</th>
                <th title="{{ __('uib.rc_expected_eq') }}">{{ __('settle.expected') }}<div class="eqh">{{ __('uib.rc_expected_eq') }}</div></th>
                <th>{{ __('settle.received') }}</th>
                <th>{{ __('settle.balance') }}</th>
                <th>{{ __('settle.by') }}</th>
                <th data-nosum></th>
            </tr>
            @forelse ($recent as $s)
                <tr>
                    <td class="num"><a href="{{ route('erp.repclose.details', $s) }}"><b>{{ $s->number }}</b></a></td>
                    <td>@if ($s->user)<a href="{{ route('ops.rep', $s->user->id) }}">{{ $s->user->displayName() }}</a>@else — @endif</td>
                    <td class="num" style="font-size:10.5px" dir="ltr">
                        {{ $s->from_at?->format('m-d h:i A') ?? __('settle.since_start') }}
                        ← {{ $s->to_at->format('m-d h:i A') }}
                    </td>
                    <td class="num">{{ $fmt($s->cash_sales) }}</td>
                    {{-- ⚠️ آجل مريم كان مخزون هنا وماكانش بيبان في أي حتة --}}
                    <td class="num mid"><b>{{ $fmt($s->credit_sales) }}</b></td>
                    <td class="num">{{ $fmt($s->cash_collections) }}</td>
                    <td class="num mid">{{ $fmt($s->cash_refunds) }}</td>
                    <td class="num">{{ $fmt($s->expected) }}</td>
                    <td class="num pos"><b>{{ $fmt($s->received) }}</b></td>
                    <td>
                        <span class="badge {{ $s->balanceClass() }}">
                            {{ $s->balanceLabel() }}@if ((float) $s->balance != 0) {{ $fmt(abs((float) $s->balance)) }}@endif
                        </span>
                    </td>
                    <td class="s">{{ $s->creator?->name ?? '—' }}</td>
                    <td style="white-space:nowrap">
                        {{-- تفاصيل التصفية — كل رقم في المحضر جاي منين (١١/٨ مساءً) --}}
                        <a class="btn sm" href="{{ route('erp.repclose.details', $s) }}"
                           title="{{ __('settle.details_title') }}">🔎</a>
                        <a class="btn sm" href="{{ route('erp.repclose.doc', $s) }}">🖨️</a>
                        {{-- مسح — أدمن، وآخر تصفية للمندوب بس (سلامة سلسلة الأرصدة) --}}
                        @if (auth()->user()->isAdmin() && ($latestIds[$s->user_id] ?? null) === $s->id)
                            <form method="POST" action="{{ route('erp.repclose.destroy', $s) }}" style="display:inline"
                                  onsubmit="return confirm(@js(__('settle.delete_confirm', ['number' => $s->number])))">
                                @csrf @method('DELETE')
                                <button class="btn sm red" type="submit">🗑</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="12" style="text-align:center;color:var(--muted);padding:24px">{{ __('settle.no_settlements') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection

@section('scripts')
<style>
.st-tbl th, .st-tbl td { text-align: center; vertical-align: middle; padding-inline: 7px; }
/* العناوين الطويلة بتلفّ بدل ما تزقّ عمود «صفّي» بره الشاشة */
.st-tbl th { white-space: normal; min-width: 72px; }
/* (٢٢/٩) معادلة العمود تحت عنوانه — خط صغير عشان الجدول مايتمدّش */
.st-tbl th .eqh { font-size: 9.5px; font-weight: 500; opacity: .85; white-space: normal; line-height: 1.3; margin-top: 2px; }
</style>
@endsection
