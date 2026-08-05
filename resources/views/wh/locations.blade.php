@extends('layouts.system')

@section('title', __('stock.locations'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **أمين المخزن لازم يشوف الأزرار دي — دي شغله.** كانت
    // `isManager()` وهو مش منهم، فالراوتس اتديتله والأزرار اتخبّت
    // عنه: مخزن للقراية بس.
    $manager = auth()->user()->canWorkWarehouse();

    // ألوان حالة الصلاحية على حافة كارت الرف
    $stateColor = [
        'ok' => 'var(--green)',
        'warn' => 'var(--orange)',
        'danger' => 'var(--red)',
        'expired' => 'var(--red)',
    ];
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
    <h3>🗄️ {{ __('stock.shelf_map') }} <span class="side">{{ __('stock.pick_face_hint') }}</span></h3>

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

    {{-- ═══ الحائط (2026-08-06): كل البلوكات جنب بعض في صف واحد —
         زي ما انت واقف قدام حيطة المخزن. الترتيب من الأقرب انتهاءً
         (شهر ← 3 شهور ← 6 شهور ← سنة) وبعدين الأرفف الحرة. ═══ --}}
    @php
        $bandOrder = ['month' => 0, 'quarter' => 1, 'half' => 2, 'year' => 3];
        $wall = $locations->sortBy([
            fn ($a, $b) => ($bandOrder[$a->life_band] ?? 9) <=> ($bandOrder[$b->life_band] ?? 9),
            fn ($a, $b) => strcmp($a->code, $b->code),
        ])->values();

        // لون حافة كل نطاق — نفس ألوان الشارات
        $bandEdge = [
            'month' => 'var(--red, #B00020)',
            'quarter' => 'var(--orange, #B86E00)',
            'half' => 'var(--royal-blue, #12399B)',
            'year' => 'var(--green, #1B7A3D)',
        ];

        // نطاق التواريخ اللي كل بلوك بيستقبله **النهارده** — من حدود LifeBands
        $bandWindow = function (?string $band) {
            $d = fn ($days) => now()->addDays($days)->format('Y-m-d');
            return match ($band) {
                'month' => __('stock.accepts_until', ['date' => $d(30)]),
                'quarter' => __('stock.accepts_between', ['from' => $d(31), 'to' => $d(90)]),
                'half' => __('stock.accepts_between', ['from' => $d(91), 'to' => $d(180)]),
                'year' => __('stock.accepts_after', ['date' => $d(180)]),
                default => __('stock.band_desc_free'),
            };
        };
        $bandDesc = fn (?string $band) => __('stock.band_desc_'.($band ?: 'free'));

        // مسمى عائلة الصنف — نفس مصدر النظرة العامة
        $famLabel = fn ($f) => $f
            ? (\Illuminate\Support\Facades\Lang::has('enums.family.'.$f) ? __('enums.family.'.$f) : (\App\Models\Product::FAMILIES[$f] ?? $f))
            : '—';

        $grandQty = max($totalOnShelves, 1);
    @endphp

    @if ($wall->isEmpty())
        <div class="alert warn"><span>🗄️</span><span>{{ __('stock.no_locations') }}</span></div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:12px;align-items:stretch">
            @foreach ($wall as $loc)
                @php
                    $bls = $loc->batchLocations->where('qty', '>', 0);
                    $q = (int) $bls->sum('qty');
                    $skus = $bls->pluck('product_id')->unique()->count();
                    $exps = $bls->map(fn ($bl) => $bl->batch?->expires_on)->filter()->sort()->values();
                    $state = $q > 0 ? $loc->worstExpiryState() : null;
                    $edge = $bandEdge[$loc->life_band] ?? 'var(--border)';
                    // العائلات اللي على البلوك: عدد الأصناف + الكمية لكل عائلة
                    $fams = $bls->groupBy(fn ($bl) => ($bl->product ?? $bl->batch?->product)?->family)
                        ->map(fn ($g) => [
                            'skus' => $g->pluck('product_id')->unique()->count(),
                            'qty' => (int) $g->sum('qty'),
                        ])->sortByDesc('qty');
                @endphp
                <div style="background:var(--card);border:1px solid var(--border);border-top:5px solid {{ $edge }};
                            border-radius:14px;padding:14px 12px;box-shadow:var(--shadow);text-align:center;
                            display:flex;flex-direction:column;gap:6px;{{ $q === 0 ? 'opacity:.78' : '' }}">
                    {{-- الكود كبير — ده اللي مكتوب على الحيطة فعلاً --}}
                    <div style="font-size:21px;font-weight:900;letter-spacing:.5px" dir="ltr">{{ $loc->code }}</div>
                    <div>
                        <span class="badge {{ $loc->bandBadge() }}" style="font-size:10px">{{ $loc->bandLabel() }}</span>
                        @if ($loc->is_pick_face)
                            <span class="badge b-purple" style="font-size:10px" title="{{ __('stock.pick_face') }}">★</span>
                        @endif
                    </div>
                    {{-- الوصف بالعربي + نطاق التواريخ اللي بيستقبله --}}
                    <div style="font-size:10px;color:var(--muted);line-height:1.6">
                        {{ $bandDesc($loc->life_band) }}<br>
                        <span dir="ltr" style="font-weight:700">📅 {{ $bandWindow($loc->life_band) }}</span>
                    </div>

                    @if ($q > 0)
                        {{-- الرقم الأساسي كبير وفي النص --}}
                        <div class="num" style="font-size:30px;font-weight:900;line-height:1;margin:4px 0 0">{{ $fmt($q) }}</div>
                        <div style="font-size:10.5px;color:var(--muted)">
                            {{ __('stock.units') }} • {{ $skus }} {{ __('stock.skus') }}
                        </div>
                        {{-- التواريخ الموجودة فعلاً على البلوك --}}
                        @if ($exps->isNotEmpty())
                            <div style="font-size:10px;color:var(--muted)" dir="ltr">
                                {{ $exps->first()->format('Y-m-d') }}@if ($exps->count() > 1) → {{ $exps->last()->format('Y-m-d') }}@endif
                            </div>
                        @endif
                        {{-- العائلات: عدد المنتجات والكمية لكل واحدة --}}
                        <div style="text-align:start;font-size:10.5px;border-top:1px dashed var(--border);padding-top:6px;margin-top:2px">
                            @foreach ($fams->take(4) as $famKey => $fam)
                                <div style="display:flex;justify-content:space-between;gap:6px;line-height:1.9">
                                    <span>{{ $famLabel($famKey) }}</span>
                                    <span class="num" style="color:var(--muted)">{{ $fam['skus'] }} × <b style="color:var(--ink)">{{ $fmt($fam['qty']) }}</b></span>
                                </div>
                            @endforeach
                            @if ($fams->count() > 4)
                                <div style="color:var(--muted)">+{{ $fams->count() - 4 }}…</div>
                            @endif
                        </div>
                        <div style="margin-top:auto">
                            <span class="badge {{ $state === 'ok' ? 'b-green' : ($state === 'warn' ? 'b-orange' : 'b-red') }}">
                                {{ $stateLabel[$state] ?? $state }}
                            </span>
                        </div>
                    @else
                        <div style="font-size:13px;color:var(--muted);margin-top:auto;margin-bottom:auto;padding:10px 0">
                            {{ __('stock.empty_shelf') }}
                        </div>
                    @endif

                    @if ($loc->capacity)
                        <div style="font-size:9.5px;color:var(--muted)">
                            {{ __('stock.free_capacity') }} {{ $fmt($loc->freeCapacity()) }}/{{ $fmt($loc->capacity) }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- ═══ ملخص البلوكات — الجدول الشيك: نظرة واحدة تعرف انت فين ═══ --}}
@if ($wall->isNotEmpty())
<div class="card">
    <h3>👁️ {{ __('stock.block_summary') }}
        <span class="side">{{ __('stock.block_summary_hint') }}</span></h3>
    <div class="tablewrap loc-tbl">
        <table>
            <tr>
                <th>{{ __('stock.location') }}</th>
                <th>{{ __('stock.life_band') }}</th>
                <th>{{ __('stock.expires_on') }}</th>
                <th class="num">{{ __('stock.skus') }}</th>
                <th class="num">{{ __('common.qty') }}</th>
                <th style="width:200px">{{ __('stock.stock_share') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>
            @foreach ($wall as $loc)
                @php
                    $bls = $loc->batchLocations->where('qty', '>', 0);
                    $q = (int) $bls->sum('qty');
                    $share = (int) round($q / $grandQty * 100);
                    $state = $q > 0 ? $loc->worstExpiryState() : null;
                    $edge = $bandEdge[$loc->life_band] ?? 'var(--muted)';
                @endphp
                <tr>
                    <td><b style="font-size:14px" dir="ltr">{{ $loc->code }}</b>@if ($loc->is_pick_face) ★@endif</td>
                    <td><span class="badge {{ $loc->bandBadge() }}">{{ $loc->bandLabel() }}</span></td>
                    <td style="font-size:11px" dir="ltr">{{ $bandWindow($loc->life_band) }}</td>
                    <td class="num">{{ $bls->pluck('product_id')->unique()->count() ?: '—' }}</td>
                    <td class="num"><b>{{ $q ? $fmt($q) : '—' }}</b></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;height:9px;border-radius:6px;background:var(--card2, #eee);overflow:hidden;border:1px solid var(--border)">
                                <div style="height:100%;width:{{ $share }}%;background:{{ $edge }};border-radius:6px"></div>
                            </div>
                            <span style="font-size:10.5px;font-weight:800" dir="ltr">{{ $share }}%</span>
                        </div>
                    </td>
                    <td>
                        @if ($q > 0)
                            <span class="badge {{ $state === 'ok' ? 'b-green' : ($state === 'warn' ? 'b-orange' : 'b-red') }}">{{ $stateLabel[$state] ?? $state }}</span>
                        @else
                            <span class="badge b-gray">{{ __('stock.empty_shelf') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

<div class="card">
    <h3>📋 {{ __('stock.stock_by_location') }}
        <span class="side">{{ __('stock.total_on_shelves') }}: {{ $fmt($totalOnShelves) }}</span></h3>

    {{-- ═══ فلاتر لايف فوق الجدول — بحث + بلوك + حالة (2026-08-06) ═══ --}}
    <div class="searchbar" style="margin-bottom:10px">
        <input type="search" id="slFilter" placeholder="🔍 {{ __('stock.search_stock_rows') }}"
               oninput="slApply()" style="flex:1;min-width:220px">
        <select id="slLoc" onchange="slApply()" style="min-width:130px">
            <option value="">{{ __('stock.location') }}: {{ __('common.all') }}</option>
            @foreach ($wall as $loc)
                <option value="{{ $loc->code }}">{{ $loc->code }} — {{ $loc->bandLabel() }}</option>
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
            <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
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
                        <td>
                            <b style="font-size:13px" dir="ltr">{{ $loc->code }}</b>
                            @if ($loc->life_band)
                                <div><span class="badge {{ $loc->bandBadge() }}" style="font-size:9px">{{ $loc->bandLabel() }}</span></div>
                            @endif
                        </td>
                        {{-- الصورة جوه خانة الصنف — نفس نمط باقي السيستم --}}
                        <td style="text-align:start">
                            <div style="display:flex;gap:10px;align-items:center">
                                @if ($p?->imageSrc())
                                    <img src="{{ $p->imageSrc() }}"
                                         style="width:48px;height:48px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#fff;flex-shrink:0">
                                @else
                                    <div style="width:48px;height:48px;border-radius:10px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>
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
                        {{-- بار العمر % — بالعين تعرف إيه اللي بيحصل --}}
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

<datalist id="whLocCodes">
    @foreach ($locations as $loc)
        <option value="{{ $loc->code }}">{{ $loc->life_band ? $loc->bandLabel() : ($loc->is_pick_face ? __('stock.pick_face') : __('stock.location')) }}</option>
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
                    <div class="alert info" style="margin-bottom:12px">
                        <span>🏷️</span><span>{{ __('stock.put_away_hint') }}</span>
                    </div>
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
                    {{-- بلوك FEFO — فاضي يعني رف حر بيقبل أي حاجة --}}
                    <label class="f">{{ __('stock.life_band') }}</label>
                    <select name="life_band" style="width:100%">
                        <option value="">{{ __('stock.band_free') }}</option>
                        @foreach (\App\Support\LifeBands::options() as $bk => $bLbl)
                            <option value="{{ $bk }}">{{ $bLbl }} ({{ \App\Support\LifeBands::PREFIX[$bk] }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="f">{{ __('common.notes') }}</label>
                    <textarea name="notes" rows="2" style="width:100%"></textarea>
                </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px">
                <input type="checkbox" name="is_pick_face" value="1"> ★ {{ __('stock.pick_face') }}
            </label>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">{{ __('stock.pick_face_hint') }}</div>
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
/* المحتوى متوسّط ومتظبط — والصنف على البداية عشان الصورة والاسم */
.loc-tbl th, .loc-tbl td { text-align: center; vertical-align: middle; }
</style>
<script>
/** فلاتر جدول المخزون بالأرفف — لايف من غير سيرفر */
function slApply() {
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
