@extends('layouts.system')

@section('title', __('stock.locations'))

{{--
    شاشة الأرفف (اتبنت من جديد ٦/٩/٢٠٢٦ — نظام الاستاندات A–J):

    ١. خريطة 2D فيجوال: كل استاند شكله استاند حقيقي — يافطة بحرفه،
       قوايم جانبية، ولوح لكل رف عليه كراتين مرسومة بحجم الكمية.
    ٢. الضغط على أي رف بيفتح بوب أب بالبضاعة اللي عليه: صنف/باتش/
       صلاحية/كمية + زرار نقل لكل سطر.
    ٣. كروت «الحائط» وملخص البلوكات القديمة اتشالت (طلب المالك) —
       التفاصيل كلها في البوب أب وجدول المخزون تحت.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **أمين المخزن لازم يشوف الأزرار دي — دي شغله.**
    $manager = auth()->user()->canWorkWarehouse();

    $stateLabel = [
        'ok' => __('stock.expiry_ok'),
        'warn' => __('stock.expiry_warn'),
        'danger' => __('stock.expiry_danger'),
        'expired' => __('stock.expiry_expired'),
    ];

    $totalOnShelves = 0;
    $occupied = 0;
    foreach ($locations as $l) {
        $q = (int) $l->batchLocations->sum('qty');
        $totalOnShelves += $q;
        if ($q > 0) { $occupied++; }
    }
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.index', $warehouse ? ['warehouse' => $warehouse->id] : []) }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    <a class="btn" href="{{ route('wh.expiry', $warehouse ? ['warehouse' => $warehouse->id] : []) }}">⏳ {{ __('stock.expiry_report') }}</a>
    @if ($manager && $warehouse)
        <button class="btn gold" onclick="openDlg('dlgNewLoc')">+ {{ __('stock.new_location') }}</button>
    @endif
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif
@if ($errors->any())
    <div class="alert" style="margin-bottom:12px"><span>⚠️</span><span>{{ $errors->first() }}</span></div>
@endif

@if ($warehouses->count() > 1)
    <div class="searchbar">
        <span style="font-size:11.5px;font-weight:800;color:var(--muted)">{{ __('stock.warehouses') }}</span>
        @foreach ($warehouses as $w)
            <a class="btn {{ $warehouse && $w->id === $warehouse->id ? 'gold' : '' }}"
               href="{{ route('wh.locations', ['warehouse' => $w->id]) }}">{{ $w->displayName() }}</a>
        @endforeach
    </div>
@endif

@if ($warehouse === null)
    <div class="card"><div class="alert warn">{{ __('stock.no_warehouse') }}</div></div>
@else

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('stock.shelf_count') }}</div>
        <div class="val">{{ $fmt($locations->count()) }}</div>
        <div class="sub2">{{ $warehouse->displayName() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.occupied') }}</div>
        <div class="val">{{ $fmt($occupied) }}</div>
        <div class="sub2">{{ __('stock.empty_shelf') }}: {{ $fmt(max($locations->count() - $occupied, 0)) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.total_on_shelves') }}</div>
        <div class="val pos">{{ $fmt($totalOnShelves) }}</div>
        <div class="sub2">{{ __('stock.units') }}</div>
    </div>
</div>

<div class="card">
    <h3>🗄️ {{ __('stock.shelf_map') }} <span class="side">{{ __('stock.map_hint') }}</span></h3>

    {{-- البحث بيضوّي على الأرفف المطابقة في الخريطة (سيرفر سايد) --}}
    <form class="searchbar" method="GET">
        <input type="hidden" name="warehouse" value="{{ $warehouse->id }}">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('stock.search_shelf') }}">
        <select name="state">
            <option value="">{{ __('stock.all_states') }}</option>
            @foreach ($stateLabel as $k => $lbl)
                <option value="{{ $k }}" @selected(($filters['state'] ?? '') === $k)>{{ $lbl }}</option>
            @endforeach
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('wh.locations', ['warehouse' => $warehouse->id]) }}">{{ __('common.clear') }}</a>
    </form>

    @php
        // الاستاندات بحرف واحد بس (A..J) — أي رف قديم تاني برة الخريطة
        $mapStands = $stands->filter(fn ($g, $k) => is_string($k) && preg_match('/^[A-Z]$/', (string) $k))
            ->sortKeys();
        $mapMax = 1;
        foreach ($mapStands as $g) {
            foreach ($g as $l) {
                $mapMax = max($mapMax, (int) $l->batchLocations->where('qty', '>', 0)->sum('qty'));
            }
        }
        // البحث نشط؟ الأرفف المطابقة (من الكوليكشن المفلترة) بتضوّي دهبي
        $searching = ($filters['q'] ?? '') !== '' || ($filters['state'] ?? '') !== '';
        $hits = $searching ? $locations->pluck('code')->flip() : collect();
    @endphp

    @if ($mapStands->isEmpty())
        <div class="alert warn"><span>🗄️</span><span>{{ __('stock.no_locations') }}</span></div>
    @else
        <div style="padding:8px 0 4px">
            {{-- الـ١٠ استاندات في عرض الصفحة — جريد بيتقسم بالتساوي --}}
            <div class="whfloor" dir="ltr"
                 style="grid-template-columns:repeat({{ max($mapStands->count(), 1) }}, minmax(0, 1fr))">
                @foreach ($mapStands as $standKey => $shelves)
                    <div class="rack">
                        <div class="rack-sign">{{ $standKey }}</div>
                        <div class="rack-frame">
                            @foreach ($shelves->sortByDesc('level') as $sh)
                                @php
                                    $bls = $sh->batchLocations->where('qty', '>', 0);
                                    $sq = (int) $bls->sum('qty');
                                    $st = $sq > 0 ? $sh->worstExpiryState() : null;
                                    $dot = ['warn' => '#B86E00', 'danger' => '#B00020', 'expired' => '#B00020'][$st ?? ''] ?? null;
                                    // صف كراتين واحد — لحد ١٠ بحجم الكمية النسبي
                                    $boxes = $sq > 0 ? max(1, (int) ceil($sq / $mapMax * 10)) : 0;
                                    // تلميح ⓘ: الكمية متفكّكة كرتونة/علبة/قطعة بمضاعفات كل صنف
                                    $tc = $tb = $tp = 0;
                                    foreach ($bls as $bl2) {
                                        $p2 = $bl2->product ?? $bl2->batch?->product;
                                        $q2 = (int) $bl2->qty;
                                        $upc = (int) ($p2?->units_per_case ?? 0);
                                        $bu = (int) ($p2?->box_units ?? 0);
                                        if ($upc > 0) { $tc += intdiv($q2, $upc); $q2 %= $upc; }
                                        if ($bu > 0) { $tb += intdiv($q2, $bu); $q2 %= $bu; }
                                        $tp += $q2;
                                    }
                                    $packTip = __('stock.map_pack', ['c' => $tc, 'b' => $tb, 'p' => $tp]);
                                    $cls = $searching ? (isset($hits[$sh->code]) ? ' hit' : ' dim') : '';
                                    $payload = json_encode([
                                        'code' => $sh->stand.$sh->level,
                                        'total' => $sq,
                                        'pack' => $sq > 0 ? $packTip : '',
                                        'rows' => $bls->map(fn ($bl) => [
                                            'p' => (($bl->product ?? $bl->batch?->product)?->displayName()) ?? '—',
                                            'c' => ($bl->product ?? $bl->batch?->product)?->code,
                                            'i' => ($bl->product ?? $bl->batch?->product)?->imageSrc(),
                                            'b' => $bl->batch?->batch_no,
                                            'e' => $bl->batch?->expires_on?->format('Y-m-d'),
                                            'q' => (int) $bl->qty,
                                            'id' => $bl->id,
                                        ])->values(),
                                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                                @endphp
                                <div class="shelf{{ $cls }}" onclick='shelfOpen({!! $payload !!})'>
                                    @if ($dot)
                                        <span class="sh-dot" style="background:{{ $dot }}"></span>
                                    @endif
                                    @if ($sq > 0)
                                        <span class="sh-info" title="{{ $packTip }}">i</span>
                                    @endif
                                    <div class="sh-boxes">
                                        @if ($boxes === 0)
                                            <span class="sh-empty">{{ __('stock.empty_shelf') }}</span>
                                        @else
                                            @for ($i = 0; $i < $boxes; $i++)
                                                <i class="cbx"></i>
                                            @endfor
                                        @endif
                                    </div>
                                    {{-- اللوح الأصفر: كود الرف شمال بالأسود والقطع يمين --}}
                                    <div class="sh-board">
                                        <span class="sh-bcode">{{ $sh->stand.$sh->level }}</span>
                                        <span class="sh-bqty num">{{ $sq > 0 ? $fmt($sq) : '' }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="rack-feet"><i></i><i></i></div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>

{{-- ═══ جدول المخزون بالأرفف — الليستة الكاملة بالنقل والصور ═══ --}}
<div class="card">
    <h3>📋 {{ __('stock.stock_by_location') }}
        <span class="side">{{ __('stock.total_on_shelves') }}: {{ $fmt($totalOnShelves) }}</span></h3>

    <div class="searchbar" style="margin-bottom:10px">
        <input type="search" id="slFilter" placeholder="🔍 {{ __('stock.search_stock_rows') }}"
               oninput="slApply()" style="flex:1;min-width:220px">
        <select id="slLoc" onchange="slApply()" style="min-width:130px">
            <option value="">{{ __('stock.location') }}: {{ __('common.all') }}</option>
            @foreach ($locations->sortBy('code') as $loc)
                <option value="{{ $loc->code }}">{{ $loc->code }}</option>
            @endforeach
        </select>
        <select id="slState" onchange="slApply()" style="min-width:130px">
            <option value="">{{ __('stock.all_states') }}</option>
            @foreach ($stateLabel as $k => $lbl)
                <option value="{{ $k }}">{{ $lbl }}</option>
            @endforeach
        </select>
        <span class="s" style="color:var(--muted)"><b id="slCount">0</b> {{ __('stock.rows_visible') }}</span>
    </div>

    <div class="tablewrap loc-tbl" style="max-height:62vh;overflow-y:auto">
        <table>
            <thead>
            <tr>
                <th>{{ __('stock.location') }}</th>
                <th style="text-align:start">{{ __('stock.item') }}</th>
                <th>{{ __('stock.batch_no') }}</th>
                <th>{{ __('stock.expires_on') }}</th>
                <th style="width:190px">{{ __('stock.life_left') }}</th>
                <th>{{ __('common.qty') }}</th>
                @if ($manager)<th></th>@endif
            </tr>
            </thead>
            <tbody>
            @php $anyRow = false; @endphp
            @foreach ($locations as $loc)
                @foreach ($loc->batchLocations as $bl)
                    @continue($bl->qty <= 0)
                    @php
                        $anyRow = true;
                        $p = $bl->product ?? $bl->batch?->product;
                        $b = $bl->batch;
                        // بار العمر: النسبة المتبقية من (الإنتاج ← الانتهاء)
                        $pct = null;
                        if ($b?->expires_on) {
                            $total = $b->produced_on ? max((int) $b->produced_on->diffInDays($b->expires_on), 1) : 365;
                            $pct = max(0, min(100, (int) round($b->daysLeft() / $total * 100)));
                        }
                        $st = $b?->expiryState() ?? 'ok';
                        $barColor = match ($st) {
                            'expired', 'danger' => 'var(--red, #B00020)',
                            'warn' => 'var(--orange, #B86E00)',
                            default => 'var(--green, #1B7A3D)',
                        };
                    @endphp
                    <tr class="sl-row"
                        data-q="{{ mb_strtolower(($p?->displayName() ?? '').' '.($p?->code ?? '').' '.($b?->batch_no ?? '').' '.$loc->code) }}"
                        data-loc="{{ $loc->code }}" data-state="{{ $b ? $st : '' }}">
                        <td><b style="font-size:13px" dir="ltr">{{ $loc->code }}</b></td>
                        <td style="text-align:start">
                            <div style="display:flex;gap:10px;align-items:center">
                                @if ($p?->imageSrc())
                                    <img src="{{ $p->imageSrc() }}"
                                         style="width:72px;height:72px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#fff;flex-shrink:0">
                                @else
                                    <div style="width:72px;height:72px;border-radius:10px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>
                                @endif
                                <div>
                                    <b style="font-size:12.5px">{{ $p?->displayName() ?? '—' }}</b>
                                    @if ($p)
                                        <div style="font-size:10px;color:var(--muted)">{{ $p->code }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="num">{{ $b?->batch_no ?? '—' }}</td>
                        <td class="num">{{ $b?->expires_on?->format('Y-m-d') ?? '—' }}</td>
                        <td>
                            @if ($pct === null)
                                <span class="badge b-gray">—</span>
                            @else
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div style="flex:1;height:9px;border-radius:6px;background:var(--card2, #eee);overflow:hidden;border:1px solid var(--border)">
                                        <div style="height:100%;width:{{ $pct }}%;background:{{ $barColor }};border-radius:6px"></div>
                                    </div>
                                    <span style="font-size:10.5px;font-weight:800;white-space:nowrap" dir="ltr">{{ $pct }}%</span>
                                </div>
                                <div style="font-size:9.5px;color:var(--muted);margin-top:2px">{{ $b->expiryLabel() }}</div>
                            @endif
                        </td>
                        <td class="num"><b>{{ $fmt($bl->qty) }}</b></td>
                        @if ($manager)
                            <td><button class="btn sm" type="button" onclick="openDlg('dlgMove{{ $bl->id }}')">
                                {{ __('stock.move_stock') }}
                            </button></td>
                        @endif
                    </tr>
                @endforeach
            @endforeach
            @if (! $anyRow)
                <tr><td colspan="{{ $manager ? 7 : 6 }}" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('common.no_results') }}
                </td></tr>
            @endif
            </tbody>
        </table>
    </div>
</div>

{{-- ═══ بوب أب محتوى الرف — بيتملى بالجافاسكريبت من بايلود الخريطة ═══ --}}
{{-- ⚠️ `wide` — العنصر الجواني في الليّاوت مقفول على 620px، ومن غيرها
     الدايالوج بيبقى أعرض من محتواه: مسافة بيضا + سكرول أفقي للجدول --}}
<dialog id="dlgShelf" class="wide">
    <div class="dlg">
        <h4 style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;
                         border-radius:10px;color:#111;font-weight:900;font-size:15px;
                         background:linear-gradient(to bottom,#ffe968 0 20%,#ffd400 20% 80%,#cfa400 80%);
                         box-shadow:0 2px 4px rgba(0,0,0,.2)" dir="ltr" id="shTitle"></span>
            <span class="badge b-blue" id="shTotal" style="font-size:12px"></span>
            <span class="badge b-gray" id="shPack" style="font-size:11px"></span>
        </h4>
        <div class="tablewrap sh-tbl" style="max-height:55vh;overflow-y:auto">
            <table>
                <thead>
                <tr>
                    <th style="text-align:start">{{ __('stock.item') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    <th>{{ __('stock.expires_on') }}</th>
                    <th>{{ __('common.qty') }}</th>
                    @if ($manager)<th></th>@endif
                </tr>
                </thead>
                <tbody id="shRows"></tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgShelf')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

<datalist id="whLocCodes">
    @foreach ($locations->sortBy('code') as $loc)
        <option value="{{ $loc->code }}"></option>
    @endforeach
</datalist>

@if ($manager)
    @foreach ($locations as $loc)
        @foreach ($loc->batchLocations as $bl)
            @continue($bl->qty <= 0)
            <dialog id="dlgMove{{ $bl->id }}">
                <form class="dlg" method="POST" action="{{ route('wh.move', $bl) }}">
                    @csrf
                    <h4>{{ __('stock.move_stock') }} — {{ $loc->code }}</h4>
                    <div class="frow">
                        <div>
                            <label class="f">{{ __('stock.move_to_shelf') }}</label>
                            <input type="text" name="location_code" list="whLocCodes" required autofocus
                                   autocomplete="off" maxlength="20" placeholder="A03"
                                   style="width:100%;text-transform:uppercase"
                                   oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                            <label class="f">{{ __('common.qty') }}</label>
                            <input type="number" name="qty" min="1" step="1" max="{{ (int) $bl->qty }}"
                                   value="{{ (int) $bl->qty }}" required style="width:100%">
                        </div>
                    </div>
                    <div style="font-size:11px;color:var(--muted)">
                        {{ $bl->product?->displayName() ?? '—' }} •
                        {{ __('stock.batch_no') }} {{ $bl->batch?->batch_no ?? '—' }} •
                        {{ __('stock.on_shelf') }} {{ $fmt($bl->qty) }}
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                        <button class="btn" type="button" onclick="closeDlg('dlgMove{{ $bl->id }}')">{{ __('common.cancel') }}</button>
                        <button class="btn gold" type="submit">{{ __('stock.move_stock') }}</button>
                    </div>
                </form>
            </dialog>
        @endforeach
    @endforeach

    <dialog id="dlgNewLoc">
        <form class="dlg" method="POST" action="{{ route('wh.locations.store') }}">
            @csrf
            <h4>{{ __('stock.new_location') }}</h4>
            <div class="frow">
                <div>
                    <label class="f">{{ __('stock.warehouse') }}</label>
                    <select name="warehouse_id" required style="width:100%">
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" @selected($w->id === $warehouse->id)>{{ $w->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="f">{{ __('stock.stand') }}</label>
                    <input type="text" name="stand" maxlength="5" required placeholder="A"
                           style="width:100%;text-transform:uppercase"
                           oninput="this.value = this.value.toUpperCase()">
                </div>
                <div>
                    <label class="f">{{ __('stock.level') }}</label>
                    <input type="number" name="level" min="1" max="99" step="1" value="1" required style="width:100%">
                </div>
                <div>
                    <label class="f">{{ __('stock.capacity') }} <span style="font-weight:400">({{ __('common.optional') }})</span></label>
                    <input type="number" name="capacity" min="1" step="1" style="width:100%">
                </div>
            </div>
            <div class="frow" style="margin-top:10px">
                <div>
                    <label class="f">{{ __('common.notes') }}</label>
                    <textarea name="notes" rows="2" style="width:100%"></textarea>
                </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px">
                <input type="checkbox" name="is_pick_face" value="1" checked> ★ {{ __('stock.pick_face') }}
            </label>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button class="btn" type="button" onclick="closeDlg('dlgNewLoc')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('common.save') }}</button>
            </div>
        </form>
    </dialog>
@endif

@endif

@endsection

@section('scripts')
<style>
.loc-tbl th, .loc-tbl td { text-align: center; vertical-align: middle; }

/* جدول البوب أب — كله باين من غير سكرول أفقي */
.sh-tbl { overflow-x: hidden; }
.sh-tbl table { width: 100%; min-width: 0; }
.sh-tbl th, .sh-tbl td { text-align: center; vertical-align: middle; }
.sh-tbl td { padding: 9px 8px; }
.sh-tbl td b { white-space: normal; word-break: break-word; }
.sh-tbl tbody tr { border-bottom: 1px solid var(--border); }
.sh-tbl tbody tr:hover { background: rgba(18,57,155,.04); }

/* ═══ الاستاندات الفيجوال — تقليد الاستاند الحقيقي (٦/٩):
       عواميد زرقا + ألواح صفرا سميكة عليها الكود والكمية ═══ */
.whfloor { display: grid; gap: 14px; align-items: end; padding: 12px 4px 4px;
           background: linear-gradient(to top, rgba(120,120,130,.14), transparent 60%); }
.rack { min-width: 0; display: flex; flex-direction: column; }
.rack-sign { text-align: center; font-weight: 900; font-size: 18px; color: #fff;
             border-radius: 10px 10px 0 0; padding: 6px 0; letter-spacing: 1px;
             background: linear-gradient(135deg, var(--royal-blue), var(--purple-heart));
             box-shadow: 0 2px 6px rgba(18,57,155,.35); }
/* العواميد الزرقا — نقط تعليق متباعدة وهادية */
.rack-frame { position: relative; padding: 10px 16px 2px; background: transparent; }
.rack-frame::before, .rack-frame::after { content: ''; position: absolute; top: 0; bottom: -2px;
    width: 11px; border-radius: 2px; z-index: 2;
    background-image:
        radial-gradient(circle 1.4px at 50% 10px, rgba(255,255,255,.6) 1.3px, transparent 1.8px),
        linear-gradient(90deg, #2a55cf 0 3px, #1d3fa8 3px 8px, #142c78 8px 100%);
    background-size: 11px 34px, 100% 100%;
    box-shadow: 2px 2px 4px rgba(0,0,0,.25); }
.rack-frame::before { left: 0; }
.rack-frame::after { right: 0; }
.rack-feet { display: flex; justify-content: space-between; padding: 0 1px; }
.rack-feet i { width: 17px; height: 11px; background: linear-gradient(90deg, #1d3fa8, #142c78);
               border-radius: 0 0 4px 4px; box-shadow: 0 3px 3px rgba(0,0,0,.25); }

.shelf { position: relative; cursor: pointer; padding: 3px 2px 0; margin-bottom: 10px;
         border-radius: 6px 6px 0 0; transition: transform .12s, box-shadow .12s; }
.shelf:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(18,57,155,.22);
               background: rgba(255,212,0,.07); }
.shelf.hit { outline: 3px solid #FFD400; outline-offset: 1px; background: rgba(255,212,0,.14); }
.shelf.dim { opacity: .3; }
.sh-dot { position: absolute; top: 4px; left: 4px; width: 8px; height: 8px; border-radius: 50%; z-index: 3; }
/* أيقونة ⓘ — هوفر بيقول الرف فيه كام كرتونة وعلبة وقطعة */
.sh-info { position: absolute; top: 3px; right: 4px; z-index: 3; width: 14px; height: 14px;
           border-radius: 50%; background: var(--royal-blue); color: #fff; font-size: 9.5px;
           font-weight: 900; font-style: normal; display: flex; align-items: center;
           justify-content: center; cursor: help; opacity: .85; }
/* صف كراتين واحد — نفس الارتفاع في كل الأرفف */
.sh-boxes { display: flex; gap: 3px; align-items: flex-end; height: 26px;
            overflow: hidden; padding: 2px 3px 0; }
.sh-empty { font-size: 9px; color: var(--muted); opacity: .7; width: 100%; text-align: center;
            align-self: center; }
/* الكرتونة — جسم كرتون بسطح أفتح وشريط لاصق (النسخة الأولى) */
.cbx { flex: 0 1 17px; min-width: 9px; height: 15px; border-radius: 2.5px;
       background:
           linear-gradient(90deg, transparent 0 38%, rgba(140,95,45,.55) 38% 62%, transparent 62%),
           linear-gradient(to bottom, #e6b273 0 5px, #cf9146 5px 100%);
       border: 1px solid #a9743a; box-shadow: inset 0 -2px 0 rgba(0,0,0,.1); }
/* اللوح الأصفر السميك — الكود بالأسود شمال والكمية يمين */
.sh-board { height: 21px; margin: 0 -14px; position: relative; z-index: 1; border-radius: 3px;
            display: flex; align-items: center; justify-content: space-between; padding: 0 18px;
            background: linear-gradient(to bottom, #ffe968 0 4px, #ffd400 4px 15px, #cfa400 15px 18px, #8a6d00 18px 100%);
            box-shadow: 0 3px 5px rgba(0,0,0,.28); }
.sh-bcode { font-size: 11.5px; font-weight: 900; color: #111; letter-spacing: .5px; line-height: 1; }
.sh-bqty { font-size: 12.5px; font-weight: 900; color: #111; line-height: 1; }
</style>
<script>
var SH_MOVE = @js($manager ?? false);
var SH_MOVE_LBL = @js(__('stock.move_stock'));
var SH_UNITS = @js(__('stock.units'));
var SH_EMPTY = @js(__('stock.shelf_pop_empty'));

/** بوب أب محتوى الرف — البايلود جاي من خانة الخريطة نفسها */
function shelfOpen(d) {
    document.getElementById('shTitle').textContent = d.code;
    document.getElementById('shTotal').textContent = Number(d.total).toLocaleString('en') + ' ' + SH_UNITS;
    var pk = document.getElementById('shPack');
    pk.textContent = d.pack || '';
    pk.style.display = d.pack ? '' : 'none';

    var tb = document.getElementById('shRows');
    tb.textContent = '';

    if (!d.rows || d.rows.length === 0) {
        var tr0 = document.createElement('tr');
        var td0 = document.createElement('td');
        td0.colSpan = SH_MOVE ? 5 : 4;
        td0.style.cssText = 'text-align:center;color:var(--muted);padding:22px';
        td0.textContent = SH_EMPTY;
        tr0.appendChild(td0);
        tb.appendChild(tr0);
    }

    (d.rows || []).forEach(function (r) {
        var tr = document.createElement('tr');

        var tdP = document.createElement('td');
        tdP.style.textAlign = 'start';
        var wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;gap:10px;align-items:center';

        if (r.i) {
            var im = document.createElement('img');
            im.src = r.i;
            im.style.cssText = 'width:58px;height:58px;object-fit:contain;border-radius:9px;'
                + 'border:1px solid var(--border);background:#fff;flex-shrink:0';
            wrap.appendChild(im);
        } else {
            var ph = document.createElement('div');
            ph.style.cssText = 'width:58px;height:58px;border-radius:9px;border:1px dashed var(--border);'
                + 'display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0';
            ph.textContent = '📦';
            wrap.appendChild(ph);
        }

        var nm = document.createElement('div');
        var bP = document.createElement('b');
        bP.style.fontSize = '12.5px';
        bP.textContent = r.p || '—';
        nm.appendChild(bP);
        if (r.c) {
            var dv = document.createElement('div');
            dv.style.cssText = 'font-size:10px;color:var(--muted)';
            dv.textContent = r.c;
            nm.appendChild(dv);
        }
        wrap.appendChild(nm);
        tdP.appendChild(wrap);
        tr.appendChild(tdP);

        ['b', 'e'].forEach(function (k) {
            var td = document.createElement('td');
            td.className = 'num';
            td.textContent = r[k] || '—';
            tr.appendChild(td);
        });

        var tdQ = document.createElement('td');
        tdQ.className = 'num';
        var bQ = document.createElement('b');
        bQ.textContent = Number(r.q).toLocaleString('en');
        tdQ.appendChild(bQ);
        tr.appendChild(tdQ);

        if (SH_MOVE) {
            var tdM = document.createElement('td');
            var btn = document.createElement('button');
            btn.className = 'btn sm';
            btn.type = 'button';
            btn.textContent = SH_MOVE_LBL;
            btn.onclick = function () { closeDlg('dlgShelf'); openDlg('dlgMove' + r.id); };
            tdM.appendChild(btn);
            tr.appendChild(tdM);
        }

        tb.appendChild(tr);
    });

    openDlg('dlgShelf');
}

/** فلاتر جدول المخزون بالأرفف — لايف من غير سيرفر */
function slApply() {
    // الشاشة ممكن تترندر من غير مخزن — مفيش جدول ساعتها
    if (! document.getElementById('slFilter')) { return; }

    const q = (document.getElementById('slFilter').value || '').trim().toLowerCase();
    const loc = document.getElementById('slLoc').value;
    const state = document.getElementById('slState').value;
    let visible = 0;

    document.querySelectorAll('tr.sl-row').forEach(function (tr) {
        const show = (!q || (tr.dataset.q || '').includes(q))
            && (!loc || tr.dataset.loc === loc)
            && (!state || tr.dataset.state === state);
        tr.hidden = !show;
        if (show) visible++;
    });

    document.getElementById('slCount').textContent = visible;
}

slApply();
</script>
@endsection
