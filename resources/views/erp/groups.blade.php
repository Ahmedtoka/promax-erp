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

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.chain') }}</th><th>{{ __('client.channel') }}</th><th>{{ __('client.segment') }}</th><th>{{ __('client.branch_count') }}</th>
                <th>{{ __('client.purchases') }}</th><th>{{ __('client.collected') }}</th><th>{{ __('client.balance') }}</th><th>{{ __('client.collection_rate') }}</th>
            </tr>
            @forelse ($groups as $g)
                @php
                    $s = $stats->get($g->id);
                    $p = (float) ($s->purchases ?? 0);
                    $c = (float) ($s->collections ?? 0);
                    $b = (float) ($s->balance ?? 0);
                @endphp
                <tr class="clickable" onclick="location.href='{{ route('erp.groups.show', $g) }}'">
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
                    <td class="num">{{ $p > 0 ? number_format($c / $p * 100, 1) : '0.0' }}%</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('client.no_chains') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

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
