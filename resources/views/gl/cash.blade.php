@extends('layouts.system')

{{--
    حركة النقدية (١١/٩/٢٠٢٦) — نقل فلوس بين الخزنة والبنك ونقدية
    المناديب. مافيش بيع ولا عميل هنا: الطرفين حسابين نقديين، والقيد
    بيتولّد لوحده من `CashMovementObserver`.
--}}

@section('title', __('gl.cash'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
    $repKinds = \App\Models\CashMovement::REP_KINDS;
    // عمود الإلغاء بيظهر لصاحب الصلاحية بس — والـcolspan لازم يمشي معاه
    $cols = $canPost ? 9 : 8;
@endphp

@section('actions')
    @if ($canPost)
        <button class="btn gold" type="button" onclick="openDlg('dlgCash')">＋ {{ __('gl.new_cash') }}</button>
    @endif
@endsection

@section('content')

<div class="kpis">
    @foreach ($kinds as $k)
        <div class="kpi">
            <div class="lbl">{{ __('gl.kind_'.$k) }}</div>
            <div class="val">{{ $fmt($byKind[$k] ?? 0) }}</div>
            <div class="sub2">{{ __('common.currency') }}</div>
        </div>
    @endforeach
</div>

<div class="card">
    <h3>🏦 {{ __('gl.cash') }} <span class="side">{{ __('gl.cash_sub') }}</span></h3>

    <form method="GET" class="frow" style="margin-bottom:12px" data-noprint>
        <div>
            <label class="f">{{ __('gl.kind') }}</label>
            <select name="kind" onchange="this.form.submit()">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($kinds as $k)
                    <option value="{{ $k }}" @selected(request('kind') === $k)>{{ __('gl.kind_'.$k) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('gl.status') }}</label>
            <select name="status" onchange="this.form.submit()">
                <option value="">{{ __('common.all') }}</option>
                <option value="posted" @selected(request('status') === 'posted')>{{ __('gl.status_posted') }}</option>
                <option value="void" @selected(request('status') === 'void')>{{ __('gl.status_void') }}</option>
            </select>
        </div>
        <div>
            <label class="f">{{ __('common.from') }}</label>
            <input type="date" name="from" value="{{ $range->fromValue() }}" onchange="this.form.submit()">
        </div>
        <div>
            <label class="f">{{ __('common.to') }}</label>
            <input type="date" name="to" value="{{ $range->toValue() }}" onchange="this.form.submit()">
        </div>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('gl.number') }}</th>
                <th>{{ __('common.date') }}</th>
                <th style="text-align:start">{{ __('gl.kind') }}</th>
                <th style="text-align:start">{{ __('gl.rep') }}</th>
                <th data-nosum>{{ __('gl.reference') }}</th>
                <th>{{ __('gl.attachment') }}</th>
                <th>{{ __('gl.status') }}</th>
                <th class="num">{{ __('gl.amount') }}</th>
                @if ($canPost)<th></th>@endif
            </tr>
            @forelse ($rows as $m)
                <tr>
                    <td><b>{{ $m->number }}</b></td>
                    <td class="num" style="font-size:11px">{{ $m->date?->format('Y-m-d') }}</td>
                    <td style="text-align:start">
                        {{ __('gl.kind_'.$m->kind) }}
                        @if ($m->note)
                            <div style="font-size:10.5px;color:var(--muted)">{{ $m->note }}</div>
                        @endif
                    </td>
                    <td style="text-align:start">
                        @if ($m->user)
                            {{ $m->user->displayName() }}
                            <div style="font-size:10px;color:var(--muted)">{{ $m->user->code }}</div>
                        @else — @endif
                    </td>
                    <td style="font-size:11px">{{ $m->reference ?: '—' }}</td>
                    <td>
                        @if ($m->attachment_path)
                            <a class="btn sm" href="{{ asset('storage/'.$m->attachment_path) }}" target="_blank">📎 {{ __('common.view') }}</a>
                        @else — @endif
                    </td>
                    <td>
                        <span class="badge {{ $m->status === 'posted' ? 'b-green' : 'b-red' }}">{{ __('gl.status_'.$m->status) }}</span>
                    </td>
                    <td class="num"><b>{{ $fmt($m->amount) }}</b></td>
                    @if ($canPost)
                        <td>
                            @if ($m->status === 'posted')
                                <form method="POST" action="{{ route('gl.cash.void', $m) }}" style="display:inline"
                                      onsubmit="return confirm(@js(__('gl.confirm_void')))">
                                    @csrf
                                    <button class="btn sm" type="submit">✕ {{ __('gl.void') }}</button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $cols }}" style="color:var(--muted);font-size:12px">{{ __('gl.no_rows') }}</td></tr>
            @endforelse
        </table>
    </div>

    <div style="margin-top:12px">{{ $rows->links() }}</div>
    <div class="sub2" style="font-size:11px;color:var(--muted)">
        {{ __('gl.total_posted') }}: <b>{{ $fmt($total) }}</b> {{ __('common.currency') }}
    </div>
</div>

{{-- ═══════════ سند حركة نقدية — الديالوج جوه content عن قصد ═══════════ --}}
@if ($canPost)
<dialog id="dlgCash">
    <form class="dlg" method="POST" enctype="multipart/form-data" action="{{ route('gl.cash.store') }}">
        @csrf
        <h4>🏦 {{ __('gl.new_cash') }}</h4>

        <div class="frow">
            <div>
                <label class="f">{{ __('common.date') }} <b class="req-star">*</b></label>
                <input type="date" name="date" required value="{{ old('date', today()->toDateString()) }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('gl.amount') }} <b class="req-star">*</b></label>
                <input type="number" name="amount" required step="0.01" min="0.01" max="99999999" dir="ltr" style="width:100%">
            </div>
        </div>

        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('gl.kind') }} <b class="req-star">*</b></label>
                <select name="kind" id="cmKind" required onchange="cmToggleRep()" style="width:100%">
                    @foreach ($kinds as $k)
                        <option value="{{ $k }}" @selected(old('kind') === $k)>{{ __('gl.kind_'.$k) }}</option>
                    @endforeach
                </select>
            </div>
            <div id="cmRepBox" hidden>
                <label class="f">{{ __('gl.rep') }} <b class="req-star">*</b></label>
                <select name="user_id" data-req-repkind style="width:100%">
                    <option value="">— {{ __('common.pick') }} —</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected(old('user_id') == $r->id)>{{ $r->displayName() }} ({{ $r->code }})</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('gl.reference') }}</label>
                <input type="text" name="reference" maxlength="80" value="{{ old('reference') }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('gl.note') }}</label>
                <input type="text" name="note" maxlength="250" value="{{ old('note') }}" style="width:100%">
            </div>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('gl.attachment') }}</label>
            <input type="file" name="attachment" accept="image/*,application/pdf" style="width:100%">
            <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('gl.attachment_hint') }}</div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgCash')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

<script>
// خانة المندوب بتبان للعهدة والرد بس — ولما تتخبّى بتتفضّى، عشان
// اختيار قديم مايتبعتش مع إيداع بنك
var CM_REP_KINDS = @json($repKinds);
function cmToggleRep() {
    var sel = document.getElementById('cmKind');
    var box = document.getElementById('cmRepBox');
    if (!sel || !box) return;
    var on = CM_REP_KINDS.indexOf(sel.value) !== -1;
    box.hidden = !on;
    var field = box.querySelector('[name="user_id"]');
    if (field) { field.required = on; if (!on) field.value = ''; }
}
cmToggleRep();
</script>

@endsection
