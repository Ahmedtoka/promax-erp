@extends('layouts.system')

{{--
    سندات المصروف (١١/٩/٢٠٢٦) — المحاسب بيسجّل المصروف وصورته،
    والقيد بيتولّد لوحده. مافيش تعديل على سند مرحّل: الغِ وسجّل تاني،
    عشان أثر التدقيق يفضل على الورق زي ما هو في الدفتر.
--}}

@section('title', __('gl.expenses'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
@endphp

@section('actions')
    @if ($canPost)
        <button class="btn gold" type="button" onclick="openDlg('dlgExpense')">＋ {{ __('gl.new_expense') }}</button>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('gl.total_posted') }}</div>
        <div class="val neg">{{ $fmt($total) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $range->fromValue() }} → {{ $range->toValue() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('gl.count') }}</div>
        <div class="val">{{ number_format($rows->total()) }}</div>
        <div class="sub2">{{ __('gl.expenses_sub') }}</div>
    </div>
</div>

<div class="card">
    <h3>🧾 {{ __('gl.expenses') }} <span class="side">{{ __('gl.expenses_sub') }}</span></h3>

    <form method="GET" class="frow" style="margin-bottom:12px" data-noprint>
        <div>
            <label class="f">{{ __('gl.account') }}</label>
            <select name="account" onchange="this.form.submit()">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($accounts as $a)
                    <option value="{{ $a->id }}" @selected(request('account') == $a->id)>{{ $a->code }} · {{ $a->displayName() }}</option>
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
                {{-- ⚠️ data-nosum — رقم السند والمرجع نصوص مش مبالغ --}}
                <th data-nosum>{{ __('gl.number') }}</th>
                <th>{{ __('common.date') }}</th>
                <th style="text-align:start">{{ __('gl.account') }}</th>
                <th style="text-align:start">{{ __('gl.payee') }}</th>
                <th>{{ __('gl.paid_from') }}</th>
                <th data-nosum>{{ __('gl.reference') }}</th>
                <th>{{ __('gl.attachment') }}</th>
                <th>{{ __('gl.status') }}</th>
                <th class="num">{{ __('gl.amount') }}</th>
                @if ($canPost)<th></th>@endif
            </tr>
            @forelse ($rows as $x)
                <tr>
                    <td><b>{{ $x->number }}</b></td>
                    <td class="num" style="font-size:11px">{{ $x->date?->format('Y-m-d') }}</td>
                    <td style="text-align:start">{{ $x->account?->displayName() ?? '—' }}</td>
                    <td style="text-align:start">
                        {{ $x->payeeLabel() }}
                        @if ($x->note)
                            <div style="font-size:10.5px;color:var(--muted)">{{ $x->note }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $x->paid_from === 'bank' ? 'b-blue' : 'b-gray' }}">{{ __('gl.paid_'.$x->paid_from) }}</span>
                        @if ($x->paidFromUser)
                            <div style="font-size:10px;color:var(--muted)">{{ $x->paidFromUser->displayName() }}</div>
                        @endif
                    </td>
                    <td style="font-size:11px">{{ $x->reference ?: '—' }}</td>
                    <td>
                        @if ($x->attachment_path)
                            <a class="btn sm" href="{{ asset('storage/'.$x->attachment_path) }}" target="_blank">📎 {{ __('common.view') }}</a>
                        @else — @endif
                    </td>
                    <td>
                        <span class="badge {{ $x->status === 'posted' ? 'b-green' : 'b-red' }}">{{ __('gl.status_'.$x->status) }}</span>
                    </td>
                    <td class="num"><b>{{ $fmt($x->amount) }}</b></td>
                    @if ($canPost)
                        <td>
                            @if ($x->status === 'posted')
                                <form method="POST" action="{{ route('gl.expenses.void', $x) }}" style="display:inline"
                                      onsubmit="return confirm('{{ __('gl.confirm_void') }}')">
                                    @csrf
                                    <button class="btn sm" type="submit">✕ {{ __('gl.void') }}</button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="10" style="color:var(--muted);font-size:12px">{{ __('gl.no_rows') }}</td></tr>
            @endforelse
        </table>
    </div>

    <div style="margin-top:12px">{{ $rows->links() }}</div>
</div>

{{-- ═══════════ سند مصروف جديد — الديالوج جوه content عن قصد ═══════════ --}}
@if ($canPost)
<dialog id="dlgExpense">
    <form class="dlg" method="POST" enctype="multipart/form-data" action="{{ route('gl.expenses.store') }}">
        @csrf
        <h4>🧾 {{ __('gl.new_expense') }}</h4>

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

        <div style="margin-top:10px">
            <label class="f">{{ __('gl.account') }} <b class="req-star">*</b></label>
            <select name="account_id" required style="width:100%">
                <option value="">— {{ __('common.pick') }} —</option>
                @foreach ($accounts as $a)
                    <option value="{{ $a->id }}" @selected(old('account_id') == $a->id)>{{ $a->code }} · {{ $a->displayName() }}</option>
                @endforeach
            </select>
        </div>

        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('gl.paid_from') }} <b class="req-star">*</b></label>
                <select name="paid_from" id="expPaidFrom" required onchange="expToggleRep()" style="width:100%">
                    @foreach (\App\Models\Expense::PAID_FROM as $p)
                        <option value="{{ $p }}" @selected(old('paid_from') === $p)>{{ __('gl.paid_'.$p) }}</option>
                    @endforeach
                </select>
            </div>
            <div id="expRepBox" hidden>
                <label class="f">{{ __('gl.rep') }} <b class="req-star">*</b></label>
                <select name="paid_from_user_id" data-req-repcash style="width:100%">
                    <option value="">— {{ __('common.pick') }} —</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected(old('paid_from_user_id') == $r->id)>{{ $r->displayName() }} ({{ $r->code }})</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('gl.payee_type') }} <b class="req-star">*</b></label>
                <select name="payee_type" id="expPayeeType" required onchange="expTogglePayee()" style="width:100%">
                    <option value="other" @selected(old('payee_type', 'other') === 'other')>{{ __('gl.payee_other') }}</option>
                    <option value="supplier" @selected(old('payee_type') === 'supplier')>{{ __('gl.payee_supplier') }}</option>
                    <option value="employee" @selected(old('payee_type') === 'employee')>{{ __('gl.payee_employee') }}</option>
                </select>
            </div>
            <div id="expPayeeOther">
                <label class="f">{{ __('gl.payee') }} <b class="req-star">*</b></label>
                <input type="text" name="payee_name" data-req-payee maxlength="120" value="{{ old('payee_name') }}" style="width:100%">
            </div>
        </div>

        <div id="expPayeeSupplier" style="margin-top:10px" hidden>
            <label class="f">{{ __('gl.payee_supplier') }} <b class="req-star">*</b></label>
            <select name="payee_supplier_id" data-req-payee style="width:100%">
                <option value="">— {{ __('common.pick') }} —</option>
                @foreach ($suppliers as $s)
                    <option value="{{ $s->id }}" @selected(old('payee_supplier_id') == $s->id)>{{ $s->displayName() }}</option>
                @endforeach
            </select>
        </div>

        <div id="expPayeeEmployee" style="margin-top:10px" hidden>
            <label class="f">{{ __('gl.payee_employee') }} <b class="req-star">*</b></label>
            <select name="payee_user_id" data-req-payee style="width:100%">
                <option value="">— {{ __('common.pick') }} —</option>
                @foreach ($employees as $e)
                    <option value="{{ $e->id }}" @selected(old('payee_user_id') == $e->id)>{{ $e->displayName() }} ({{ $e->code }})</option>
                @endforeach
            </select>
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
            <button class="btn" type="button" onclick="closeDlg('dlgExpense')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

<script>
// خانة المندوب بتبان لما المصروف يتدفع من نقدية مندوب بس — ولما
// تتخبّى بتتفضّى، عشان اختيار قديم مايتبعتش مع سند خزنة
function expToggleRep() {
    var sel = document.getElementById('expPaidFrom');
    var box = document.getElementById('expRepBox');
    if (!sel || !box) return;
    var on = sel.value === 'rep_cash';
    box.hidden = !on;
    var field = box.querySelector('[name="paid_from_user_id"]');
    if (field) { field.required = on; if (!on) field.value = ''; }
}

// نوع المستفيد بيبدّل خانة واحدة من تلاتة — والاتنين التانيين بيتفضّوا
function expTogglePayee() {
    var sel = document.getElementById('expPayeeType');
    if (!sel) return;
    var map = { other: 'expPayeeOther', supplier: 'expPayeeSupplier', employee: 'expPayeeEmployee' };
    Object.keys(map).forEach(function (k) {
        var box = document.getElementById(map[k]);
        if (!box) return;
        var on = sel.value === k;
        box.hidden = !on;
        var field = box.querySelector('[data-req-payee]');
        if (field) { field.required = on; if (!on) field.value = ''; }
    });
}

expToggleRep();
expTogglePayee();
</script>

@endsection
