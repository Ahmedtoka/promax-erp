@extends('layouts.system')

@section('title', __('journey.live'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $money = fn ($n) => number_format((float) $n, 2);
    $pct = $totals['planned'] > 0 ? round($totals['done'] / $totals['planned'] * 100, 1) : 0;
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.journeys') }}">🗺️ {{ __('journey.page') }}</a>
    <a class="btn" href="{{ route('ops.tracking') }}">📍 {{ __('nav.tracking') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>📡 {{ __('journey.live') }}
        <span class="side">{{ __('journey.live_sub') }} · {{ $date }}</span>
    </h3>
    <div style="font-size:11.5px;color:var(--muted)">{{ __('journey.refresh_note') }}</div>
</div>

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('journey.active_reps') }}</div>
        <div class="val">{{ $fmt($totals['active']) }} <span style="font-size:14px;color:var(--muted)">/ {{ $fmt($totals['reps']) }}</span></div>
        <div class="sub2">{{ __('journey.on_map') }}: {{ $onMap->count() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.planned') }}</div>
        <div class="val">{{ $fmt($totals['planned']) }}</div>
        <div class="sub2">{{ __('journey.page') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.done') }}</div>
        <div class="val pos">{{ $fmt($totals['done']) }}</div>
        <div class="sub2">{{ __('journey.completion') }}: {{ $pct }}%</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.stock_value') }}</div>
        <div class="val num">{{ $money($totals['value']) }}</div>
        <div class="sub2">{{ __('common.currency') }}</div>
    </div>
</div>

{{-- ═══════════ الخريطة ═══════════ --}}
<div class="card">
    <h3>🗺️ {{ __('journey.on_map') }} <span class="side">{{ $onMap->count() }}</span></h3>

    @if ($onMap->isEmpty())
        <div class="alert info">{{ __('journey.no_activity') }}</div>
    @else
        <div id="liveMap" class="mapbox"></div>
    @endif
</div>

{{-- ═══════════ المناديب ═══════════ --}}
<div class="card">
    <h3>🧑‍💼 {{ __('journey.rep') }} <span class="side">{{ $rows->count() }}</span></h3>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('journey.rep') }}</th>
                <th>{{ __('client.zone') }}</th>
                <th class="num">{{ __('journey.planned') }}</th>
                <th class="num">{{ __('journey.done') }}</th>
                <th class="num">{{ __('journey.off_plan') }}</th>
                <th class="num">{{ __('journey.completion') }}</th>
                <th class="num">{{ __('journey.stock_units') }}</th>
                <th class="num">{{ __('journey.stock_value') }}</th>
                <th>{{ __('journey.last_seen') }}</th>
                <th></th>
            </tr>

            @foreach ($rows as $r)
                @php
                    $s = $r['summary'];
                    $has = $r['last'] !== null;
                @endphp
                <tr>
                    <td>
                        <b>{{ $r['rep']->displayName() }}</b>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $r['rep']->roleLabel() }}</span>
                    </td>
                    <td class="s">{{ $r['rep']->zone?->displayName() ?: '—' }}</td>
                    <td class="num">{{ $fmt($s['planned']) }}</td>
                    <td class="num pos">{{ $fmt($s['done']) }}</td>
                    <td class="num {{ $s['off_plan'] > 0 ? 'mid' : '' }}"
                        @if ($s['off_plan'] > 0) title="{{ __('journey.off_plan_hint') }}" @endif>
                        {{ $fmt($s['off_plan']) }}
                    </td>
                    <td class="num">
                        <span class="badge {{ $s['pct'] >= 80 ? 'b-green' : ($s['pct'] >= 40 ? 'b-orange' : 'b-red') }}">
                            {{ $s['pct'] }}%
                        </span>
                    </td>
                    <td class="num">{{ $fmt($r['remaining_units']) }}</td>
                    <td class="num">{{ $money($r['remaining_value']) }}</td>
                    <td class="s">
                        @if ($has)
                            {{ $r['minutes_ago'] < 2 ? __('journey.now') : __('journey.minutes_ago', ['count' => $r['minutes_ago']]) }}
                            <br><span style="font-size:10px;color:var(--muted)">{{ $r['last']->title }}</span>
                        @else
                            <span style="color:var(--muted)">{{ __('journey.no_location') }}</span>
                        @endif
                    </td>
                    <td class="num">
                        <a class="btn sm" href="{{ route('ops.rep_day', $r['rep']) }}">{{ __('journey.rep_day') }}</a>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

@endsection

@section('scripts')
@php
    // ⚠️ الداتا بتتجهّز هنا في PHP — ممنوع لوب بليد جوه الجافاسكريبت.
    $markers = $onMap->map(fn ($r) => [
        'name' => $r['rep']->displayName(),
        'lat' => (float) $r['lat'],
        'lng' => (float) $r['lng'],
        'pct' => $r['summary']['pct'],
        'done' => $r['summary']['done'],
        'planned' => $r['summary']['planned'],
        'units' => $r['remaining_units'],
        'url' => route('ops.rep_day', $r['rep']),
    ])->values()->all();
@endphp
<script>
const MARKERS = {!! json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};

if (MARKERS.length > 0) {
    const map = L.map('liveMap');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap',
    }).addTo(map);

    const bounds = [];
    MARKERS.forEach(m => {
        // لون العلامة بيتبع نسبة الإنجاز — الأحمر بيبان من بعيد
        const color = m.pct >= 80 ? '#0F7A38' : (m.pct >= 40 ? '#B96C0A' : '#DC2626');

        L.circleMarker([m.lat, m.lng], {
            radius: 9, color: '#fff', weight: 2,
            fillColor: color, fillOpacity: .92,
        }).addTo(map).bindPopup(
            '<b>' + m.name + '</b><br>'
            + m.done + ' / ' + m.planned + ' (' + m.pct + '%)<br>'
            + m.units + '<br>'
            + '<a href="' + m.url + '">→</a>'
        );

        bounds.push([m.lat, m.lng]);
    });

    map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
}

// ⚠️ الترفريش بيتلغى لو الصفحة مخفية — تبويب مفتوح في الخلفية
// طول اليوم بيفضل يضرب السيرفر كل دقيقة من غير ما حد يبصله.
setInterval(() => {
    if (! document.hidden) { location.reload(); }
}, 60000);
</script>
@endsection
