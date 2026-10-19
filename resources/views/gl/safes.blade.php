@extends('layouts.system')

{{--
    الخزن والبنوك (Lane 4، ١٩/١٠/٢٠٢٦) — سجل الخزن وحسابات البنوك.
    الرصيد من الدفتر (L4-D1): صافي حساب الخزنة في الشجرة، فالرقم هنا
    هو نفسه اللي في ميزان المراجعة. كل خزنة ليها حساب (L4-D2).
--}}

@section('title', __('cash.safes'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
    $cols = $canPost ? 11 : 10;
@endphp

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
    @if ($canPost)
        <button class="btn gold" type="button" onclick="openDlg('dlgSafeNew')">＋ {{ __('cash.new_safe') }}</button>
    @endif
@endsection

@section('content')

@if ($ledgerOff)
    <div class="alert info" style="margin-bottom:12px"><span>ℹ️</span><span>{{ __('cash.ledger_off') }}</span></div>
@endif
@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $errors->first() }}</span></div>
@endif

<div class="kpis">
    @foreach (['cash' => 'cash.cash_total', 'bank' => 'cash.bank_total'] as $k => $lbl)
        <a class="kpi" style="grid-column:span 2" href="{{ request()->fullUrlWithQuery(['kind' => request('kind') === $k ? null : $k]) }}#safes-table" title="{{ __('ui.click_to_filter') }}">
            <div class="lbl">{{ __($lbl) }}</div>
            <div class="val {{ $kpis[$k]['balance'] < 0 ? 'neg' : '' }}" dir="ltr">{{ $fmt($kpis[$k]['balance']) }}</div>
            <div class="sub2">{{ __('cash.n_safes', ['n' => $kpis[$k]['n'], 'in' => $fmt($kpis[$k]['in']), 'out' => $fmt($kpis[$k]['out'])]) }}</div>
        </a>
    @endforeach
</div>

<div class="card" id="safes-table">
    <h3>🏦 {{ __('cash.safes') }} <span class="side">{{ __('cash.safes_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('cash.kind') }}</span>
            <select name="kind" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_kinds') }}</option>
                @foreach ($kinds as $k)
                    <option value="{{ $k }}" @selected(request('kind') === $k)>{{ __('cash.kind_'.$k) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('cash.branch') }}</span>
            <select name="branch_id" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_branches') }}</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected((string) request('branch_id') === (string) $b->id)>{{ $b->displayName() }}</option>
                @endforeach
            </select></label>
        {{-- ⚠️ `all => false`: الفاضي = الشهر الحالي (`DateRange` month) --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('gl.safes') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('cash.code') }}</th>
                <th style="text-align:start">{{ __('cash.name') }}</th>
                <th>{{ __('cash.kind') }}</th>
                <th style="text-align:start">{{ __('cash.branch') }}</th>
                <th style="text-align:start">{{ __('cash.custodian') }}</th>
                <th style="text-align:start">{{ __('cash.bank_name') }}</th>
                <th class="num">{{ __('cash.opening') }}</th>
                <th class="num">{{ __('cash.in') }}</th>
                <th class="num">{{ __('cash.out') }}</th>
                <th class="num">{{ __('cash.balance') }}</th>
                @if ($canPost)<th></th>@endif
            </tr>
            @forelse ($rows as $r)
                @php $s = $r['safe']; @endphp
                <tr @if (! $s->active) style="opacity:.6" @endif>
                    <td><b>{{ $s->code }}</b>
                        @if ($s->glAccount)
                            <div style="font-size:10px"><a href="{{ route('gl.accounts.show', $s->glAccount) }}">{{ $s->glAccount->code }}</a></div>
                        @endif
                    </td>
                    <td style="text-align:start">{{ $s->displayName() }}
                        @if (! $s->active) <span class="badge b-red">{{ __('cash.inactive') }}</span> @endif
                    </td>
                    <td><span class="badge {{ $s->kind === 'bank' ? 'b-blue' : 'b-green' }}">{{ __('cash.kind_'.$s->kind) }}</span></td>
                    <td style="text-align:start">{{ $s->branch?->displayName() ?? '—' }}
                        @if ($s->costCenter)
                            <div style="font-size:10px;color:var(--muted)">{{ $s->costCenter->displayName() }}</div>
                        @endif
                    </td>
                    <td style="text-align:start">{{ $s->custodian?->displayName() ?? '—' }}</td>
                    <td style="text-align:start">{{ $s->bank_name ?: '—' }}
                        @if ($s->account_no)
                            <div style="font-size:10px;color:var(--muted)" dir="ltr">{{ $s->account_no }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $fmt($r['opening']) }}</td>
                    <td class="num">{{ $fmt($r['in']) }}</td>
                    <td class="num">{{ $fmt($r['out']) }}</td>
                    <td class="num"><b class="{{ $r['balance'] < 0 ? 'neg' : '' }}">{{ $fmt($r['balance']) }}</b></td>
                    @if ($canPost)
                        <td>
                            <button class="btn sm" type="button" onclick="safeEdit({{ json_encode([
                                "id" => $s->id, "code" => $s->code, "name" => $s->name, "name_en" => $s->name_en, "kind" => $s->kind,
                                "branch_id" => $s->branch_id, "cost_center_id" => $s->cost_center_id, "custodian_user_id" => $s->custodian_user_id,
                                "gl_account_id" => $s->gl_account_id, "bank_name" => $s->bank_name, "account_no" => $s->account_no, "active" => $s->active,
                            ]) }})">✎ {{ __('cash.edit') }}</button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $cols }}" style="color:var(--muted);font-size:12px">{{ __('cash.no_safes') }}</td></tr>
            @endforelse
            @if ($rows->isNotEmpty())
                <tfoot><tr>
                    <td colspan="6" style="text-align:start">{{ __('cash.total') }} · {{ __('ui.rows_n', ['n' => number_format($rows->count())]) }}</td>
                    <td class="num">{{ $fmt($rows->sum('opening')) }}</td>
                    <td class="num">{{ $fmt($rows->sum('in')) }}</td>
                    <td class="num">{{ $fmt($rows->sum('out')) }}</td>
                    <td class="num">{{ $fmt($rows->sum('balance')) }}</td>
                    @if ($canPost)<td></td>@endif
                </tr></tfoot>
            @endif
        </table>
    </div>
</div>

{{-- ═══════════ إضافة / تعديل — الديالوج جوه content عن قصد ═══════════ --}}
@if ($canPost)
<dialog id="dlgSafeNew">
    <form class="dlg" method="POST" id="formNewSafe" action="{{ route('gl.safes.store') }}">
        @csrf
        <h4>🏦 {{ __('cash.new_safe') }}</h4>
        <div class="frow">
            <div>
                <label class="f">{{ __('cash.code') }} <b class="req-star">*</b></label>
                <input type="text" name="code" required maxlength="20" pattern="[A-Za-z0-9_\-]+" dir="ltr" value="{{ old('code') }}" style="width:100%">
                <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('cash.code_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('cash.kind') }} <b class="req-star">*</b></label>
                <select name="kind" required data-safe-kind onchange="safeKindToggle(this.form)" style="width:100%">
                    @foreach ($kinds as $k)
                        <option value="{{ $k }}" @selected(old('kind') === $k)>{{ __('cash.kind_'.$k) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @include('gl._safe_fields', ['mode' => 'new'])
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgSafeNew')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
{{-- الـaction بيتحط من `safeEdit()` — متسجّل في `FormStarIntegrityTest::DYNAMIC_FORMS` --}}
<dialog id="dlgSafeEdit">
    <form class="dlg" method="POST" id="formEditSafe">
        @csrf
        <h4>🏦 {{ __('cash.edit_safe') }}</h4>
        <div class="frow">
            <div>
                <label class="f">{{ __('cash.code') }}</label>
                <div id="edSafeCode" dir="ltr" style="padding:6px 0;font-weight:700"></div>
            </div>
            <div>
                <label class="f">{{ __('cash.kind') }} <b class="req-star">*</b></label>
                <select name="kind" required data-safe-kind onchange="safeKindToggle(this.form)" style="width:100%">
                    @foreach ($kinds as $k)
                        <option value="{{ $k }}">{{ __('cash.kind_'.$k) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @include('gl._safe_fields', ['mode' => 'edit'])
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgSafeEdit')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

<script>
// بيانات البنك بتبان للبنك بس — ولما تتخبّى بتتفضّى وماتبقاش required
function safeKindToggle(form) {
    var kind = form.querySelector('[data-safe-kind]').value;
    var box = form.querySelector('[data-bank-box]');
    var on = kind === 'bank';
    box.hidden = !on;
    box.querySelectorAll('input').forEach(function (i) { if (!on) i.value = ''; });
    form.querySelector('[name="bank_name"]').required = on;
}
function safeEdit(s) {
    var f = document.getElementById('formEditSafe');
    f.action = @json(url('gl/safes')) + '/' + s.id;
    document.getElementById('edSafeCode').textContent = s.code;
    ['name', 'name_en', 'kind', 'branch_id', 'cost_center_id', 'custodian_user_id', 'gl_account_id'].forEach(function (k) {
        f.querySelector('[name="' + k + '"]').value = s[k] == null ? '' : s[k];
    });
    safeKindToggle(f);
    f.querySelector('[name="bank_name"]').value = s.bank_name || '';
    f.querySelector('[name="account_no"]').value = s.account_no || '';
    f.querySelector('input[type="checkbox"][name="active"]').checked = !!s.active;
    openDlg('dlgSafeEdit');
}
safeKindToggle(document.getElementById('formNewSafe'));
@if ($errors->any() && old('code'))
    openDlg('dlgSafeNew');
@endif
</script>
@endif

@endsection
