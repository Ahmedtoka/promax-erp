@extends('layouts.system')

{{--
    راسم خط السير التفاعلي (٦/٩/٢٠٢٦ — طلب المالك):

    فلتر بالمنطقة/القسم ← المحلات تظهر نقط رمادي على الخريطة ←
    تدوس عليها بالترتيب فتترقّم ١ ٢ ٣ ويترسم خط بينها ← تختار
    المندوب واليوم ← Apply: بيسكّن الليدات للمندوب وبيكتبهم في
    خطة اليوم بنفس الترتيب (بيظهروا له في «مجدولين النهارده»).
--}}

@section('title', __('lead.route_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.leads') }}">← {{ __('lead.page') }}</a>
    <a class="btn" href="{{ route('erp.leads.planner') }}">📅 {{ __('lead.planner_title') }}</a>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif
@if ($errors->any())
    <div class="alert" style="margin-bottom:12px"><span>⚠️</span><span>{{ $errors->first() }}</span></div>
@endif

{{-- ═══ الفلاتر — بليبلات ═══ --}}
<div class="card" style="margin-bottom:12px">
    <form method="GET" action="{{ route('erp.leads.route') }}" class="searchbar" style="align-items:flex-end;row-gap:10px">
        <div style="flex:1;min-width:180px">
            <label class="f">🔎 {{ __('common.search') }}</label>
            <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" style="width:100%">
        </div>
        <div>
            <label class="f">{{ __('client.zone') }}</label>
            @include('partials._zone_select', [
                'zones' => $zones,
                'name' => 'zone',
                'selected' => $filters['zone'] ?? null,
                'placeholder' => __('common.all'),
            ])
        </div>
        <div>
            <label class="f">{{ __('lead.f_cat') }}</label>
            <select name="cat">
                <option value="">{{ __('lead.all_cats') }}</option>
                @foreach ($cats as $c)
                    <option value="{{ $c->category_raw }}" @selected(($filters['cat'] ?? '') === $c->category_raw)>
                        {{ $c->category_raw }} ({{ $c->n }})</option>
                @endforeach
            </select>
        </div>
        <button class="btn gold">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('erp.leads.route') }}">🧹 {{ __('lead.clear_filters') }}</a>
        <span class="badge b-blue" style="align-self:center">{{ $leads->count() }} 📍</span>
    </form>
</div>

{{-- ═══ بار التطبيق: مندوب + يوم + المحدد + Apply ═══ --}}
<div class="card" style="margin-bottom:12px">
    <form method="POST" action="{{ route('erp.leads.route.save') }}" id="rtForm"
          style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;
                 background:var(--blue-050,#E8F1FF);border:1px solid var(--royal-blue,#12399B);
                 border-radius:12px;padding:12px 14px;margin:0"
          onsubmit="return rtSubmit(this)">
        @csrf
        <div>
            <label class="f">{{ __('ops.rep') }}</label>
            <select name="rep_id" required>
                <option value="">—</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}">{{ $r->displayName() }} ({{ $r->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('lead.route_date') }}</label>
            <input type="date" name="date" value="{{ today()->toDateString() }}" required>
        </div>
        <div id="rtIds"></div>
        <span class="badge b-blue" style="font-size:12.5px">
            {{ __('lead.route_selected') }}: <b id="rtCount">0</b></span>
        <button class="btn sm" type="button" id="rtNearBtn">🧭 {{ __('lead.route_nearest') }}</button>
        <button class="btn sm" type="button" id="rtClearBtn">✕ {{ __('lead.route_unselect') }}</button>
        <button class="btn gold" type="submit">✅ Apply — {{ __('lead.route_apply') }}</button>
    </form>
    <div class="dash-hint" style="margin-top:8px">{{ __('lead.route_page_hint') }}</div>
    {{-- التسلسل المختار — شيبس بالترتيب و✕ لكل واحد --}}
    <div id="rtSeq" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"></div>
</div>

<div class="card">
    <div id="rtMap" style="height:66vh;border-radius:12px;border:1px solid var(--border)"></div>
</div>

@endsection

@section('scripts')
<script>
(function () {
    'use strict';

    var LEADS = {!! json_encode($leads, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
    var SEL_ERR = @js(__('lead.route_none_sel'));
    var UNASSIGNED = @js(__('lead.k_unassigned'));

    var sel = [];          // ids بالترتيب المختار
    var markers = {};      // id -> L.marker
    var byId = {};
    LEADS.forEach(function (l) { byId[l.id] = l; });

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    var map = L.map('rtMap', { scrollWheelZoom: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap',
    }).addTo(map);
    var lineLayer = L.layerGroup().addTo(map);

    function dotIcon() {
        return L.divIcon({
            className: '', iconSize: [16, 16], iconAnchor: [8, 8],
            html: '<div style="width:16px;height:16px;border-radius:50%;background:#9CA3AF;'
                + 'border:2.5px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35)"></div>',
        });
    }

    function numIcon(n) {
        return L.divIcon({
            className: '', iconSize: [30, 30], iconAnchor: [15, 15],
            html: '<div style="width:30px;height:30px;border-radius:50%;background:#12399B;'
                + 'border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);color:#fff;'
                + 'font:900 13px Cairo,Inter,sans-serif;display:flex;align-items:center;'
                + 'justify-content:center">' + n + '</div>',
        });
    }

    // ضغطة على النقطة = ضيفها آخر التسلسل، وضغطة تانية = شيلها
    function toggle(id) {
        var i = sel.indexOf(id);
        if (i === -1) sel.push(id);
        else sel.splice(i, 1);
        refresh();
    }

    function refresh() {
        // الأيقونات: المختار مرقّم والباقي نقطة رمادي
        LEADS.forEach(function (l) {
            var i = sel.indexOf(l.id);
            markers[l.id].setIcon(i === -1 ? dotIcon() : numIcon(i + 1));
            markers[l.id].setZIndexOffset(i === -1 ? 0 : 500);
        });

        // الخط
        lineLayer.clearLayers();
        var pts = sel.map(function (id) { return [byId[id].lat, byId[id].lng]; });
        if (pts.length > 1) {
            lineLayer.addLayer(L.polyline(pts, { color: '#12399B', weight: 3.5, dashArray: '8 8', opacity: .9 }));
        }

        // العداد + شيبس التسلسل
        document.getElementById('rtCount').textContent = sel.length;
        var seq = document.getElementById('rtSeq');
        seq.innerHTML = '';
        sel.forEach(function (id, i) {
            var l = byId[id];
            var chip = document.createElement('span');
            chip.className = 'badge b-blue';
            chip.style.cssText = 'font-size:11px;display:inline-flex;align-items:center;gap:5px;padding:5px 10px';
            chip.innerHTML = '<b>' + (i + 1) + '</b> ' + esc(l.name)
                + ' <span style="cursor:pointer;font-weight:900" data-id="' + id + '">✕</span>';
            chip.querySelector('[data-id]').addEventListener('click', function () { toggle(id); });
            seq.appendChild(chip);
        });
    }

    // النقط
    var bounds = [];
    LEADS.forEach(function (l) {
        var m = L.marker([l.lat, l.lng], { icon: dotIcon() });
        m.bindTooltip(
            '<b>' + esc(l.name) + '</b>'
            + (l.cat ? '<br>🏷 ' + esc(l.cat) : '')
            + (l.addr ? '<br>📍 ' + esc(l.addr) : '')
            + '<br>👤 ' + esc(l.rep || UNASSIGNED),
            { direction: 'top', offset: [0, -10] },
        );
        m.on('click', function () { toggle(l.id); });
        m.addTo(map);
        markers[l.id] = m;
        bounds.push([l.lat, l.lng]);
    });

    if (bounds.length === 1) map.setView(bounds[0], 14);
    else if (bounds.length) map.fitBounds(L.latLngBounds(bounds).pad(0.12));
    else map.setView([30.05, 31.4], 11);

    // «رتب بالأقرب» — أول مختار ثابت والباقي سلسلة أقرب-فالأقرب
    document.getElementById('rtNearBtn').addEventListener('click', function () {
        if (sel.length < 3) return;
        var chain = [sel[0]];
        var pool = sel.slice(1);
        while (pool.length) {
            var last = byId[chain[chain.length - 1]];
            var bi = 0, bd = Infinity;
            pool.forEach(function (id, i) {
                var l = byId[id];
                var dy = (last.lat - l.lat) * 111320;
                var dx = (last.lng - l.lng) * 111320 * Math.cos(last.lat * Math.PI / 180);
                var d = dx * dx + dy * dy;
                if (d < bd) { bd = d; bi = i; }
            });
            chain.push(pool.splice(bi, 1)[0]);
        }
        sel = chain;
        refresh();
    });

    document.getElementById('rtClearBtn').addEventListener('click', function () {
        sel = [];
        refresh();
    });

    // السبمت: التسلسل بيتحول hidden inputs ids[] بالترتيب
    window.rtSubmit = function (form) {
        if (!sel.length) { alert(SEL_ERR); return false; }
        var box = document.getElementById('rtIds');
        box.innerHTML = '';
        sel.forEach(function (id) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'ids[]';
            inp.value = id;
            box.appendChild(inp);
        });
        return true;
    };

    refresh();
})();
</script>
@endsection
