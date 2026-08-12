@extends('layouts.system')

@section('title', __('journey.rep_day').' — '.$rep->displayName())

{{--
    يوم المندوب — إعادة بناء ١٢ أغسطس ٢٠٢٦.

    كل المراسي بالـid مش بالرول عشان المدير الميداني يتحسب زي
    المندوب بالظبط. الأوقات كلها h:i A بتوقيت القاهرة صراحةً —
    اللايف سيرفر ممكن يكون ناسي APP_TIMEZONE. الفلوس من نفس عقيدة
    ١١/٨: فواتيره + أوامره المسلَّمة، وفلوس الأوامر من القيود.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $fm2 = fn ($n) => number_format((float) $n, 2);
    $hia = fn ($dt) => $dt?->copy()->timezone('Africa/Cairo')->format('h:i A');
    $visitMins = fn ($v) => $v !== null && $v->checked_in_at !== null && $v->checked_out_at !== null
        ? (int) round(abs($v->checked_in_at->diffInMinutes($v->checked_out_at)))
        : null;

    $statusClass = [
        'done' => 'b-green',
        'in_visit' => 'b-orange',
        'pending' => 'b-gray',
    ];

    $viewer = auth()->user();
    $canRepScreen = $viewer !== null
        && ($viewer->role !== 'manager' || (int) $rep->manager_id === (int) $viewer->id);
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.live') }}">← {{ __('journey.live') }}</a>
    @if ($canRepScreen)
        <a class="btn" href="{{ route('ops.rep', $rep) }}">🚚 {{ __('nav.reps') }}</a>
    @endif
    @if (\App\Support\Access::allows($viewer, 'ops.tracking'))
        <a class="btn" target="_blank" rel="noopener"
           href="{{ route('ops.tracking', ['user' => $rep->id, 'date' => $date->toDateString()]) }}">
            🛰️ {{ __('journey.rd_btn_tracking') }} ↗
        </a>
    @endif
    @if (\App\Support\Access::allows($viewer, 'erp.repclose.show'))
        <a class="btn" target="_blank" rel="noopener"
           href="{{ route('erp.repclose.show', $rep) }}">
            🤝 {{ __('journey.rd_btn_settle') }} ↗
        </a>
    @endif
@endsection

@section('content')

<div class="card">
    <h3 style="display:flex;gap:10px;align-items:center">
        @include('partials._avatar', ['u' => $rep, 'size' => 38])
        <span>{{ $rep->displayName() }}</span>
        <span class="side">{{ $rep->roleLabel() }} · {{ $date->format('Y-m-d') }} · {{ __('journey.day_'.$date->dayOfWeek) }}</span>
    </h3>

    <form method="GET" action="{{ route('ops.rep_day', $rep) }}" class="searchbar">
        <div>
            <label class="f">{{ __('common.date') }}</label>
            <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()">
        </div>
        <div>
            <label class="f">{{ __('journey.rep') }}</label>
            {{-- التنقل بين أيام الفريق — القايمة فيها المدير الميداني كمان،
                 ومصفّاة بالحارس فمفيش أوبشن بيفتح على 403 --}}
            <select onchange="if (this.value) location.href = this.value">
                @foreach ($repOptions as $o)
                    <option value="{{ route('ops.rep_day', $o) }}?date={{ $date->toDateString() }}"
                        @selected($o->id === $rep->id)>
                        {{ $o->displayName() }} — {{ $o->roleLabel() }}
                    </option>
                @endforeach
            </select>
        </div>
    </form>
</div>

{{-- ═══════════ الحضور — قراءة فقط من يوم الحضور ═══════════ --}}
<div class="card">
    <h3>🕐 {{ __('journey.rd_attendance') }}
        <span class="side">{{ $date->format('Y-m-d') }}</span>
    </h3>

    @if ($hasAtt)
        <div class="kpis">
            <div class="kpi">
                <div class="lbl">🟢 {{ __('journey.rd_att_in') }}</div>
                <div class="val {{ $att['in'] !== null ? 'pos' : '' }}">{{ $att['in'] ?? '—' }}</div>
            </div>
            <div class="kpi">
                <div class="lbl">⏸️ {{ __('journey.rd_att_break') }}</div>
                <div class="val">{{ $att['break_at'] ?? '—' }}</div>
                @if ($att['break_min'] > 0)
                    <div class="sub2">{{ __('journey.dur_min', ['count' => $att['break_min']]) }}</div>
                @endif
            </div>
            <div class="kpi">
                <div class="lbl">🔴 {{ __('journey.rd_att_out') }}</div>
                <div class="val {{ $att['out'] !== null ? '' : 'mid' }}">{{ $att['out'] ?? '—' }}</div>
            </div>
            <div class="kpi">
                <div class="lbl">⏱️ {{ __('journey.rd_att_worked') }}</div>
                <div class="val">{{ $att['worked'] ?? '—' }}</div>
            </div>
        </div>
    @else
        <div style="text-align:center;color:var(--muted);padding:16px">
            {{ __('journey.not_checked_in') }}
        </div>
    @endif
</div>

{{-- ═══════════ خط السير — مخطط / اتعمل / لسه / بره الخطة ═══════════ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('journey.planned') }}</div>
        <div class="val">{{ $fmt($summary['planned']) }}</div>
        <div class="sub2">{{ __('journey.plan') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.done') }}</div>
        <div class="val pos">{{ $fmt($summary['done']) }}</div>
        <div class="sub2">{{ __('journey.completion') }}: {{ $summary['pct'] }}%</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.pending') }}</div>
        <div class="val {{ $summary['pending'] > 0 ? 'mid' : 'pos' }}">{{ $fmt($summary['pending']) }}</div>
        <div class="sub2">{{ __('journey.plan') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.off_plan') }}</div>
        <div class="val">{{ $fmt($summary['off_plan']) }}</div>
        <div class="sub2">{{ __('journey.off_plan_hint') }}</div>
    </div>
</div>

{{-- ═══════════ فلوس وحركة اليوم — العقيدة الموحّدة ═══════════ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">💵 {{ __('journey.sales_today') }}</div>
        <div class="val pos">{{ $fm2($money['sales']) }}</div>
        <div class="sub2">
            {{ __('journey.rd_sales_sub', ['count' => $money['inv_count'], 'inv' => $fm2($money['inv_total'])]) }}
            @if ($money['po_sales'] > 0)
                · {{ __('field.sales_incl_pos', ['v' => $fm2($money['po_sales'])]) }}
            @endif
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">🧾 {{ __('journey.rd_collections') }}</div>
        <div class="val">{{ $fm2($money['coll_total']) }}</div>
        <div class="sub2">{{ __('journey.rd_coll_sub', ['cash' => $fm2($money['coll_cash']), 'other' => $fm2($money['coll_other'])]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">📦 {{ __('journey.rd_custody_left') }}</div>
        <div class="val">{{ $fm2($custodyValue) }}</div>
        <div class="sub2">
            {{ __('journey.rd_custody_now') }}
            @if ($custody !== null && $custodyList !== null)
                · {{ $custodyList->displayName() }}
            @endif
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">🛣️ {{ __('journey.rd_km_today') }}</div>
        <div class="val">{{ number_format($km, 1) }} <span style="font-size:12px">{{ __('journey.km_unit') }}</span></div>
        <div class="sub2">{{ __('journey.rd_km_hint') }}</div>
    </div>
</div>

{{-- ═══════════ خطة السير ═══════════ --}}
<div class="card">
    <h3>📋 {{ __('journey.plan') }} <span class="side">{{ $rows->count() }}</span></h3>

    <div class="tablewrap">
        <table>
            <tr>
                <th class="num">#</th>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('client.zone') }}</th>
                <th>{{ __('journey.frequency') }}</th>
                <th>{{ __('ops.check_in') }}</th>
                <th>{{ __('ops.check_out') }}</th>
                <th data-nosum>{{ __('journey.rd_duration') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>

            @forelse ($rows as $i => $row)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>
                        <a href="{{ route('erp.clients.show', $row['client']) }}">
                            <b>{{ $row['client']->displayName() }}</b>
                        </a>
                        @if ($row['client']->address)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $row['client']->address }}</span>
                        @endif
                    </td>
                    <td class="s">{{ $row['client']->zone?->displayName() ?: '—' }}</td>
                    <td class="s">{{ $row['plan']->frequencyLabel() }}</td>
                    <td class="num s">{{ $hia($row['visit']?->checked_in_at) ?: '—' }}</td>
                    <td class="num s">{{ $hia($row['visit']?->checked_out_at) ?: '—' }}</td>
                    <td class="num s">
                        @php $vm = $visitMins($row['visit']); @endphp
                        {{ $vm !== null ? __('journey.dur_min', ['count' => $vm]) : '—' }}
                    </td>
                    <td>
                        <span class="badge {{ $statusClass[$row['status']] ?? 'b-gray' }}">
                            {{ __('journey.'.$row['status']) }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('journey.no_plan_day') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- ═══════════ بره الخطة — شغل حقيقي بس مش في خطة اليوم ═══════════ --}}
@if ($offPlan->isNotEmpty())
<div class="card">
    <h3>➕ {{ __('journey.off_plan') }} <span class="side">{{ $offPlan->count() }}</span></h3>
    <div class="alert info">{{ __('journey.off_plan_hint') }}</div>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('ops.check_in') }}</th>
                <th>{{ __('ops.check_out') }}</th>
                <th data-nosum>{{ __('journey.rd_duration') }}</th>
                <th></th>
            </tr>
            @foreach ($offPlan as $v)
                <tr>
                    <td>
                        <a href="{{ route('erp.clients.show', $v->client_id) }}">
                            {{ $v->client?->displayName() }}
                        </a>
                    </td>
                    <td class="num s">{{ $hia($v->checked_in_at) ?: '—' }}</td>
                    <td class="num s">{{ $hia($v->checked_out_at) ?: '—' }}</td>
                    <td class="num s">
                        @php $vm = $visitMins($v); @endphp
                        {{ $vm !== null ? __('journey.dur_min', ['count' => $vm]) : '—' }}
                    </td>
                    <td><span class="badge b-purple">{{ __('journey.off_plan') }}</span></td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

{{-- ═══════════ تايم لاين اليوم — سجل التتبع ليستة ═══════════ --}}
<div class="card">
    <h3>🧭 {{ __('journey.rd_timeline') }}
        <span class="side">{{ $timeline->count() }} · {{ __('journey.rd_timeline_hint') }}</span>
    </h3>

    @if ($timeline->isEmpty())
        <div style="text-align:center;color:var(--muted);padding:20px">
            {{ __('journey.no_events_today') }}
        </div>
    @else
        <div style="display:flex;flex-direction:column;gap:0">
            @foreach ($timeline as $e)
                <div style="display:flex;gap:10px;align-items:flex-start;padding:8px 2px;border-bottom:1px solid var(--border)">
                    <span style="width:30px;height:30px;flex:0 0 auto;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;background:{{ $e['color'] }}1a;border:1.5px solid {{ $e['color'] }}">{{ $e['icon'] }}</span>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:700;font-size:12.5px">{{ $e['title'] }}</div>
                        @if ($e['subtitle'])
                            <div style="font-size:11px;color:var(--muted)">{{ $e['subtitle'] }}</div>
                        @endif
                    </div>
                    <span class="num" style="font-size:11.5px;color:var(--muted);white-space:nowrap" dir="ltr">{{ $e['time'] }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- ═══════════ صور ترتيب الرفوف (2026-08-09) ═══════════
     شغل المندوب على الرف قبل وبعد — مجمّعة بالعميل، والصورة
     بتفتح بالحجم الكامل في تاب. --}}
@if ($shelfPhotos->isNotEmpty())
<div class="card">
    <h3>🖼️ {{ __('field.shelf_photos') }} <span class="side">{{ $shelfPhotos->flatten()->count() }}</span></h3>

    @foreach ($shelfPhotos as $clientName => $photos)
        <div style="margin-bottom:14px">
            <div style="font-weight:800;font-size:12.5px;margin-bottom:7px">{{ $clientName }}</div>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                @foreach (['before', 'after'] as $stage)
                    @php $stagePhotos = $photos->where('stage', $stage); @endphp
                    @if ($stagePhotos->isNotEmpty())
                        <div>
                            <div style="font-size:10.5px;color:var(--muted);font-weight:700;margin-bottom:5px">
                                {{ $stage === 'before' ? '📷 '.__('field.shelf_before') : '✨ '.__('field.shelf_after') }}
                                · {{ $stagePhotos->count() }}
                            </div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                @foreach ($stagePhotos as $p)
                                    <a href="{{ $p->url() }}" target="_blank">
                                        <img src="{{ $p->url() }}" alt=""
                                             style="width:84px;height:84px;object-fit:cover;border-radius:10px;border:1px solid var(--border)">
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endif

@endsection
