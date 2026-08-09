@extends('layouts.system')

{{--
    التراكينج — إعادة بناء ٩ أغسطس ٢٠٢٦ (طلب المالك):

    «كل المناديب مرة واحدة» هو الافتراضي: كل مندوب ليه لون ثابت
    وماركر بصورته (أو دايرة بحروف اسمه لحد ما يرفع صورة من «حسابي»)،
    ومساره مرسوم بلونه. الشيبس فوق الخريطة بتخفي/تظهر مندوب بضغطة.

    التايم لاين بقى بالتاريخ والوقت مكتوبين على كل حدث، وبصورة صاحب
    الحدث وأيقونة نوعه — والأحداث اللي من غير إحداثيات (GPS فشل قبل
    فولباك ٩/٨) بتتحسب وبتتقال صراحة تحت الخريطة بدل ما تختفي بصمت.
--}}

@section('title', __('ops.tracking'))

@section('content')

@php
    $fmtTime = fn ($e) => $e->happened_at->format('h:i A');
    $fmtDate = fn ($e) => $e->happened_at->format('d/m');
    $repCount = $reps->count();
    $noGeo = $events->filter(fn ($e) => ! ($e->lat && $e->lng))->count();
@endphp

{{-- ═══ الفلتر ═══ --}}
<form class="filters" method="GET" style="margin-bottom:14px">
    <div style="flex:0 1 260px">
        <label class="f">{{ __('hr.employee') }}</label>
        <select name="user" onchange="this.form.submit()">
            <option value="">{{ __('ops.all_reps') }}</option>
            @foreach ($field as $f)
                <option value="{{ $f->id }}" @selected($userId === $f->id)>{{ $f->displayName() }} ({{ $f->code }})</option>
            @endforeach
        </select>
    </div>
    <div style="flex:0 1 190px">
        <label class="f">{{ __('hr.date') }}</label>
        <input type="date" name="date" value="{{ $date }}" onchange="this.form.submit()">
    </div>
    <button class="btn gold" type="submit">{{ __('ops.show') }}</button>
</form>

{{-- ═══ سامري اليوم ═══ --}}
<div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><div class="lbl">🧑‍💼 {{ __('ops.trk_reps') }}</div><div class="val">{{ $repCount }}</div></div>
    <div class="kpi"><div class="lbl">⚡ {{ __('ops.trk_events') }}</div><div class="val">{{ $events->count() }}</div></div>
    <div class="kpi"><div class="lbl">📍 {{ __('ops.trk_visits') }}</div><div class="val">{{ $events->where('type', 'check_in')->count() }}</div></div>
    <div class="kpi"><div class="lbl">💰 {{ __('ops.trk_sales') }}</div><div class="val pos">{{ $events->where('type', 'sale')->count() }}</div></div>
    <div class="kpi"><div class="lbl">🧾 {{ __('ops.trk_collects') }}</div><div class="val">{{ $events->where('type', 'collect')->count() }}</div></div>
</div>

{{-- ═══ شيبس المناديب — إخفاء/إظهار بضغطة ═══ --}}
@if ($repCount > 1)
    <div class="card" style="padding:10px 14px">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <span style="font-size:11px;font-weight:800;color:var(--muted)">{{ __('ops.toggle_rep_hint') }}</span>
            @foreach ($reps as $r)
                <button type="button" class="trk-chip" data-uid="{{ $r->id }}"
                        style="--rc: {{ $colors[$r->id] }}">
                    @include('partials._avatar', ['u' => $r, 'size' => 24, 'ring' => $colors[$r->id]])
                    <b>{{ $r->displayName() }}</b>
                    <span class="badge b-gray" style="font-size:10px">{{ $events->where('user_id', $r->id)->count() }}</span>
                </button>
            @endforeach
        </div>
    </div>
@endif

<div class="card">
    <div class="grid2">
        <div>
            <div class="mapbox" id="map" style="height:480px"></div>
            <div style="font-size:11px;color:var(--muted);margin-top:6px;display:flex;gap:12px;flex-wrap:wrap">
                @foreach ($events->pluck('type')->unique()->take(8) as $t)
                    @php $ev = $events->firstWhere('type', $t); @endphp
                    <span>{{ $ev->icon() }} {{ $ev->typeLabel() }}</span>
                @endforeach
                <span style="margin-inline-start:auto">{{ __('ops.map_zoom_hint') }}</span>
            </div>
            @if ($noGeo > 0)
                <div class="alert info" style="margin-top:8px">
                    <span>ℹ️</span><span>{{ __('ops.no_coords_note', ['count' => $noGeo]) }}</span>
                </div>
            @endif
        </div>

        {{-- ═══ التايم لاين — بالتاريخ والوقت والصورة ═══ --}}
        <div style="max-height:520px;overflow-y:auto" id="timeline">
            @forelse ($events as $e)
                <div class="trk-row" data-uid="{{ $e->user_id }}"
                     style="border-inline-start:4px solid {{ $colors[$e->user_id] ?? 'var(--border)' }}">
                    @include('partials._avatar', ['u' => $e->user, 'size' => 34, 'ring' => $colors[$e->user_id] ?? null])
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap">
                            <b style="font-size:12.5px;color:{{ $colors[$e->user_id] ?? 'inherit' }}">{{ $e->user->displayName() }}</b>
                            <span style="font-size:12.5px">{{ $e->icon() }} {{ $e->title }}</span>
                        </div>
                        @if ($e->subtitle)
                            <div style="font-size:11px;color:var(--muted);margin-top:2px">{{ $e->subtitle }}</div>
                        @endif
                    </div>
                    <div style="text-align:end;flex-shrink:0">
                        <div dir="ltr" style="font-size:12px;font-weight:900">{{ $fmtTime($e) }}</div>
                        <div dir="ltr" style="font-size:10px;color:var(--muted)">{{ $fmtDate($e) }}</div>
                        @unless ($e->lat && $e->lng)
                            <div style="font-size:9.5px;color:var(--muted)" title="{{ __('ops.no_coords_one') }}">📵</div>
                        @endunless
                    </div>
                </div>
            @empty
                <div class="empty">{{ __('ops.no_movements_that_day') }}</div>
            @endforelse
        </div>
    </div>
</div>

@endsection

@section('scripts')
<style>
.trk-chip {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 5px 11px; border-radius: 999px; cursor: pointer;
    border: 1.5px solid var(--rc); background: color-mix(in srgb, var(--rc) 8%, transparent);
    font: inherit; font-size: 12px;
}
.trk-chip.off { opacity: .32; border-style: dashed; }
.trk-row {
    display: flex; gap: 10px; align-items: center;
    padding: 9px 12px; margin-bottom: 8px;
    background: var(--card2, #FAFAFC); border: 1px solid var(--border);
    border-radius: 12px;
}
</style>
@php
    // بترتيب الوقت من الأقدم للأحدث عشان مسار كل مندوب يترسم صح.
    // النقطة = الحدث + هوية صاحبه (صورة/حروف/لون) — الماركر بيترسم
    // divIcon بالصورة نفسها مش دبوس مجهول.
    $pts = $events->reverse()->values()
        ->filter(fn ($e) => $e->lat && $e->lng)
        ->map(fn ($e) => [
            'lat' => (float) $e->lat,
            'lng' => (float) $e->lng,
            'uid' => $e->user_id,
            'icon' => $e->icon(),
            'title' => $e->icon().' '.$e->title,
            'sub' => trim(($e->subtitle ? $e->subtitle.' • ' : '')
                .$e->user->displayName().' • '
                .$e->happened_at->format('d/m · h:i A')),
            'avatar' => $e->user->avatarUrl(),
            'initials' => $e->user->initials(),
            'color' => $colors[$e->user_id] ?? '#12399B',
        ])->values();
@endphp
<script>
(function () {
  'use strict';

  const PTS = {!! json_encode($pts, JSON_UNESCAPED_UNICODE) !!};
  const el = document.getElementById('map');

  if (!PTS.length) {
    el.innerHTML = '<div style="display:grid;place-items:center;height:100%;color:var(--muted);font-size:13px">'
      + {!! json_encode(__('common.no_map_points'), JSON_UNESCAPED_UNICODE) !!} + '</div>';
    return;
  }

  const map = L.map('map', { scrollWheelZoom: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  /* ماركر بصورة الموظف — أو دايرة بحروفه بلونه لحد ما يرفع صورة.
     البادج الصغير تحت يمين = أيقونة نوع الحدث (إيموچي، بلا أصول) */
  function avatarIcon(p) {
    const inner = p.avatar
      ? '<img src="' + p.avatar + '" style="width:100%;height:100%;object-fit:cover;border-radius:50%">'
      : '<span style="display:flex;width:100%;height:100%;align-items:center;justify-content:center;'
        + 'background:' + p.color + ';color:#fff;font:900 12px Cairo,Inter,sans-serif;border-radius:50%">'
        + p.initials + '</span>';

    return L.divIcon({
      className: '',
      iconSize: [38, 38],
      iconAnchor: [19, 19],
      popupAnchor: [0, -20],
      html: '<div style="position:relative;width:38px;height:38px">'
        + '<div style="width:34px;height:34px;border-radius:50%;border:2.5px solid ' + p.color + ';'
        + 'box-shadow:0 1px 6px rgba(0,0,0,.35);overflow:hidden;background:#fff">' + inner + '</div>'
        + '<div style="position:absolute;bottom:-2px;right:-4px;font-size:13px;'
        + 'text-shadow:0 0 3px #fff,0 0 3px #fff">' + p.icon + '</div>'
        + '</div>',
    });
  }

  /* طبقة لكل مندوب: الماركرز + خط مساره بلونه — الشيبس بتشيل
     وتحط الطبقة كاملة بضغطة */
  const layers = {};
  const allLatLngs = [];

  PTS.forEach(function (p) {
    const uid = String(p.uid);
    if (!layers[uid]) layers[uid] = { group: L.layerGroup().addTo(map), path: [], color: p.color };

    const m = L.marker([p.lat, p.lng], { icon: avatarIcon(p) });
    m.bindPopup('<div style="font:600 13px ' + (IS_RTL ? 'Cairo' : 'Inter') + ',sans-serif;'
      + 'direction:' + (IS_RTL ? 'rtl' : 'ltr') + ';text-align:start">' + p.title
      + '<div style="font-weight:400;font-size:11.5px;color:#6B6B66;margin-top:3px">' + p.sub + '</div></div>');
    layers[uid].group.addLayer(m);
    layers[uid].path.push([p.lat, p.lng]);
    allLatLngs.push([p.lat, p.lng]);
  });

  Object.values(layers).forEach(function (l) {
    if (l.path.length > 1) {
      l.group.addLayer(L.polyline(l.path, { color: l.color, weight: 4, opacity: .8 }));
    }
  });

  if (allLatLngs.length === 1) map.setView(allLatLngs[0], 15);
  else map.fitBounds(L.latLngBounds(allLatLngs).pad(0.18));

  map.on('click', function () { map.scrollWheelZoom.enable(); });
  map.on('mouseout', function () { map.scrollWheelZoom.disable(); });

  /* الشيبس: إخفاء/إظهار مندوب — من الخريطة ومن التايم لاين مع بعض */
  document.querySelectorAll('.trk-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      const uid = chip.dataset.uid;
      const off = chip.classList.toggle('off');

      if (layers[uid]) {
        if (off) map.removeLayer(layers[uid].group);
        else layers[uid].group.addTo(map);
      }
      document.querySelectorAll('.trk-row[data-uid="' + uid + '"]').forEach(function (row) {
        row.style.display = off ? 'none' : '';
      });
    });
  });
})();
</script>
@endsection
