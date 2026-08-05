@extends('layouts.system')

{{--
    تقرير الصلاحية — النسخة المتطورة (2026-08-06):

    تاب لكل مخزن + «كل المخازن» ← KPI كروت كفلاتر بضغطة ← قسم
    «محتاجة تتنقل» (بلوكات FEFO: اللي قعد وعمره قل عن نطاق بلوكه،
    بزرار نقل بضغطة) ← جداول البكتات بصور المنتجات وبار عمر لكل
    باتش وهيدر ثابت ومحتوى متوسّط.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);

    $bucketMeta = [
        'expired' => ['label' => __('stock.expiry_expired'), 'badge' => 'b-red', 'val' => 'neg', 'icon' => '⛔', 'bar' => 'var(--red, #B00020)'],
        'danger' => ['label' => __('stock.expiry_danger'), 'badge' => 'b-red', 'val' => 'neg', 'icon' => '🔴', 'bar' => 'var(--red, #B00020)'],
        'warn' => ['label' => __('stock.expiry_warn'), 'badge' => 'b-orange', 'val' => 'mid', 'icon' => '🟠', 'bar' => 'var(--orange, #B86E00)'],
        'ok' => ['label' => __('stock.expiry_ok'), 'badge' => 'b-green', 'val' => 'pos', 'icon' => '🟢', 'bar' => 'var(--green, #1B7A3D)'],
    ];

    // بار العمر: النسبة المتبقية من (الإنتاج ← الانتهاء)، ومن غير
    // تاريخ إنتاج بنفترض عمر سنة — عرض بس مش حساب
    $lifePct = function ($b) {
        if ($b->expires_on === null) { return null; }
        $total = $b->produced_on
            ? max((int) $b->produced_on->diffInDays($b->expires_on), 1)
            : 365;
        return max(0, min(100, (int) round($b->daysLeft() / $total * 100)));
    };

    $whQuery = fn ($w) => ['warehouse' => $w];
    $canWork = auth()->user()->canWorkWarehouse();
@endphp

@section('title', __('stock.expiry_report'))

@section('actions')
    <a class="btn" href="{{ route('wh.index', $warehouse ? ['warehouse' => $warehouse->id] : []) }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    <a class="btn" href="{{ route('wh.locations', $warehouse ? ['warehouse' => $warehouse->id] : []) }}">🗄️ {{ __('stock.shelf_map') }}</a>
@endsection

@section('content')

@if ($warehouses->count() > 1)
    <div class="searchbar">
        <span style="font-size:11.5px;font-weight:800;color:var(--muted)">{{ __('stock.warehouses') }}</span>
        <a class="btn {{ $all ? 'gold' : '' }}" href="{{ route('wh.expiry', ['warehouse' => 'all']) }}">🌐 {{ __('stock.all_warehouses') }}</a>
        @foreach ($warehouses as $w)
            <a class="btn {{ ! $all && $warehouse && $w->id === $warehouse->id ? 'gold' : '' }}"
               href="{{ route('wh.expiry', ['warehouse' => $w->id]) }}">{{ $w->displayName() }}</a>
        @endforeach
    </div>
@endif

<div class="alert info" style="margin-bottom:14px">
    <span>⏳</span>
    <span>{{ __('stock.fefo_note') }} {{ __('stock.pick_face_hint') }}</span>
</div>

{{-- ═══ الكروت فلاتر بضغطة — واضغط تاني يرجع الكل ═══ --}}
<div class="kpis">
    @foreach ($bucketMeta as $key => $meta)
        @php
            $bucket = $buckets[$key] ?? collect();
            $active = $bucketFilter === $key;
            $link = route('wh.expiry', ($all ? ['warehouse' => 'all'] : ($warehouse ? ['warehouse' => $warehouse->id] : []))
                + ($active ? [] : ['bucket' => $key]));
        @endphp
        <a class="kpi" href="{{ $link }}"
           style="text-decoration:none;color:inherit;{{ $active ? 'outline:2px solid var(--royal-blue)' : '' }}">
            <div class="lbl">{{ $meta['icon'] }} {{ $meta['label'] }}</div>
            <div class="val {{ $meta['val'] }}">{{ $fmt($bucket->sum('qty_remaining')) }}</div>
            <div class="sub2">{{ __('stock.units') }} • {{ __('stock.batch_countable', ['count' => $bucket->count()]) }}</div>
        </a>
    @endforeach
</div>

{{-- ═══ بلوكات FEFO: محتاجة تتنقل لبلوك أقل ═══ --}}
@if ($relocations !== [])
    <div class="card">
        <h3>🔀 {{ __('stock.relocate_needed') }}
            <span class="side">{{ __('stock.relocate_hint') }}</span>
            <span class="badge b-orange" style="margin-inline-start:auto">{{ count($relocations) }}</span>
        </h3>
        <div class="tablewrap exp-tbl">
            <table>
                <tr>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    @if ($all)<th>{{ __('stock.warehouse') }}</th>@endif
                    <th>{{ __('stock.life_left') }}</th>
                    <th>{{ __('stock.location') }}</th>
                    <th>{{ __('stock.suggested_block') }}</th>
                    <th class="num">{{ __('common.qty') }}</th>
                    @if ($canWork)<th></th>@endif
                </tr>
                @foreach ($relocations as $r)
                    @php $bl = $r['bl']; $b = $r['batch']; @endphp
                    <tr>
                        <td style="text-align:start"><b>{{ $b->product?->displayName() ?? '—' }}</b></td>
                        <td class="num">{{ $b->batch_no }}</td>
                        @if ($all)<td class="s">{{ $b->warehouse?->displayName() ?? '—' }}</td>@endif
                        <td class="num">{{ max($b->daysLeft(), 0) }} {{ __('stock.day_unit') }}</td>
                        <td>
                            <span class="badge {{ $bl->location?->bandBadge() }}">{{ $bl->location?->code }} — {{ $bl->location?->bandLabel() }}</span>
                        </td>
                        <td>
                            @if ($r['target'])
                                <span class="badge {{ $r['target']->bandBadge() }}">{{ $r['target']->code }} — {{ $r['target']->bandLabel() }}</span>
                            @else
                                <span class="badge b-gray">—</span>
                            @endif
                        </td>
                        <td class="num"><b>{{ $fmt($bl->qty) }}</b></td>
                        @if ($canWork)
                            <td>
                                @if ($r['target'])
                                    <form method="POST" action="{{ route('wh.move', $bl) }}" style="display:inline">
                                        @csrf
                                        <input type="hidden" name="location_code" value="{{ $r['target']->code }}">
                                        <input type="hidden" name="qty" value="{{ (int) $bl->qty }}">
                                        <button class="btn sm gold" type="submit">🔀 {{ __('stock.relocate_to', ['code' => $r['target']->code]) }}</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@endif

@foreach ($bucketMeta as $key => $meta)
    @continue($bucketFilter && $bucketFilter !== $key)
    @php $bucket = $buckets[$key] ?? collect(); @endphp
    @if ($bucket->isNotEmpty())
        <div class="card">
            <h3>{{ $meta['icon'] }} {{ $meta['label'] }}
                <span class="side">{{ $fmt($bucket->sum('qty_remaining')) }} {{ __('stock.units') }}</span></h3>
            <div class="tablewrap exp-tbl" style="max-height:64vh;overflow-y:auto">
                <table>
                    <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
                        <tr>
                            <th style="text-align:start">{{ __('stock.item') }}</th>
                            @if ($all)<th>{{ __('stock.warehouse') }}</th>@endif
                            <th>{{ __('stock.batch_no') }}</th>
                            <th>{{ __('stock.expires_on') }}</th>
                            <th style="width:190px">{{ __('stock.life_left') }}</th>
                            <th class="num">{{ __('common.qty') }}</th>
                            <th>{{ __('stock.locations') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($bucket as $b)
                        @php
                            $shelved = $b->locations->where('qty', '>', 0);
                            $unshelved = (int) $b->qty_remaining - (int) $shelved->sum('qty');
                            $pct = $lifePct($b);
                        @endphp
                        <tr>
                            {{-- الصورة جوه خانة الصنف — نفس نمط باقي السيستم --}}
                            <td style="text-align:start">
                                <div style="display:flex;gap:10px;align-items:center">
                                    @if ($b->product?->imageSrc())
                                        <img src="{{ $b->product->imageSrc() }}"
                                             style="width:52px;height:52px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#fff;flex-shrink:0">
                                    @else
                                        <div style="width:52px;height:52px;border-radius:10px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>
                                    @endif
                                    <div>
                                        <b style="font-size:12.5px">{{ $b->product?->displayName() ?? '—' }}</b>
                                        @if ($b->product)
                                            <div style="font-size:10.5px;color:var(--muted)">{{ $b->product->code }} • {{ $b->product->unitLabel() }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            @if ($all)<td class="s">{{ $b->warehouse?->displayName() ?? '—' }}</td>@endif
                            <td class="num"><b>{{ $b->batch_no }}</b></td>
                            <td class="num">{{ $b->expires_on?->format('Y-m-d') ?? '—' }}</td>
                            {{-- بار العمر: النسبة الفاضلة من عمر الباتش --}}
                            <td>
                                @if ($pct === null)
                                    <span class="badge b-gray">—</span>
                                @else
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <div style="flex:1;height:9px;border-radius:6px;background:var(--card2, #eee);overflow:hidden;border:1px solid var(--border)">
                                            <div style="height:100%;width:{{ $pct }}%;background:{{ $meta['bar'] }};border-radius:6px"></div>
                                        </div>
                                        <span style="font-size:10.5px;font-weight:800;white-space:nowrap" dir="ltr">{{ $pct }}%</span>
                                    </div>
                                    <div style="font-size:10px;color:var(--muted);margin-top:2px">{{ $b->expiryLabel() }}</div>
                                @endif
                            </td>
                            <td class="num"><b>{{ $fmt($b->qty_remaining) }}</b></td>
                            <td>
                                {{-- الأرفف تفصيلة جوه الباتش — واللي لسه على الأرض باين صريح --}}
                                @foreach ($shelved as $bl)
                                    <span class="badge {{ $bl->location?->life_band ? $bl->location->bandBadge() : 'b-blue' }}" style="font-size:10.5px">
                                        {{ $bl->location?->code ?? '—' }} × {{ $fmt($bl->qty) }}
                                        @if ($bl->location?->is_pick_face) ★ @endif
                                    </span>
                                @endforeach
                                @if ($unshelved > 0)
                                    <span class="badge b-orange" style="font-size:10.5px">
                                        {{ __('stock.unshelved') }} × {{ $fmt($unshelved) }}
                                    </span>
                                @endif
                                @if ($shelved->isEmpty() && $unshelved <= 0)
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endforeach

@if ($rows->isEmpty())
    <div class="card">
        <div class="alert good"><span>✅</span><span>{{ __('common.no_results') }}</span></div>
    </div>
@endif

@endsection

@section('scripts')
<style>
/* المحتوى متوسّط ومتظبط — والصنف بس على البداية عشان الصورة والاسم */
.exp-tbl th, .exp-tbl td { text-align: center; vertical-align: middle; }
</style>
@endsection
