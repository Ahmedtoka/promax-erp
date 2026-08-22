@extends('layouts.system')

@section('title', __('nav.overview'))

{{-- ═══ الداشبورد الرئيسية (إعادة بناء ٢٢ أغسطس ٢٠٢٦) ═══
     «بالعين أشوف حال الشركة» — كل رقم لينكابل بيفتح تقريره بنفس
     الفلاتر، وتحت كل ويدجت سطر شرح خفيف. الرسومات كلها CSS/SVG
     صافي — مفيش أي مكتبة خارجية (السيرفر بيترفع بالإيد). --}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    // الفلاتر الحالية بتتبعت لكل لينك تقرير — نفس أسماء باراميترات
    // مركز التقارير (from/to/user_id)
    $rq = array_filter(['from' => $from, 'to' => $to, 'user_id' => $repId]);
    $rpt = fn (string $key, array $extra = []) => route('erp.reports.show', array_merge(['key' => $key], $rq, $extra));
    $palette = ['#12399B', '#602D90', '#D74297', '#16A34A', '#B86E00', '#0E7490', '#B00020', '#64748B'];
@endphp

@section('content')

{{-- ═══ الفلاتر — بتسمع في كل ويدجت تحت ═══ --}}
<div class="card" style="padding:12px 14px">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div>
            <label class="f">{{ __('rpt.f_from') }}</label>
            <input type="date" name="from" value="{{ $from }}">
        </div>
        <div>
            <label class="f">{{ __('rpt.f_to') }}</label>
            <input type="date" name="to" value="{{ $to }}">
        </div>
        @if ($managers->isNotEmpty())
            <div style="min-width:170px">
                <label class="f">{{ __('dash.f_manager') }}</label>
                <select name="manager_id" onchange="this.form.submit()">
                    <option value="">{{ __('rpt.f_all') }}</option>
                    @foreach ($managers as $m)
                        <option value="{{ $m->id }}" @selected($mgrId === $m->id)>{{ $m->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div style="min-width:170px">
            <label class="f">{{ __('rpt.f_rep') }}</label>
            <select name="user_id">
                <option value="">{{ __('rpt.f_all') }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected($repId === $r->id)>{{ $r->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn gold" type="submit">🔍 {{ __('rpt.apply') }}</button>
        <a class="btn" href="{{ route('erp.overview') }}">{{ __('common.clear') }}</a>
    </form>
    <div class="s" style="color:var(--muted);margin-top:6px">{{ __('dash.filters_hint') }}</div>
</div>

{{-- ═══ صف الأرقام الكبيرة — كله لينكابل ═══ --}}
<div class="kpis">
    <a class="kpi dash-link" href="{{ $rpt('sales_docs') }}">
        <div class="lbl">💰 {{ __('dash.k_sales') }}</div>
        <div class="val pos">{{ $fmt($inv->g) }}</div>
        <div class="sub2">{{ $fmt($inv->n) }} {{ __('rpt.k_count') }} · 💵 {{ $fmt($inv->cash_g) }} {{ __('rpt.cash') }}</div>
        <div class="dash-hint">{{ __('dash.h_sales') }}</div>
    </a>
    <a class="kpi dash-link" href="{{ $rpt('collections') }}">
        <div class="lbl">🧾 {{ __('dash.k_coll') }}</div>
        <div class="val pos">{{ $fmt($coll) }}</div>
        <div class="sub2">{{ $inv->g > 0 ? number_format($coll / $inv->g * 100, 1) : 0 }}% {{ __('dash.of_sales') }}</div>
        <div class="dash-hint">{{ __('dash.h_coll') }}</div>
    </a>
    <a class="kpi dash-link" href="{{ $rpt('returns_docs') }}">
        <div class="lbl">↩️ {{ __('rpt.k_returns') }}</div>
        <div class="val neg">{{ $fmt($rets->g) }}</div>
        <div class="sub2">{{ $fmt($rets->n) }} {{ __('dash.docs') }}</div>
        <div class="dash-hint">{{ __('dash.h_returns') }}</div>
    </a>
    <a class="kpi dash-link" href="{{ $rpt('debts') }}">
        <div class="lbl">⏳ {{ __('rpt.k_balance') }}</div>
        <div class="val mid">{{ $fmt($debt->g) }}</div>
        <div class="sub2">{{ $fmt($debt->n) }} {{ __('rpt.k_clients') }}</div>
        <div class="dash-hint">{{ __('dash.h_debt') }}</div>
    </a>
    <a class="kpi dash-link" href="{{ $rpt('visits_log') }}">
        <div class="lbl">🚪 {{ __('rpt.k_visits') }}</div>
        <div class="val">{{ $fmt($visitsN) }}</div>
        <div class="sub2">🎁 {{ $fmt($giftsQ) }} {{ __('rpt.k_gifts') }}</div>
        <div class="dash-hint">{{ __('dash.h_visits') }}</div>
    </a>
    <a class="kpi dash-link" href="{{ $rpt('new_clients') }}">
        <div class="lbl">✨ {{ __('rpt.new_clients') }}</div>
        <div class="val">{{ $fmt($newClientsN) }}</div>
        <div class="sub2">📋 {{ $fmt($openRequests) }} {{ __('dash.pending_req') }}</div>
        <div class="dash-hint">{{ __('dash.h_new') }}</div>
    </a>
</div>

{{-- ═══ الصف التاني: المبيعات مقابل التحصيل + دونات القنوات ═══ --}}
<div class="dash-grid2">
    <div class="card">
        <h3>📈 {{ __('dash.chart_flow') }}
            <span class="side">{{ $daily ? __('dash.by_day') : __('dash.by_month') }}</span></h3>
        @php $maxV = max(1, collect($series)->flatMap(fn ($s) => [$s['sales'], $s['coll']])->max()); @endphp
        <div class="dash-bars">
            @foreach ($series as $k => $s)
                <a class="dash-bcol" href="{{ $rpt('sales_docs', $daily ? ['from' => $k, 'to' => $k] : []) }}"
                   title="{{ $k }} — {{ __('dash.k_sales') }}: {{ $fmt($s['sales']) }} · {{ __('dash.k_coll') }}: {{ $fmt($s['coll']) }}">
                    <span class="b sales" style="height:{{ round($s['sales'] / $maxV * 100) }}%"></span>
                    <span class="b coll" style="height:{{ round($s['coll'] / $maxV * 100) }}%"></span>
                    <i>{{ $daily ? \Illuminate\Support\Carbon::parse($k)->format('d') : \Illuminate\Support\Carbon::parse($k.'-01')->format('m/y') }}</i>
                </a>
            @endforeach
        </div>
        <div class="dash-legend">
            <span><i style="background:var(--royal-blue)"></i> {{ __('dash.k_sales') }}</span>
            <span><i style="background:#16A34A"></i> {{ __('dash.k_coll') }}</span>
        </div>
        <div class="dash-hint">{{ __('dash.h_flow') }}</div>
    </div>

    <div class="card">
        <h3>🎯 {{ __('rpt.sales_by_channel') }}</h3>
        @php
            $chTotal = max(1, $byChannel->sum('v'));
            $deg = 0;
            $stops = [];
            foreach ($byChannel as $i => $ch) {
                $slice = $ch->v / $chTotal * 360;
                $stops[] = $palette[$i % count($palette)].' '.round($deg).'deg '.round($deg + $slice).'deg';
                $deg += $slice;
            }
        @endphp
        <div class="dash-donutwrap">
            <div class="dash-donut" style="background:conic-gradient({{ $stops ? implode(',', $stops) : 'var(--border) 0deg 360deg' }})">
                <div class="hole"><b>{{ $fmt($chTotal > 1 ? $chTotal : 0) }}</b><i>{{ __('rpt.k_grand') }}</i></div>
            </div>
            <div class="dash-dlegend">
                @forelse ($byChannel as $i => $ch)
                    <a href="{{ $rpt('sales_by_channel') }}">
                        <i style="background:{{ $palette[$i % count($palette)] }}"></i>
                        <span>{{ $ch->cname }}</span>
                        <b>{{ number_format($ch->v / $chTotal * 100, 1) }}%</b>
                    </a>
                @empty
                    <div class="s" style="color:var(--muted)">{{ __('rpt.no_rows') }}</div>
                @endforelse
            </div>
        </div>
        <div class="dash-hint">{{ __('dash.h_channels') }}</div>
    </div>
</div>

{{-- ═══ الصف التالت: العائلات + تصنيفات العملاء + أعمار الديون ═══ --}}
<div class="dash-grid3">
    <div class="card">
        <h3>🧬 {{ __('dash.chart_families') }}</h3>
        @php $famMax = max(1, $byFamily->max('v') ?? 1); @endphp
        <div class="dash-hbars">
            @forelse ($byFamily->take(7) as $f)
                <a class="dash-hrow" href="{{ $rpt('sales_by_product') }}">
                    <span class="nm">{{ \App\Models\ProductFamily::label($f->family) }}</span>
                    <span class="tr"><span class="fill" style="width:{{ round($f->v / $famMax * 100) }}%"></span></span>
                    <b>{{ $fmt($f->v) }}</b>
                </a>
            @empty
                <div class="s" style="color:var(--muted)">{{ __('rpt.no_rows') }}</div>
            @endforelse
        </div>
        <div class="dash-hint">{{ __('dash.h_families') }}</div>
    </div>

    <div class="card">
        <h3>🏷️ {{ __('dash.chart_cats') }}</h3>
        @php
            $catTotal = max(1, array_sum($catCounts));
            $catColors = ['danger' => '#B00020', 'watch' => '#B86E00', 'grow' => '#12399B', 'ok' => '#16A34A', 'idle' => '#64748B', 'credit' => '#0E7490'];
            $deg2 = 0; $stops2 = [];
            foreach ($catCounts as $cat => $n) {
                $slice = $n / $catTotal * 360;
                $stops2[] = ($catColors[$cat] ?? '#64748B').' '.round($deg2).'deg '.round($deg2 + $slice).'deg';
                $deg2 += $slice;
            }
        @endphp
        <div class="dash-donutwrap">
            <div class="dash-donut sm" style="background:conic-gradient({{ $stops2 ? implode(',', $stops2) : 'var(--border) 0deg 360deg' }})">
                <div class="hole"><b>{{ $fmt($catTotal) }}</b><i>{{ __('rpt.k_clients') }}</i></div>
            </div>
            <div class="dash-dlegend">
                @foreach ($catCounts as $cat => $n)
                    <a href="{{ route('erp.clients', ['cat' => $cat]) }}">
                        <i style="background:{{ $catColors[$cat] ?? '#64748B' }}"></i>
                        <span>{{ __('enums.category.'.$cat) }}</span>
                        <b>{{ $fmt($n) }}</b>
                    </a>
                @endforeach
            </div>
        </div>
        <div class="dash-hint">{{ __('dash.h_cats') }}</div>
    </div>

    <div class="card">
        <h3>⏳ {{ __('dash.chart_aging') }}</h3>
        @php
            $agTotal = max(1, array_sum($aging));
            $agLabels = ['a30' => '≤30', 'a60' => '31-60', 'a90' => '61-90', 'a180' => '91-180', 'a180p' => '+180'];
            $agColors = ['a30' => '#16A34A', 'a60' => '#12399B', 'a90' => '#B86E00', 'a180' => '#D74297', 'a180p' => '#B00020'];
        @endphp
        <a href="{{ route('erp.reports', ['tab' => 'aging']) }}" class="dash-stack">
            @foreach ($aging as $k => $v)
                @if ($v > 0)
                    <span style="width:{{ max(2, round($v / $agTotal * 100)) }}%;background:{{ $agColors[$k] }}"
                          title="{{ $agLabels[$k] }} {{ __('dash.days') }}: {{ $fmt($v) }}"></span>
                @endif
            @endforeach
        </a>
        <div class="dash-dlegend" style="margin-top:10px">
            @foreach ($aging as $k => $v)
                <a href="{{ route('erp.reports', ['tab' => 'aging']) }}">
                    <i style="background:{{ $agColors[$k] }}"></i>
                    <span>{{ $agLabels[$k] }} {{ __('dash.days') }}</span>
                    <b>{{ $fmt($v) }}</b>
                </a>
            @endforeach
        </div>
        <div class="dash-hint">{{ __('dash.h_aging') }}</div>
    </div>
</div>

{{-- ═══ الصف الرابع: أفضل المناديب + أفضل العملاء ═══ --}}
<div class="dash-grid2">
    <div class="card">
        <h3>🧑‍💼 {{ __('dash.top_reps') }}
            <a class="side" href="{{ $rpt('reps_overview') }}">{{ __('dash.full_report') }} ←</a></h3>
        @php $repMax = max(1, $topReps->max('v') ?? 1); @endphp
        <div class="dash-hbars">
            @forelse ($topReps as $r)
                <a class="dash-hrow" href="{{ $rpt('sales_docs', ['user_id' => $r->user_id]) }}">
                    <span class="nm">{{ $r->rep?->displayName() ?? '—' }}</span>
                    <span class="tr"><span class="fill alt" style="width:{{ round($r->v / $repMax * 100) }}%"></span></span>
                    <b>{{ $fmt($r->v) }}</b>
                </a>
            @empty
                <div class="s" style="color:var(--muted)">{{ __('rpt.no_rows') }}</div>
            @endforelse
        </div>
        <div class="dash-hint">{{ __('dash.h_top_reps') }}</div>
    </div>

    <div class="card">
        <h3>🏆 {{ __('dash.top_clients') }}
            <a class="side" href="{{ $rpt('sales_by_client') }}">{{ __('dash.full_report') }} ←</a></h3>
        <div class="tablewrap" style="max-height:320px;overflow:auto">
            <table>
                <thead><tr>
                    <th style="text-align:start">{{ __('rpt.c_client') }}</th>
                    <th>{{ __('client.channel') }}</th>
                    <th class="num">{{ __('rpt.k_count') }}</th>
                    <th class="num">{{ __('rpt.k_grand') }}</th>
                    <th class="num">{{ __('rpt.k_balance') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($topClients as $tc)
                    @php $c = $topClientRows->get($tc->client_id); @endphp
                    <tr class="clickable" onclick="location.href='{{ $c ? route('erp.clients.show', $c) : '#' }}'">
                        <td style="text-align:start"><b>{{ $c?->fullName() ?? '—' }}</b></td>
                        <td><span class="badge b-purple">{{ $c?->channel?->displayName() ?? '—' }}</span></td>
                        <td class="num">{{ $fmt($tc->n) }}</td>
                        <td class="num pos"><b>{{ $fmt($tc->v) }}</b></td>
                        <td class="num {{ ($c?->balance ?? 0) > 0 ? 'mid' : '' }}">{{ $fmt($c?->balance ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">{{ __('rpt.no_rows') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="dash-hint">{{ __('dash.h_top_clients') }}</div>
    </div>
</div>

{{-- ═══ شريط سريع تحت: مخزون + أوامر مفتوحة ═══ --}}
<div class="kpis">
    <a class="kpi dash-link" href="{{ route('erp.stock') }}">
        <div class="lbl">📦 {{ __('dash.k_stock') }}</div>
        <div class="val">{{ $fmt($stockValue) }}</div>
        <div class="dash-hint">{{ __('dash.h_stock') }}</div>
    </a>
    <a class="kpi dash-link" href="{{ $rpt('pos_status') }}">
        <div class="lbl">🚚 {{ __('dash.k_open_pos') }}</div>
        <div class="val mid">{{ $fmt($openPos) }}</div>
        <div class="dash-hint">{{ __('dash.h_open_pos') }}</div>
    </a>
    <a class="kpi dash-link" href="{{ $rpt('inactive_clients') }}">
        <div class="lbl">😴 {{ __('rpt.inactive_clients') }}</div>
        <div class="val">←</div>
        <div class="dash-hint">{{ __('dash.h_inactive') }}</div>
    </a>
    <a class="kpi dash-link" href="{{ route('erp.reports.hub') }}">
        <div class="lbl">📑 {{ __('rpt.hub_title') }}</div>
        <div class="val">←</div>
        <div class="dash-hint">{{ __('dash.h_hub') }}</div>
    </a>
</div>

@endsection

@section('scripts')
<style>
/* ═══ ستايل الداشبورد (٢٢/٨) — CSS صافي، صفر مكتبات ═══ */
.dash-link{display:block;text-decoration:none;color:inherit;transition:box-shadow .12s,border-color .12s}
.dash-link:hover{border-color:var(--royal-blue);box-shadow:0 4px 14px rgba(18,57,155,.12)}
.dash-hint{font-size:10.5px;color:var(--muted);margin-top:7px;line-height:1.6}
.dash-grid2{display:grid;grid-template-columns:1.35fr 1fr;gap:14px;margin-top:14px}
.dash-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-top:14px}
@media (max-width:1100px){.dash-grid2,.dash-grid3{grid-template-columns:1fr}}

/* أعمدة المبيعات/التحصيل */
.dash-bars{display:flex;align-items:flex-end;gap:4px;height:190px;padding-top:8px}
.dash-bcol{flex:1;display:flex;align-items:flex-end;justify-content:center;gap:2px;height:100%;
  position:relative;text-decoration:none;border-radius:6px}
.dash-bcol:hover{background:var(--blue-050)}
.dash-bcol .b{width:38%;min-height:2px;border-radius:4px 4px 0 0}
.dash-bcol .b.sales{background:var(--royal-blue)}
.dash-bcol .b.coll{background:#16A34A}
.dash-bcol i{position:absolute;bottom:-16px;font-size:9px;color:var(--muted);font-style:normal}
.dash-legend{display:flex;gap:16px;margin-top:24px;font-size:11px;color:var(--muted)}
.dash-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-inline-end:4px}

/* الدونات */
.dash-donutwrap{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.dash-donut{width:150px;height:150px;border-radius:50%;position:relative;flex-shrink:0}
.dash-donut.sm{width:130px;height:130px}
.dash-donut .hole{
  position:absolute;inset:22%;background:var(--card);border-radius:50%;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
}
.dash-donut .hole b{font-size:13px}
.dash-donut .hole i{font-style:normal;font-size:9.5px;color:var(--muted)}
.dash-dlegend{flex:1;min-width:150px;display:flex;flex-direction:column;gap:5px}
.dash-dlegend a{display:flex;align-items:center;gap:7px;font-size:11.5px;text-decoration:none;color:var(--ink);
  padding:3px 6px;border-radius:7px}
.dash-dlegend a:hover{background:var(--blue-050)}
.dash-dlegend i{width:10px;height:10px;border-radius:3px;flex-shrink:0}
.dash-dlegend span{flex:1}
.dash-dlegend b{font-size:11px}

/* البارات الأفقية */
.dash-hbars{display:flex;flex-direction:column;gap:7px}
.dash-hrow{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--ink);
  padding:3px 5px;border-radius:8px}
.dash-hrow:hover{background:var(--blue-050)}
.dash-hrow .nm{width:120px;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dash-hrow .tr{flex:1;height:14px;background:var(--card2);border-radius:7px;overflow:hidden}
.dash-hrow .fill{display:block;height:100%;background:linear-gradient(90deg,var(--royal-blue),#602D90);border-radius:7px}
.dash-hrow .fill.alt{background:linear-gradient(90deg,#602D90,#D74297)}
.dash-hrow b{font-size:11.5px;min-width:70px;text-align:end}

/* شريط الأعمار المكدس */
.dash-stack{display:flex;height:26px;border-radius:9px;overflow:hidden;text-decoration:none}
.dash-stack span{display:block;height:100%}
</style>
@endsection
