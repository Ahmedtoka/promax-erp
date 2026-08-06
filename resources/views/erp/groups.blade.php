@extends('layouts.system')

@section('title', __('client.chains'))

@php
    use App\Models\Channel;
    $fmt = fn ($n) => number_format((float) $n);
    $manager = auth()->user()->isManager();
@endphp

@section('actions')
    @if ($manager)
        <button class="btn gold" onclick="openDlg('dlgNewG')">+ {{ __('client.new_chain') }}</button>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('client.chain_count') }}</div>
        <div class="val">{{ $groups->count() }}</div>
        <div class="sub2">{{ __('client.linked_branches', ['count' => $groups->sum('clients_count')]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.chains_purchases') }}</div>
        <div class="val" style="color:var(--primary)">{{ $fmt($stats->sum('purchases')) }} {{ __('common.currency') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.chains_balance') }}</div>
        <div class="val {{ $stats->sum('balance') > 0 ? 'neg' : 'pos' }}">{{ $fmt($stats->sum('balance')) }} {{ __('common.currency') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.independent_clients') }}</div>
        <div class="val">{{ $ungrouped }}</div>
        <div class="sub2">{{ __('client.no_chain') }}</div>
    </div>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('client.search_chain') }}">
        <select name="channel">
            <option value="">{{ __('client.all_channels') }}</option>
            @foreach ($channels as $ch)
                <option value="{{ $ch->id }}" @selected((int) ($filters['channel'] ?? 0) === $ch->id)>{{ $ch->displayName() }}</option>
            @endforeach
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.groups') }}">{{ __('common.clear') }}</a>
        <span class="badge b-gray">{{ __('client.chain_countable', ['count' => $groups->count()]) }}</span>
    </form>

    {{-- فريز الهيدر + سورت بالضغط على العمود (2026-08-06) —
         نفس نمط شاشة المنتجات: الحاوية هي اللي بتسكرول والهيدر sticky --}}
    <div class="tablewrap" style="max-height:66vh;overflow-y:auto">
        <table id="chainsTbl">
            <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
            <tr>
                <th class="srt" data-k="name" data-t="s">{{ __('client.chain') }}<span class="arw"></span></th>
                <th class="srt" data-k="channel" data-t="s">{{ __('client.channel') }}<span class="arw"></span></th>
                <th class="srt" data-k="segment" data-t="s">{{ __('client.segment') }}<span class="arw"></span></th>
                <th class="srt" data-k="branches" data-t="n">{{ __('client.branch_count') }}<span class="arw">▼</span></th>
                <th class="srt" data-k="purchases" data-t="n">{{ __('client.purchases') }}<span class="arw"></span></th>
                <th class="srt" data-k="collected" data-t="n">{{ __('client.collected') }}<span class="arw"></span></th>
                <th class="srt" data-k="balance" data-t="n">{{ __('client.balance') }}<span class="arw"></span></th>
                <th class="srt" data-k="rate" data-t="n">{{ __('client.collection_rate') }}<span class="arw"></span></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($groups as $g)
                @php
                    $s = $stats->get($g->id);
                    $p = (float) ($s->purchases ?? 0);
                    $c = (float) ($s->collections ?? 0);
                    $b = (float) ($s->balance ?? 0);
                    $rate = $p > 0 ? $c / $p * 100 : 0;
                @endphp
                <tr class="clickable" onclick="location.href='{{ route('erp.groups.show', $g) }}'"
                    data-name="{{ mb_strtolower($g->displayName()) }}"
                    data-channel="{{ mb_strtolower($g->channel?->displayName() ?? '') }}"
                    data-segment="{{ mb_strtolower($g->subChannelLabel() ?? '') }}"
                    data-branches="{{ $g->clients_count }}"
                    data-purchases="{{ $p }}" data-collected="{{ $c }}"
                    data-balance="{{ $b }}" data-rate="{{ round($rate, 2) }}">
                    <td>
                        <b>{{ $g->displayName() }}</b>
                        @if (! $g->active)<span class="badge b-gray">{{ __('client.suspended') }}</span>@endif
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $g->code }}</span>
                    </td>
                    <td>
                        @if ($g->channel)
                            <span class="badge {{ $g->channel->badgeClass() }}">{{ $g->channel->displayName() }}</span>
                        @else — @endif
                    </td>
                    <td style="color:var(--muted);font-size:11.5px">{{ $g->subChannelLabel() ?? '—' }}</td>
                    <td class="num"><b>{{ $g->clients_count }}</b></td>
                    <td class="num">{{ $fmt($p) }}</td>
                    <td class="num pos">{{ $fmt($c) }}</td>
                    <td class="num {{ $b > 0 ? 'neg' : 'pos' }}">{{ $fmt($b) }}</td>
                    <td class="num">{{ number_format($rate, 1) }}%</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('client.no_chains') }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<style>
    #chainsTbl th.srt{cursor:pointer;user-select:none;white-space:nowrap}
    #chainsTbl th.srt:hover{color:var(--primary)}
    #chainsTbl th.srt .arw{font-size:9px;margin-inline-start:4px;color:var(--primary)}
</style>

<script>
(function () {
    'use strict';
    const tbl = document.getElementById('chainsTbl');
    if (!tbl) return;
    const tbody = tbl.tBodies[0];
    // الترتيب الحالي: جاي من السيرفر بأكتر الفروع تنازلي
    let cur = 'branches', dir = -1;

    tbl.querySelectorAll('th.srt').forEach(function (th) {
        th.addEventListener('click', function () {
            const k = th.dataset.k, numeric = th.dataset.t === 'n';
            // نفس العمود = اقلب الاتجاه؛ عمود جديد = الأرقام تنازلي والنصوص تصاعدي
            dir = (k === cur) ? -dir : (numeric ? -1 : 1);
            cur = k;

            const rows = Array.from(tbody.rows).filter(function (r) { return r.dataset[k] !== undefined; });
            rows.sort(function (a, b) {
                const va = a.dataset[k], vb = b.dataset[k];
                return dir * (numeric
                    ? (parseFloat(va) || 0) - (parseFloat(vb) || 0)
                    : va.localeCompare(vb, ['ar', 'en']));
            });
            rows.forEach(function (r) { tbody.appendChild(r); });

            tbl.querySelectorAll('th.srt .arw').forEach(function (s) { s.textContent = ''; });
            th.querySelector('.arw').textContent = dir === -1 ? '▼' : '▲';
        });
    });
})();
</script>

@if ($manager)
<dialog id="dlgNewG">
    <form class="dlg" method="POST" action="{{ route('erp.groups.store') }}">
        @csrf
        <h4>{{ __('client.new_chain') }}</h4>
        <div class="frow">
            {{-- ⚠️ **الإنجليزي الأول — زي فورم العميل بالظبط.**
                 اللي بيدخل الداتا بيتعلّم مكان الخانة بعينه، وقلب
                 الترتيب بين شاشة وشاشة بيخلّيه يكتب العربي في خانة
                 الإنجليزي وميلاحظش. --}}
            <div>
                <label class="f">{{ __('client.chain_name_en') }}</label>
                <input type="text" name="name_en" dir="ltr" maxlength="120" style="width:100%"
                       value="{{ old('name_en') }}" placeholder="{{ __('client.chain_name_en_ph') }}">
            </div>
            <div>
                <label class="f">{{ __('client.chain_name_ar') }} <b class="req-star">*</b></label>
                <input type="text" name="name" required maxlength="120" style="width:100%"
                       class="{{ $errors->has('name') ? 'bad' : '' }}"
                       value="{{ old('name') }}" placeholder="{{ __('client.chain_name_ar_ph') }}">
                @error('name')<div class="errline">{{ $message }}</div>@enderror
            </div>
            <div><label class="f">{{ __('client.channel') }}</label>
                <select name="channel_id" style="width:100%">
                    <option value="">— {{ __('common.none') }} —</option>
                    @foreach ($channels as $ch)<option value="{{ $ch->id }}">{{ $ch->displayName() }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('client.segment') }}</label>
                <select name="sub_channel" style="width:100%">
                    <option value="">— {{ __('client.not_applicable') }} —</option>
                    @foreach (Channel::SUB_CHANNELS as $k => $lbl)<option value="{{ $k }}">{{ __('enums.sub_channel.'.$k) }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="alert info" style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('client.chain_is_a_grouping') }}</span>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewG')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.create') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
