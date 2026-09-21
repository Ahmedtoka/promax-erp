@extends('layouts.system')

@section('title', __('ops.merch_visits'))

{{--
    «زيارات الرفوف» — المصدرين مع بعض (١٥ أغسطس ٢٠٢٦).

    قبل كده الشاشة كانت بتعرض زيارات البروموتر بس
    (`merch_visits.photo_before/after`)، وصور الرف اللي المندوب
    بياخدها جوه زيارته العادية (`visit_photos`) ماكانش ليها عارض —
    ده بلاغ المالك بالحرف. دلوقتي الاتنين في ليستة واحدة ببادج
    مصدر، ونفس عرض «قبل/بعد».

    الصف بتاع المندوب مالوش أعمدة «اتنقل للرف»/«ناقص» — دي بيانات
    ريفيل البروموتر، والمندوب بيصوّر الترتيب مش بينقل بضاعة.
--}}

@php
    $hia = fn ($dt) => $dt?->copy()->timezone('Africa/Cairo')->format('m-d h:i A');

    $srcBadge = [
        'promoter' => ['b-purple', '🛍️', __('ops.sv_src_promoter')],
        'rep' => ['b-blue', '🛒', __('ops.sv_src_rep')],
    ];
@endphp

@section('content')

<div class="card">
    <h3>🛒 {{ __('ops.merch_visits') }}
        <span class="side">{{ __('ops.sv_hint') }}</span></h3>

    <form class="searchbar" method="GET">
        <label class="fl"><span>{{ __('ops.sv_source') }}</span>
            <select name="source">
                <option value="">{{ __('ops.sv_all_sources') }}</option>
                <option value="promoter" @selected($filters['source'] === 'promoter')>{{ __('ops.sv_src_promoter') }}</option>
                <option value="rep" @selected($filters['source'] === 'rep')>{{ __('ops.sv_src_rep') }}</option>
            </select></label>
        <label class="fl"><span>{{ __('ui.l_rep') }}</span>
            <select name="user">
                <option value="">{{ __('ops.sv_all_reps') }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected((int) $filters['user'] === (int) $r->id)>
                        {{ $r->displayName() }} — {{ $r->roleLabel() }}
                    </option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_client') }}</span>
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('common.search') }}"></label>
        <label class="fl"><span>{{ __('ops.sv_completeness') }}</span>
            <select name="shots">
                <option value="">{{ __('ui.all_of', ['x' => __('uic.statuses')]) }}</option>
                <option value="full" @selected($filters['shots'] === 'full')>{{ __('ops.sv_full') }}</option>
                <option value="partial" @selected($filters['shots'] === 'partial')>{{ __('ops.sv_partial') }}</option>
                <option value="none" @selected($filters['shots'] === 'none')>{{ __('ops.sv_no_photos') }}</option>
                <option value="counted" @selected($filters['shots'] === 'counted')>{{ __('ops.sv_counted') }}</option>
            </select></label>
        @include('partials._range', ['from' => $filters['from'], 'to' => $filters['to']])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.merch') }}">{{ __('common.clear') }}</a>
        {{-- الجدول صفحات — التصدير ده بياخد نتيجة الفلتر كلها --}}
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
        <span class="badge b-gray">{{ __('ops.visit_countable', ['count' => $visits->total()]) }}</span>
    </form>

    {{-- تنبيه «اتقفلت بدون تصوير» — بنفس فترة الفلتر، وآخر 7 أيام لو مفيش فترة --}}
    @if ($noPhotosCount > 0 && $filters['shots'] !== 'none')
        <div class="alert warn" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span>🚫 {{ __('ops.sv_no_photos_alert', ['count' => $noPhotosCount]) }}</span>
            <a class="btn sm" href="{{ request()->fullUrlWithQuery(['shots' => 'none', 'page' => null]) }}">{{ __('ops.sv_no_photos_show') }}</a>
        </div>
    @endif

    @if ($capped)
        <div class="alert info">{{ __('ops.sv_capped', ['count' => $cap]) }}</div>
    @endif

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th data-nosum>{{ __('ops.sv_source') }}</th>
                <th>{{ __('client.branch') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th data-nosum>{{ __('ops.checked_in') }}</th>
                <th data-nosum>{{ __('ops.sv_gps') }}</th>
                <th data-nosum>{{ __('ops.duration') }}</th>
                <th>{{ __('ops.moved_to_shelf') }}</th>
                <th>{{ __('ops.short') }}</th>
                <th data-nosum>{{ __('ops.shelf_photos') }}</th>
                <th data-nosum>{{ __('ops.items') }}</th>
                <th data-nosum>{{ __('ops.sv_count') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($visits as $v)
                @php [$badgeClass, $badgeIcon, $badgeText] = $srcBadge[$v['source']]; @endphp
                <tr>
                    <td><span class="badge {{ $badgeClass }}">{{ $badgeIcon }} {{ $badgeText }}</span></td>
                    <td>
                        @if ($v['client'])
                            <a href="{{ route('erp.clients.show', $v['client']->id) }}">
                                <b>{{ $v['client']->displayName() }}</b>
                            </a>
                            @if ($v['client']->channel)
                                <br><span class="badge {{ $v['client']->channel->badgeClass() }}">{{ $v['client']->channel->displayName() }}</span>
                            @endif
                        @else
                            <b>—</b>
                        @endif
                    </td>
                    <td>
                        @if ($v['user'])
                            <a href="{{ route('ops.rep', $v['user']->id) }}">{{ $v['user']->displayName() }}</a>
                        @else — @endif
                    </td>
                    <td class="num" dir="ltr">{{ $hia($v['at']) ?? '—' }}</td>
                    <td class="num">
                        @if ($v['source'] !== 'promoter')
                            —
                        @elseif ($v['gps'] === null)
                            <span class="badge b-gray">{{ __('ops.sv_no_gps') }}</span>
                        @elseif ($v['gps'] > 300)
                            <span class="badge b-red" title="{{ __('ops.sv_gps_far_hint') }}">📍 {{ number_format($v['gps']) }} {{ __('ops.sv_m') }}</span>
                        @else
                            <span class="badge b-green">📍 {{ number_format($v['gps']) }} {{ __('ops.sv_m') }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $v['minutes'] !== null ? __('ops.minutes', ['count' => $v['minutes']]) : __('ops.in_progress') }}</td>
                    <td class="num pos"><b>{{ $v['moved'] !== null ? $v['moved'] : '—' }}</b></td>
                    <td class="num {{ ($v['short'] ?? 0) > 0 ? 'neg' : '' }}">{{ $v['short'] !== null ? $v['short'] : '—' }}</td>
                    <td style="white-space:normal;min-width:230px">
                        @php $total = count($v['before']) + count($v['after']); @endphp
                        @if ($v['no_photos'])
                            <span class="badge b-red">🚫 {{ __('ops.sv_no_photos') }}</span>
                            <div style="font-size:11px;color:var(--muted);margin-top:3px">{{ $v['no_photo_reason'] }}</div>
                        @endif
                        @if ($total === 0)
                            @if (! $v['no_photos'])<span style="color:var(--muted)">—</span>@endif
                        @else
                            <div style="display:flex;gap:12px;flex-wrap:wrap">
                                @foreach (['before' => __('field.shelf_before'), 'after' => __('field.shelf_after')] as $stage => $label)
                                    @if ($v[$stage] !== [])
                                        <div>
                                            <div style="font-size:10px;color:var(--muted);font-weight:700;margin-bottom:4px">
                                                {{ $stage === 'before' ? '📷' : '✨' }} {{ $label }} · {{ count($v[$stage]) }}
                                            </div>
                                            <div style="display:flex;gap:4px;flex-wrap:wrap">
                                                @foreach ($v[$stage] as $url)
                                                    <a href="{{ $url }}" target="_blank" rel="noopener">
                                                        <img src="{{ $url }}" alt="" loading="lazy"
                                                             style="width:62px;height:62px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
                                                    </a>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                            @if ($v['before'] === [] || $v['after'] === [])
                                <span class="badge b-orange" style="font-size:9.5px">{{ __('ops.sv_partial') }}</span>
                            @endif
                        @endif
                    </td>
                    <td style="white-space:normal;max-width:300px;font-size:11px">
                        @forelse ($v['refills'] as $r)
                            <div style="color:{{ $r->out_of_stock ? 'var(--red)' : 'inherit' }}">
                                @if ($r->product)<a href="{{ route('erp.products.show', $r->product_id) }}" style="color:inherit">{{ $r->product->displayName() }}</a>@endif:
                                @if ($r->out_of_stock)
                                    {{ __('ops.out_of_stock') }}
                                @else
                                    {{ $r->shelf_before }} ← {{ $r->shelfAfter() }}
                                    <span style="color:var(--muted)">({{ __('ops.from_store_room_qty', ['qty' => $r->moved_qty]) }})</span>
                                @endif
                            </div>
                        @empty
                            @if ($v['visit_id'])
                                <a class="btn sm" href="{{ route('ops.visits', ['q' => $v['client']?->code, 'from' => $v['at']?->toDateString(), 'to' => $v['at']?->toDateString()]) }}">{{ __('ops.sv_open_visit') }}</a>
                            @else
                                <span style="color:var(--muted)">—</span>
                            @endif
                        @endforelse
                    </td>
                    {{-- جرد الرف بإيد المنسق: الكمية بوحدتها + تاريخ الإنتاج والانتهاء.
                         أحمر = منتهي، برتقالي = أقل من 30 يوم. --}}
                    <td style="white-space:normal;max-width:320px;font-size:11px">
                        @forelse ($v['counts'] as $c)
                            @php $d = $c->daysToExpiry(); @endphp
                            <div style="color:{{ $d !== null && $d < 0 ? 'var(--red)' : ($d !== null && $d <= 30 ? 'var(--orange)' : 'inherit') }}">
                                @if ($c->product)<a href="{{ route('erp.products.show', $c->product_id) }}" style="color:inherit">{{ $c->product->displayName() }}</a>@endif:
                                <b class="num">{{ rtrim(rtrim(number_format((float) $c->qty, 2), '0'), '.') }}</b> {{ __('stock.unit_'.$c->unit) }}
                                @if ($c->unit !== 'piece') <span style="color:var(--muted)">(<span class="num">{{ number_format($c->pieces) }}</span> {{ __('stock.unit_piece') }})</span> @endif
                                @if ($c->production_date) · {{ __('ops.sv_prod') }} <span class="num">{{ $c->production_date->format('Y-m-d') }}</span> @endif
                                @if ($c->expiry_date) · {{ __('ops.sv_exp') }} <span class="num">{{ $c->expiry_date->format('Y-m-d') }}</span> @endif
                                @if ($c->note) <span style="color:var(--muted)">— {{ $c->note }}</span> @endif
                            </div>
                        @empty
                            <span style="color:var(--muted)">—</span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.sv_no_rows') }}</td></tr>
            @endforelse
            </tbody>
            {{-- (٢٢/٩) الإجمالي من نتيجة الفلتر كلها — نفس أرقام ملف التصدير --}}
            @if ($visits->total() > 0)
                <tfoot>
                <tr style="background:var(--card2);font-weight:900">
                    <td>Σ</td>
                    <td colspan="5">{{ __('ops.visit_countable', ['count' => $visits->total()]) }}</td>
                    <td class="num">{{ number_format($sumMoved) }}</td>
                    <td class="num">{{ number_format($sumShort) }}</td>
                    <td colspan="3"></td>
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div class="pag">{{ $visits->links('pagination::simple-default') }}</div>
</div>

@endsection
