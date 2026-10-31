@extends('layouts.system')

@section('title', $m->displayName())

@php
    use App\Models\Channel;

    $fmt = fn ($n) => number_format((float) $n);
    $canEdit = \App\Support\Access::action(auth()->user(), 'act.org.structure');
    $all = $clients->flatten(1);
    $purchases = $all->sum('purchases');
    $collections = $all->sum('collections');
    $returns = $all->sum('returns');
    $balance = $all->sum('balance');
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.mother_companies') }}">← {{ __('client.mother_all') }}</a>
    <a class="btn" href="{{ route('erp.mother_companies.export', ['mother' => $m->id]) }}">⬇ {{ __('client.mother_export') }}</a>
    @if ($canEdit)
        <button class="btn" onclick="openDlg('dlgEditM')">{{ __('common.edit') }}</button>
        <button class="btn" onclick="openDlg('dlgNewChain')">+ {{ __('client.new_chain') }}</button>
        <button class="btn gold" onclick="openDlg('dlgAttach')">+ {{ __('client.mother_add_chains') }}</button>
    @endif
@endsection

@section('content')

@if (! $m->active)
    <div class="alert warn" style="margin-bottom:12px"><span>⚠️</span><span>{{ __('client.suspended') }}</span></div>
@endif

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('client.mother_chains') }}</div>
        <div class="val">{{ $groups->count() }}</div>
        <div class="sub2">{{ __('client.linked_branches', ['count' => $all->count()]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.purchases') }}</div>
        <div class="val">{{ $fmt($purchases) }} {{ __('common.currency') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.collected') }}</div>
        <div class="val pos">{{ $fmt($collections) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('client.returns') }}: {{ $fmt($returns) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.balance') }}</div>
        <div class="val {{ $balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($balance) }} {{ __('common.currency') }}</div>
    </div>
</div>

<div class="card">
    <div class="tablewrap">
        <table id="motherChains">
            <thead>
            <tr>
                <th>{{ __('client.chain') }}</th>
                <th>{{ __('client.channel') }}</th>
                <th>{{ __('client.branch_count') }}</th>
                <th>{{ __('client.purchases') }}</th>
                <th>{{ __('client.collected') }}</th>
                <th>{{ __('client.returns') }}</th>
                <th>{{ __('client.balance') }}</th>
                @if ($canEdit)<th></th>@endif
            </tr>
            </thead>
            <tbody>
            @forelse ($groups as $g)
                @php $members = $clients->get($g->id, collect()); @endphp
                {{-- الضغط على الصف بيفتح عملاء السلسلة تحته، واسمها بيفتح صفحتها --}}
                <tr class="clickable" onclick="toggleChain({{ $g->id }}, this)">
                    <td>
                        <span class="arw" style="color:var(--primary);font-size:10px">▶</span>
                        <a href="{{ route('erp.groups.show', $g) }}" onclick="event.stopPropagation()"><b>{{ $g->displayName() }}</b></a>
                        @if (! $g->active)<span class="badge b-gray">{{ __('client.suspended') }}</span>@endif
                    </td>
                    <td>@if ($g->channel)<span class="badge {{ $g->channel->badgeClass() }}">{{ $g->channel->displayName() }}</span>@else — @endif</td>
                    <td class="num"><b>{{ $members->count() }}</b></td>
                    <td class="num">{{ $fmt($members->sum('purchases')) }}</td>
                    <td class="num pos">{{ $fmt($members->sum('collections')) }}</td>
                    <td class="num mid">{{ $fmt($members->sum('returns')) }}</td>
                    <td class="num {{ $members->sum('balance') > 0 ? 'neg' : 'pos' }}">{{ $fmt($members->sum('balance')) }}</td>
                    @if ($canEdit)
                        <td onclick="event.stopPropagation()">
                            <form method="POST" action="{{ route('erp.mother_companies.attach', $m) }}" style="display:inline"
                                  onsubmit="return confirm(@js(__('client.mother_confirm_detach')))">
                                @csrf
                                <input type="hidden" name="action" value="detach">
                                <input type="hidden" name="group_ids[]" value="{{ $g->id }}">
                                <button class="btn sm red" type="submit">{{ __('client.detach') }}</button>
                            </form>
                        </td>
                    @endif
                </tr>
                <tr id="chainClients{{ $g->id }}" style="display:none">
                    <td colspan="{{ $canEdit ? 8 : 7 }}" style="background:var(--card2);padding:10px 14px">
                        @if ($members->isEmpty())
                            <span style="color:var(--muted)">{{ __('client.no_branches') }}</span>
                        @else
                            <table>
                                <thead>
                                <tr>
                                    <th>{{ __('common.code') }}</th>
                                    <th>{{ __('client.client') }}</th>
                                    <th>{{ __('client.purchases') }}</th>
                                    <th>{{ __('client.collected') }}</th>
                                    <th>{{ __('client.returns') }}</th>
                                    <th>{{ __('client.balance') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($members as $c)
                                    <tr>
                                        <td>{{ $c->code }}</td>
                                        <td><a href="{{ route('erp.clients.show', $c) }}">{{ $c->displayName() }}</a></td>
                                        <td class="num">{{ $fmt($c->purchases) }}</td>
                                        <td class="num pos">{{ $fmt($c->collections) }}</td>
                                        <td class="num mid">{{ $fmt($c->returns) }}</td>
                                        <td class="num {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $canEdit ? 8 : 7 }}" style="text-align:center;color:var(--muted);padding:28px">{{ __('client.mother_no_chains') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleChain(id, row) {
    const box = document.getElementById('chainClients' + id);
    const open = box.style.display === 'none';
    box.style.display = open ? '' : 'none';
    row.querySelector('.arw').textContent = open ? '▼' : '▶';
}
</script>

@if ($canEdit)
<dialog id="dlgAttach">
    <form class="dlg" method="POST" action="{{ route('erp.mother_companies.attach', $m) }}">
        @csrf
        <input type="hidden" name="action" value="attach">
        <h4>{{ __('client.mother_add_chains') }}</h4>
        <input type="text" id="attachFilter" placeholder="🔍 {{ __('client.search_chain') }}" style="width:100%;margin-bottom:10px"
               oninput="const s = this.value.toLowerCase(); document.querySelectorAll('#attachList label').forEach(l => l.style.display = l.textContent.toLowerCase().includes(s) ? '' : 'none')">
        <div id="attachList" style="max-height:320px;overflow-y:auto">
            @forelse ($available as $g)
                <label style="display:flex;gap:8px;align-items:center;padding:6px 2px">
                    <input type="checkbox" name="group_ids[]" value="{{ $g->id }}">
                    <span>{{ $g->displayName() }}
                        @if ($g->motherCompany)
                            <span class="badge b-orange">{{ __('client.mother_belongs_to', ['name' => $g->motherCompany->displayName()]) }}</span>
                        @endif
                    </span>
                </label>
            @empty
                <div style="color:var(--muted);padding:12px">{{ __('client.no_chains') }}</div>
            @endforelse
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgAttach')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

<dialog id="dlgNewChain">
    <form class="dlg" method="POST" action="{{ route('erp.groups.store') }}">
        @csrf
        <input type="hidden" name="mother_company_id" value="{{ $m->id }}">
        <h4>{{ __('client.new_chain') }}</h4>
        <div class="frow">
            <div>
                <label class="f">{{ __('client.chain_name_en') }}</label>
                <input type="text" name="name_en" dir="ltr" maxlength="120" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('client.chain_name_ar') }} <b class="req-star">*</b></label>
                <input type="text" name="name" required maxlength="120" style="width:100%">
            </div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('client.channel') }}</label>
                <select name="channel_id" style="width:100%">
                    <option value="">— {{ __('common.none') }} —</option>
                    @foreach ($channels as $ch)<option value="{{ $ch->id }}">{{ $ch->displayName() }}</option>@endforeach
                </select>
            </div>
            <div><label class="f">{{ __('client.segment') }}</label>
                <select name="sub_channel" style="width:100%">
                    <option value="">— {{ __('client.not_applicable') }} —</option>
                    @foreach (Channel::SUB_CHANNELS as $k => $lbl)<option value="{{ $k }}">{{ __('enums.sub_channel.'.$k) }}</option>@endforeach
                </select>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewChain')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.create') }}</button>
        </div>
    </form>
</dialog>

<dialog id="dlgEditM">
    <form class="dlg" method="POST" action="{{ route('erp.mother_companies.update', $m) }}">
        @csrf @method('PUT')
        <h4>{{ __('common.edit_named', ['name' => $m->displayName()]) }}</h4>
        <div class="frow">
            <div>
                <label class="f">{{ __('client.mother_name_en') }}</label>
                <input type="text" name="name_en" dir="ltr" maxlength="120" style="width:100%" value="{{ old('name_en', $m->name_en) }}">
            </div>
            <div>
                <label class="f">{{ __('client.mother_name_ar') }} <b class="req-star">*</b></label>
                <input type="text" name="name" required maxlength="120" style="width:100%"
                       class="{{ $errors->has('name') ? 'bad' : '' }}" value="{{ old('name', $m->name) }}">
                @error('name')<div class="errline">{{ $message }}</div>@enderror
            </div>
        </div>
        <div><label class="f">{{ __('common.notes') }}</label><textarea name="notes" rows="2" style="width:100%">{{ old('notes', $m->notes) }}</textarea></div>
        <label style="display:flex;gap:8px;align-items:center;margin-top:10px">
            <input type="checkbox" name="active" value="1" @checked($m->active)> {{ __('client.mother_active') }}
        </label>
        <div style="display:flex;gap:8px;justify-content:space-between;margin-top:12px">
            <button class="btn red" type="button" onclick="document.getElementById('formDelM').submit()">{{ __('common.delete') }}</button>
            <div style="display:flex;gap:8px">
                <button class="btn" type="button" onclick="closeDlg('dlgEditM')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('common.save') }}</button>
            </div>
        </div>
    </form>
</dialog>
<form id="formDelM" method="POST" action="{{ route('erp.mother_companies.destroy', $m) }}"
      onsubmit="return confirm(@js(__('client.mother_confirm_delete')))">
    @csrf @method('DELETE')
</form>
@endif

@endsection
