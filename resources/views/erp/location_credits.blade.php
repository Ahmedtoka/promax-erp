@extends('layouts.system')

{{--
    رصيد المناديب من عناوين العملاء  ·  ١٧ أغسطس ٢٠٢٦

    طلب المالك: «شاشة فيها كل مندوب عمل طلب تعديل لوكيشن كام مرة،
    ولما أدوس عليهم يطلع ليست بكل العملاء اللي عملهم تعديل».

    ⚠️ **مستويين في شاشة واحدة**: من غير `?rep=` بتوري المناديب،
    ومعاه بتوري عملاء المندوب. شاشتين مالهمش لازمة.
--}}

@section('title', __('geo.rep_credits'))

@section('actions')
    @if ($repId > 0)
        <a class="btn" href="{{ route('erp.client_locations.credits') }}">← {{ __('geo.all_reps') }}</a>
    @endif
    <a class="btn" href="{{ route('erp.client_locations') }}">📍 {{ __('geo.confirm_locations') }}</a>
@endsection

@section('content')

@php
    $totalSent = $rows->sum('sent');
    $totalOk = $rows->sum('confirmed');
    // ⚠️ النقط بتتحسب **لكل مندوب على حدة** وبعدين بتتجمع — مش على
    // الإجمالي. `intdiv(9,5)+intdiv(9,5)=2` بس `intdiv(18,5)=3`،
    // والمندوب مش بياخد نقطة من باقي زميله.
    $totalPts = $rows->sum(fn ($r) => intdiv((int) $r->confirmed, $perPoint) * $ptsPer);
@endphp

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('geo.sent_total') }}</div>
        <div class="val">{{ number_format($totalSent) }}</div>
        <div class="sub2">{{ __('geo.sent_total_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('geo.confirmed_total') }}</div>
        <div class="val pos">{{ number_format($totalOk) }}</div>
        <div class="sub2">{{ __('geo.confirmed_total_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('geo.points_earned') }}</div>
        <div class="val">{{ number_format($totalPts) }}</div>
        <div class="sub2">{{ __('geo.points_rule', ['n' => $perPoint, 'p' => $ptsPer]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('incent.point_value') }}</div>
        <div class="val">{{ number_format($totalPts * $pointValue, 2) }}</div>
        <div class="sub2">{{ __('geo.points_money_hint') }}</div>
    </div>
</div>

@if ($repId === 0)
    {{-- ═══════════ المستوى الأول: المناديب ═══════════ --}}
    <div class="card">
        <h3>🧭 {{ __('geo.rep_credits') }}
            <span class="side">{{ __('geo.reps_countable', ['count' => $rows->count()]) }}</span></h3>

        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    {{-- ⚠️ `ops.rep` — المفتاح الموجود فعلاً. `team.rep`
                         مش موجود وكان هيطبع المفتاح الخام في الهيدر. --}}
                    <th>{{ __('ops.rep') }}</th>
                    <th class="num">{{ __('geo.sent') }}</th>
                    <th class="num">{{ __('geo.confirmed') }}</th>
                    <th class="num">{{ __('geo.pending_review') }}</th>
                    <th class="num">{{ __('geo.points') }}</th>
                    <th>{{ __('geo.last_sent') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $r)
                    @php
                        $u = $users->get($r->uid);
                        $sent = (int) $r->sent;
                        $ok = (int) $r->confirmed;
                        $pts = intdiv($ok, $perPoint) * $ptsPer;
                        // فاضل كام عنوان للنقطة الجاية — بيخلّي الرقم
                        // هدف مش مجرد إحصائية
                        $toNext = $perPoint - ($ok % $perPoint);
                    @endphp
                    <tr class="clickable"
                        onclick="location.href='{{ route('erp.client_locations.credits', ['rep' => $r->uid]) }}'">
                        <td><b>{{ $u?->displayName() ?? '#'.$r->uid }}</b>
                            @if ($u?->code)
                                <span style="color:var(--muted);font-size:11px"> · {{ $u->code }}</span>
                            @endif
                        </td>
                        <td class="num">{{ number_format($sent) }}</td>
                        <td class="num" style="color:var(--green,#16A34A);font-weight:800">{{ number_format($ok) }}</td>
                        {{-- ⚠️ **المستنّي = بعت ناقص اتأكّد.** الرقم ده
                             بيقول للمراجع فيه شغل مستنيه من المندوب ده
                             قد إيه، مش بيقول إن المندوب مقصّر. --}}
                        <td class="num" style="color:var(--muted)">{{ number_format($sent - $ok) }}</td>
                        <td class="num"><b>{{ number_format($pts) }}</b>
                            @if ($ptsPer > 0)
                                <br><span style="font-size:10px;color:var(--muted)">
                                    {{ __('geo.to_next_point', ['n' => $toNext]) }}</span>
                            @endif
                        </td>
                        <td style="color:var(--muted);font-size:11.5px">
                            {{ $r->last_at ? \Illuminate\Support\Carbon::parse($r->last_at)->format('Y-m-d h:i A') : '—' }}
                        </td>
                        <td>@include('partials._view', ['url' => route('erp.client_locations.credits', ['rep' => $r->uid])])</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">
                        {{ __('geo.no_credits_yet') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@else
    {{-- ═══════════ المستوى الثاني: عملاء المندوب ═══════════ --}}
    <div class="card">
        <h3>🧭 {{ $rep?->displayName() ?? '#'.$repId }}
            <span class="side">{{ __('client.client_countable', ['count' => $clients->count()]) }}</span></h3>

        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('client.client') }}</th>
                    <th>{{ __('client.zone') }}</th>
                    <th>{{ __('geo.current_point') }}</th>
                    <th>{{ __('geo.sent_at') }}</th>
                    <th>{{ __('geo.state') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($clients as $c)
                    <tr class="clickable"
                        onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                        <td>
                            <b>{{ $c->fullName() }}</b>
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $c->displayAddress() ?: '—' }}</span>
                        </td>
                        <td style="color:var(--muted)">{{ $c->zone?->displayName() ?? '—' }}</td>
                        <td class="num">
                            @if ($c->hasLocation())
                                <span dir="ltr">{{ number_format((float) $c->lat, 5) }}, {{ number_format((float) $c->lng, 5) }}</span>
                            @else
                                <span style="color:var(--red)">—</span>
                            @endif
                        </td>
                        <td style="color:var(--muted);font-size:11.5px">
                            {{ $c->location_submitted_at?->format('Y-m-d h:i A') ?? '—' }}
                        </td>
                        <td>
                            @if ($c->location_confirmed_at)
                                <span class="badge b-green">✅ {{ __('geo.confirmed') }}</span>
                                <br><span style="font-size:10px;color:var(--muted)">
                                    @if ($c->locationConfirmer)
                                        {{ $c->locationConfirmer->displayName() }} ·
                                    @endif
                                    {{ $c->location_confirmed_at->format('Y-m-d') }}</span>
                            @else
                                <span class="badge b-purple">🚩 {{ __('geo.pending_review') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:30px">
                        {{ __('geo.none_here') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
