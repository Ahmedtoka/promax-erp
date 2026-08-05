@extends('layouts.system')

@section('title', __('client.dues_page'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **المحاسب لازم يشوف الأزرار دي.** التحصيل والمستحقات
    // شغله الأساسي، والراوتس بتسمح له — بس `isManager()` كانت
    // بتخبّي كل حاجة عنه.
    $manager = auth()->user()->canWorkMoney();
    $isRtl = app()->getLocale() === 'ar';
@endphp

@section('actions')
    @if ($manager)
        @if (\App\Support\Access::action(auth()->user(), 'act.money.dues'))<form method="POST" action="{{ route('erp.dues.generate') }}" style="display:inline">
            @csrf
            <button class="btn gold">🔄 {{ __('client.due_generate') }}</button>
        </form>@endif
    @endif
    <a class="btn" href="{{ route('erp.contracts') }}">📜 {{ __('nav.contracts') }}</a>
@endsection

@section('content')

<div class="card" style="padding-bottom:12px">
    <h3>💸 {{ __('client.dues_page') }} <span class="side">{{ __('client.dues_sub') }}</span></h3>
    <div class="alert info">{{ __('client.dues_not_posted_hint') }}</div>
</div>

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('client.due_amount') }}</div>
        <div class="val neg">{{ $fmt($kpi['due_amount']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $kpi['due_count'] }} · {{ $kpi['clients'] }} {{ __('client.due_clients') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.due_settled') }}</div>
        <div class="val pos">{{ $fmt($kpi['settled_amount']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('client.due_status_settled') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.withheld_total') }}</div>
        <div class="val mid">{{ $fmt($kpi['withheld_total']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $kpi['withheld_clients'] }} · {{ __('client.withheld_hint') }}</div>
    </div>
</div>

{{-- ═══════════ أكبر المستحقات ═══════════ --}}
@if ($byClient->count() > 0)
<div class="grid2">
    <div class="card">
        <h3>📊 {{ __('client.top_by_dues') }}</h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('client.client') }}</th>
                    <th class="num">{{ __('common.count') }}</th>
                    <th class="num">{{ __('client.due_amount') }}</th>
                </tr>
                @foreach ($byClient as $row)
                    <tr>
                        <td>
                            @if ($row->client)
                                <a href="{{ route('erp.clients.show', $row->client) }}">{{ $row->client->displayName() }}</a>
                            @else — @endif
                        </td>
                        <td class="num">{{ $row->n }}</td>
                        <td class="num neg"><b>{{ $fmt($row->total) }}</b></td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    {{-- المحجوز — رقم مختلف تماماً عن المستحق --}}
    <div class="card">
        <h3>🔒 {{ __('client.held_by_client') }} <span class="side">{{ __('client.withheld_hint') }}</span></h3>
        @if ($withheld->count() > 0)
            <div class="tablewrap">
                <table>
                    <tr>
                        <th>{{ __('client.client') }}</th>
                        <th class="num">{{ __('client.balance') }}</th>
                        <th class="num">{{ __('client.withheld_total') }}</th>
                        <th class="num">{{ __('client.collectable') }}</th>
                    </tr>
                    @foreach ($withheld as $c)
                        <tr>
                            <td><a href="{{ route('erp.clients.show', $c) }}">{{ $c->displayName() }}</a></td>
                            <td class="num">{{ $fmt($c->balance) }}</td>
                            <td class="num neg"><b>{{ $fmt($c->withheld) }}</b></td>
                            <td class="num pos">{{ $fmt($c->collectableBalance()) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @else
            <div style="font-size:12px;color:var(--muted)">—</div>
        @endif
    </div>
</div>
@endif

{{-- ═══════════ بنود دورية من غير توقيت ═══════════ --}}
@if ($undated->count() > 0)
<div class="card">
    <h3>❓ {{ __('client.undated_rebates') }}
        <span class="side">{{ __('client.undated_rebates_hint') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.contract') }}</th>
                <th>{{ __('client.clause') }}</th>
                <th class="num">{{ __('client.clause_value') }}</th>
                <th></th>
            </tr>
            @foreach ($undated as $cl)
                <tr>
                    <td>{{ $cl->contract?->client?->displayName() ?: $cl->contract?->displayChain() ?: '—' }}</td>
                    <td style="white-space:normal;max-width:360px">{{ $cl->displayLabel() }}</td>
                    <td class="num"><b>{{ $cl->valueLabel() }}</b></td>
                    <td class="num">
                        @if ($cl->contract)
                            <a class="btn sm gold" href="{{ route('erp.contracts.show', $cl->contract) }}">
                                {{ __('client.set_basis') }}
                            </a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

{{-- ═══════════ الفلاتر ═══════════ --}}
<form class="searchbar" method="GET">
    <select name="status">
        <option value="all" @selected(($filters['status'] ?? '') === 'all')>{{ __('client.all_statuses') }}</option>
        @foreach (['due', 'settled', 'waived'] as $st)
            <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ __('client.due_status_'.$st) }}</option>
        @endforeach
    </select>

    <select name="client">
        <option value="">{{ __('client.all_clients') }}</option>
        @foreach ($clients as $c)
            <option value="{{ $c->id }}" @selected((int) ($filters['client'] ?? 0) === $c->id)>{{ $c->displayName() }}</option>
        @endforeach
    </select>

    <button class="btn gold">{{ __('common.search') }}</button>
    <a class="btn" href="{{ route('erp.dues') }}">{{ __('common.clear') }}</a>
</form>

{{-- ═══════════ الاستحقاقات ═══════════ --}}
<div class="card">
    <h3>💸 {{ __('client.dues_page') }} <span class="side">{{ $dues->total() }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('client.clause') }}</th>
                <th>{{ __('client.due_period') }}</th>
                <th class="num">{{ __('client.due_basis') }}</th>
                <th class="num">{{ __('client.clause_value') }}</th>
                <th class="num">{{ __('client.due_amount') }}</th>
                <th>{{ __('common.status') }}</th>
                @if ($manager)<th></th>@endif
            </tr>

            @forelse ($dues as $d)
                <tr>
                    <td>
                        @if ($d->client)
                            <a href="{{ route('erp.clients.show', $d->client) }}">{{ $d->client->displayName() }}</a>
                        @else — @endif
                        @if ($d->contract)
                            <br><a style="font-size:10px;color:var(--muted)"
                                   href="{{ route('erp.contracts.show', $d->contract) }}">{{ $d->contract->number }}</a>
                        @endif
                    </td>
                    <td style="white-space:normal;max-width:320px">{{ $d->label() }}</td>
                    <td class="num">
                        <b>{{ $d->periodLabel() }}</b>
                        <br><span style="font-size:10px;color:var(--muted)">
                            {{ $d->period_start?->format('Y-m-d') }} → {{ $d->period_end?->format('Y-m-d') }}
                        </span>
                    </td>
                    <td class="num">{{ $fmt($d->basis_amount) }}</td>
                    <td class="num">{{ $d->pct !== null ? number_format($d->pct * 100, 2).'%' : '—' }}</td>
                    <td class="num {{ $d->isDue() ? 'neg' : '' }}"><b>{{ $fmt($d->amount) }}</b></td>
                    <td>
                        <span class="badge {{ $d->statusClass() }}">{{ $d->statusLabel() }}</span>
                        @if ($d->settled_at)
                            <br><span style="font-size:10px;color:var(--muted)">{{ $d->settled_at->format('Y-m-d') }}</span>
                        @endif
                    </td>
                    @if ($manager)
                        <td class="num" style="white-space:nowrap">
                            @if ($d->isDue())
                                @if (\App\Support\Access::action(auth()->user(), 'act.money.dues'))<form method="POST" action="{{ route('erp.dues.settle', $d) }}" style="display:inline"
                                      onsubmit="return confirm(DUE_CONFIRM_SETTLE)">
                                    @csrf
                                    <button class="btn sm green">{{ __('client.due_settle') }}</button>
                                </form>@endif
                                @if (\App\Support\Access::action(auth()->user(), 'act.money.dues'))<form method="POST" action="{{ route('erp.dues.waive', $d) }}" style="display:inline"
                                      onsubmit="return confirm(DUE_CONFIRM_WAIVE)">
                                    @csrf
                                    <button class="btn sm">{{ __('client.due_waive') }}</button>
                                </form>@endif
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $manager ? 8 : 7 }}" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('client.no_dues') }}
                </td></tr>
            @endforelse
        </table>
    </div>

    <div class="pag">{{ $dues->links('pagination::simple-default') }}</div>
</div>

@endsection

@section('scripts')
<script>
    {{-- ⚠️ نصوص التأكيد في ثوابت مش جوه onsubmit — الأبوستروف جوه
         confirm('...') بيكسّر الجافاسكريبت في اللغتين. --}}
    const DUE_CONFIRM_SETTLE = @js(__('client.due_confirm_settle'));
    const DUE_CONFIRM_WAIVE = @js(__('client.due_confirm_waive'));
</script>
@endsection
