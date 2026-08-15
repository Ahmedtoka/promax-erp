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
        <div>
            <label class="f">{{ __('ops.sv_source') }}</label>
            <select name="source">
                <option value="">{{ __('ops.sv_all_sources') }}</option>
                <option value="promoter" @selected($filters['source'] === 'promoter')>{{ __('ops.sv_src_promoter') }}</option>
                <option value="rep" @selected($filters['source'] === 'rep')>{{ __('ops.sv_src_rep') }}</option>
            </select>
        </div>
        <div>
            <label class="f">{{ __('ops.rep') }}</label>
            <select name="user">
                <option value="">{{ __('ops.sv_all_reps') }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected((int) $filters['user'] === (int) $r->id)>
                        {{ $r->displayName() }} — {{ $r->roleLabel() }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('ops.vb_from') }}</label>
            <input type="date" name="from" value="{{ $filters['from'] }}">
        </div>
        <div>
            <label class="f">{{ __('ops.vb_to') }}</label>
            <input type="date" name="to" value="{{ $filters['to'] }}">
        </div>
        <div>
            <label class="f">{{ __('client.client') }}</label>
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('common.search') }}">
        </div>
        <div>
            <label class="f">{{ __('ops.sv_completeness') }}</label>
            <select name="shots">
                <option value="">{{ __('common.all') }}</option>
                <option value="full" @selected($filters['shots'] === 'full')>{{ __('ops.sv_full') }}</option>
                <option value="partial" @selected($filters['shots'] === 'partial')>{{ __('ops.sv_partial') }}</option>
            </select>
        </div>
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.merch') }}">{{ __('common.clear') }}</a>
        <span class="badge b-gray">{{ __('ops.visit_countable', ['count' => $visits->total()]) }}</span>
    </form>

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
                <th data-nosum>{{ __('ops.duration') }}</th>
                <th>{{ __('ops.moved_to_shelf') }}</th>
                <th>{{ __('ops.short') }}</th>
                <th data-nosum>{{ __('ops.shelf_photos') }}</th>
                <th data-nosum>{{ __('ops.items') }}</th>
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
                    <td>{{ $v['user']?->displayName() ?? '—' }}</td>
                    <td class="num" dir="ltr">{{ $hia($v['at']) ?? '—' }}</td>
                    <td class="num">{{ $v['minutes'] !== null ? __('ops.minutes', ['count' => $v['minutes']]) : __('ops.in_progress') }}</td>
                    <td class="num pos"><b>{{ $v['moved'] !== null ? $v['moved'] : '—' }}</b></td>
                    <td class="num {{ ($v['short'] ?? 0) > 0 ? 'neg' : '' }}">{{ $v['short'] !== null ? $v['short'] : '—' }}</td>
                    <td style="white-space:normal;min-width:230px">
                        @php $total = count($v['before']) + count($v['after']); @endphp
                        @if ($total === 0)
                            <span style="color:var(--muted)">—</span>
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
                                {{ $r->product?->displayName() }}:
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
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.sv_no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pag">{{ $visits->links('pagination::simple-default') }}</div>
</div>

@endsection
