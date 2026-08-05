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
    @endphp

    @if ($wall->isEmpty())
        <div class="alert warn"><span>🗄️</span><span>{{ __('stock.no_locations') }}</span></div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px;align-items:stretch">
            @foreach ($wall as $loc)
                @php
                    $bls = $loc->batchLocations->where('qty', '>', 0);
                    $q = (int) $bls->sum('qty');
                    $skus = $bls->pluck('product_id')->unique()->count();
                    $exps = $bls->map(fn ($bl) => $bl->batch?->expires_on)->filter()->sort()->values();
                    $state = $q > 0 ? $loc->worstExpiryState() : null;
                    $edge = $bandEdge[$loc->life_band] ?? 'var(--border)';
                @endphp
                <div style="background:var(--card);border:1px solid var(--border);border-top:5px solid {{ $edge }};
                            border-radius:14px;padding:14px 12px;box-shadow:var(--shadow);text-align:center;
                            display:flex;flex-direction:column;gap:6px;{{ $q === 0 ? 'opacity:.75' : '' }}">
                    {{-- الكود كبير — ده اللي مكتوب على الحيطة فعلاً --}}
                    <div style="font-size:21px;font-weight:900;letter-spacing:.5px" dir="ltr">{{ $loc->code }}</div>
                    <div>
                        <span class="badge {{ $loc->bandBadge() }}" style="font-size:10px">{{ $loc->bandLabel() }}</span>
                        @if ($loc->is_pick_face)
                            <span class="badge b-purple" style="font-size:10px" title="{{ __('stock.pick_face') }}">★</span>
                        @endif
                    </div>

                    @if ($q > 0)
                        <div class="num" style="font-size:24px;font-weight:900;line-height:1">{{ $fmt($q) }}</div>
                        <div style="font-size:10.5px;color:var(--muted)">
                            {{ __('stock.units') }} • {{ $skus }} {{ __('stock.skus') }}
                        </div>
                        {{-- التواريخ اللي على البلوك: أقرب وأبعد انتهاء --}}
                        @if ($exps->isNotEmpty())
                            <div style="font-size:10px;color:var(--muted)" dir="ltr">
                                📅 {{ $exps->first()->format('Y-m-d') }}@if ($exps->count() > 1) → {{ $exps->last()->format('Y-m-d') }}@endif
                            </div>
                        @endif
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

<div class="card">
    <h3>📋 {{ __('stock.stock_by_location') }}
        <span class="side">{{ __('stock.total_on_shelves') }}: {{ $fmt($totalOnShelves) }}</span></h3>
    <div class="tablewrap" style="max-height:60vh;overflow-y:auto">
        <table>
            <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
            <tr>
                <th>{{ __('stock.location') }}</th>
                <th>{{ __('stock.item') }}</th>
                <th>{{ __('stock.batch_no') }}</th>
                <th>{{ __('stock.expires_on') }}</th>
                <th>{{ __('stock.expiry') }}</th>
                <th>{{ __('common.qty') }}</th>
                @if ($manager)<th></th>@endif
            </tr>
            </thead>
            @php $anyRow = false; @endphp
            @foreach ($locations as $loc)
                @foreach ($loc->batchLocations as $bl)
                    @continue($bl->qty <= 0)
                    @php $anyRow = true; @endphp
                    <tr>
                        <td>
                            <b style="font-size:14px">{{ $loc->code }}</b>
                            @if ($loc->life_band)
                                <span class="badge {{ $loc->bandBadge() }}" style="font-size:9.5px">{{ $loc->bandLabel() }}</span>
                            @endif
                            @if ($loc->is_pick_face)
                                <span class="badge b-purple">★ {{ __('stock.pick_face') }}</span>
                            @endif
                        </td>
                        <td>{{ $bl->product?->displayName() ?? $bl->batch?->product?->displayName() ?? '—' }}</td>
                        <td class="num">{{ $bl->batch?->batch_no ?? '—' }}</td>
                        <td class="num">{{ $bl->batch?->expires_on?->format('Y-m-d') ?? '—' }}</td>
                        <td>
                            @if ($bl->batch)
                                <span class="badge {{ $bl->batch->expiryClass() }}">{{ $bl->batch->expiryLabel() }}</span>
                            @else
                                <span class="badge b-gray">—</span>
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
