@extends('layouts.system')

{{--
    سندات المصروف (١١/٩/٢٠٢٦) — المحاسب بيسجّل المصروف وصورته،
    والقيد بيتولّد لوحده. مافيش تعديل على سند مرحّل: الغِ وسجّل تاني،
    عشان أثر التدقيق يفضل على الورق زي ما هو في الدفتر.

    Lane 4 (L4-D7..D9): بند وخزنة ومركز تكلفة وإيصال، ودورة
    «مستني اعتماد ← معتمد ← مدفوع». القيد بيتولّد عند الدفع بس.
--}}

@section('title', __('gl.expenses'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $me = auth()->user();
    $canPost = \App\Support\Access::action($me, 'act.gl.post');
    $canApprove = \App\Support\Access::action($me, 'act.gl.expense_approve');
    $hasActions = $canPost || $canApprove;
    // عمود الأزرار بيظهر لصاحب صلاحية بس — والـcolspan لازم يمشي معاه
    $cols = $hasActions ? 12 : 11;
    $statusBadge = ['pending' => 'b-orange', 'approved' => 'b-blue', 'rejected' => 'b-red', 'posted' => 'b-green', 'void' => 'b-gray'];
@endphp

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    <a class="btn sm" href="{{ route('gl.expense_categories') }}">🏷️ {{ __('cash.categories') }}</a>
    @if ($canPost)
        <button class="btn gold" type="button" onclick="openDlg('dlgExpense')">＋ {{ __('gl.new_expense') }}</button>
    @endif
@endsection

@section('content')

@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $errors->first() }}</span></div>
@endif

<div class="kpis">
    {{-- (٢٢/٩) الإجمالي = المرحّل بس فبيفلتر عليه، والعدد هو الجدول تحت --}}
    <a class="kpi" href="{{ request()->fullUrlWithQuery(['status' => 'posted', 'page' => null]) }}#gl-table">
        <div class="lbl">{{ __('gl.total_posted') }}</div>
        <div class="val neg">{{ $fmt($total) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $range->fromValue() }} → {{ $range->toValue() }}</div>
    </a>
    <a class="kpi" href="#gl-table">
        <div class="lbl">{{ __('gl.count') }}</div>
        <div class="val">{{ number_format($count) }}</div>
        <div class="sub2">{{ __('gl.expenses_sub') }}</div>
    </a>
    {{-- L4-D7: طلبات الاعتماد على كل التواريخ — الضغط بيفلتر المعلّق وبيوسّع الفترة من أقدم معلّق لأحدث واحد (الفترة الفاضية هنا = الشهر الحالي، فمش كفاية نفضّيها) --}}
    <a class="kpi" href="{{ route('gl.expenses', ['status' => 'pending', 'from' => $pendingFrom, 'to' => $pendingTo]) }}#gl-table" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">{{ __('cash.exp_pending_kpi') }}</div>
        <div class="val {{ $pending > 0 ? 'neg' : '' }}">{{ number_format($pending) }}</div>
        <div class="sub2">{{ __('cash.exp_pending_sub', ['amount' => $fmt($pendingAmount), 'n' => number_format($awaitingPay)]) }}</div>
    </a>
</div>

<div class="card" id="gl-table">
    <h3>🧾 {{ __('gl.expenses') }} <span class="side">{{ __('cash.exp_flow_hint') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('cash.exp_category') }}</span>
            <select name="category" onchange="this.form.submit()">
                <option value="">{{ __('cash.exp_all_categories') }}</option>
                @foreach ($categories as $c)
                    <option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->code }} · {{ $c->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('gl.account') }}</span>
            <select name="account" onchange="this.form.submit()">
                <option value="">{{ __('ui.all_of', ['x' => __('uib.accounts')]) }}</option>
                @foreach ($accounts as $a)
                    <option value="{{ $a->id }}" @selected(request('account') == $a->id)>{{ $a->code }} · {{ $a->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('gl.status') }}</span>
            <select name="status" onchange="this.form.submit()">
                <option value="">{{ __('ui.all_of', ['x' => __('uib.statuses')]) }}</option>
                @foreach ($statuses as $st)
                    <option value="{{ $st }}" @selected(request('status') === $st)>{{ __('cash.xstatus_'.$st) }}</option>
                @endforeach
            </select></label>
        {{-- ⚠️ `all => false`: الفترة الفاضية هنا = الشهر الحالي (`DateRange` month) مش «كل الفترات» --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('gl.expenses') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                {{-- ⚠️ data-nosum — رقم السند والمرجع نصوص مش مبالغ --}}
                <th data-nosum>{{ __('gl.number') }}</th>
                <th>{{ __('common.date') }}</th>
                <th style="text-align:start">{{ __('cash.exp_category') }}</th>
                <th style="text-align:start">{{ __('gl.account') }}</th>
                <th style="text-align:start">{{ __('gl.payee') }}</th>
                <th>{{ __('gl.paid_from') }}</th>
                <th style="text-align:start">{{ __('cash.exp_center') }}</th>
                <th data-nosum>{{ __('gl.reference') }}</th>
                <th>{{ __('gl.attachment') }}</th>
                <th>{{ __('gl.status') }}</th>
                <th class="num">{{ __('gl.amount') }}</th>
                @if ($hasActions)<th></th>@endif
            </tr>
            @forelse ($rows as $x)
                <tr>
                    <td><b>{{ $x->number }}</b></td>
                    <td class="num" style="font-size:11px">{{ $x->date?->format('Y-m-d') }}</td>
                    <td style="text-align:start">
                        @if ($x->category)
                            {{ $x->category->displayName() }}
                            <div style="font-size:10px;color:var(--muted)">{{ __('cash.cat_type_'.$x->category->type) }}</div>
                        @else — @endif
                        @if ($x->rep || $x->vehicle)
                            <div style="font-size:10px">⛽ {{ $x->rep?->displayName() }} {{ $x->vehicle ? '· '.$x->vehicle->plate : '' }}</div>
                        @endif
                    </td>
                    <td style="text-align:start">@if ($x->account)<a href="{{ route('gl.accounts.show', $x->account_id) }}">{{ $x->account->displayName() }}</a>@else — @endif</td>
                    <td style="text-align:start">
                        @if ($x->payeeSupplier)<a href="{{ route('erp.suppliers.show', $x->payeeSupplier->id) }}">{{ $x->payeeLabel() }}</a>
                        @elseif ($x->payeeUser && in_array($x->payeeUser->role, \App\Models\User::FIELD_WORK_ROLES, true))<a href="{{ route('ops.rep', $x->payeeUser->id) }}">{{ $x->payeeLabel() }}</a>
                        @else{{ $x->payeeLabel() }}@endif
                        @if ($x->note)
                            <div style="font-size:10.5px;color:var(--muted)">{{ $x->note }}</div>
                        @endif
                        @if ($x->decision_note)
                            <div style="font-size:10.5px;color:var(--muted)">💬 {{ $x->decision_note }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $x->paid_from === 'bank' ? 'b-blue' : 'b-gray' }}">{{ $x->safe ? $x->safe->displayName() : __('gl.paid_'.$x->paid_from) }}</span>
                        @if ($x->paidFromUser)
                            <div style="font-size:10px"><a href="{{ route('ops.rep', $x->paidFromUser->id) }}">{{ $x->paidFromUser->displayName() }}</a></div>
                        @endif
                    </td>
                    <td style="text-align:start;font-size:11px">{{ $x->costCenter?->displayName() ?? '—' }}</td>
                    <td style="font-size:11px">{{ $x->reference ?: '—' }}</td>
                    <td>
                        @if ($x->attachment_path)
                            <a class="btn sm" href="{{ asset('storage/'.$x->attachment_path) }}" target="_blank">📎 {{ __('common.view') }}</a>
                        @elseif ($x->status === 'posted')
                            <span class="badge b-orange">{{ __('cash.exp_no_receipt') }}</span>
                        @else — @endif
                    </td>
                    <td>
                        <span class="badge {{ $statusBadge[$x->status] ?? 'b-gray' }}">{{ __('cash.xstatus_'.$x->status) }}</span>
                    </td>
                    <td class="num"><b>{{ $fmt($x->amount) }}</b></td>
                    @if ($hasActions)
                        <td style="white-space:nowrap">
                            @if ($canApprove && $x->status === 'pending')
                                <form method="POST" action="{{ route('gl.expenses.approve', $x) }}" style="display:inline"
                                      onsubmit="return confirm(@js(__('cash.exp_confirm_approve')))">
                                    @csrf
                                    <button class="btn sm green" type="submit">✓ {{ __('cash.exp_approve') }}</button>
                                </form>
                            @endif
                            @if ($canPost && $x->status === 'approved')
                                <form method="POST" action="{{ route('gl.expenses.pay', $x) }}" style="display:inline"
                                      onsubmit="return confirm(@js(__('cash.exp_confirm_pay')))">
                                    @csrf
                                    <button class="btn sm gold" type="submit">💵 {{ __('cash.exp_pay') }}</button>
                                </form>
                            @endif
                            @if ($canApprove && in_array($x->status, ['pending', 'approved'], true))
                                <button class="btn sm" type="button" onclick="expReject({{ json_encode(['url' => route('gl.expenses.reject', $x), 'number' => $x->number]) }})">⨯ {{ __('cash.exp_reject') }}</button>
                            @endif
                            @if ($canPost && in_array($x->status, ['posted', 'pending', 'approved'], true))
                                <form method="POST" action="{{ route('gl.expenses.void', $x) }}" style="display:inline"
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
            {{-- الجدول مقسم صفحات — الإجمالي من السيرفر على الفلتر كله = كارت الإجمالي = ملف الإكسيل --}}
            @if ($rows->total() > 0)
                <tfoot><tr>
                    <td colspan="10" style="text-align:start">{{ __('gl.total_posted') }} · {{ __('ui.rows_n', ['n' => number_format($count)]) }}</td>
                    <td class="num">{{ $fmt($total) }}</td>
                    @if ($hasActions)<td></td>@endif
                </tr></tfoot>
            @endif
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

{{-- ═══════════ رفض — الـaction بيتحط من `expReject()` (متسجّل في `FormStarIntegrityTest::DYNAMIC_FORMS`) ═══════════ --}}
@if ($canApprove)
<dialog id="dlgExpReject">
    <form class="dlg" method="POST" id="formExpReject">
        @csrf
        <h4>⨯ {{ __('cash.exp_reject_title') }} <span id="expRejectNo" dir="ltr"></span></h4>
        <div>
            <label class="f">{{ __('cash.exp_reject_note') }} <b class="req-star">*</b></label>
            <input type="text" name="note" required maxlength="250" style="width:100%">
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgExpReject')">{{ __('common.cancel') }}</button>
            <button class="btn red" type="submit">{{ __('cash.exp_reject') }}</button>
        </div>
    </form>
</dialog>
@endif

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
                <input type="number" name="amount" required step="0.01" min="0.01" max="99999999" dir="ltr" value="{{ old('amount') }}" style="width:100%">
            </div>
        </div>

        {{-- L4-D9: البند والخزنة والمركز والإيصال إجباريين على الشاشة، والسيرفر عنده بدايل للسكريبتات القديمة --}}
        <div style="margin-top:10px">
            <label class="f">{{ __('cash.exp_category') }} <b class="req-star">*</b></label>
            <select name="category_id" id="expCategory" required onchange="expToggleCategory()" style="width:100%">
                <option value="">{{ __('ui.choose', ['x' => __('cash.exp_category')]) }}</option>
                @foreach ($categories->groupBy('type') as $type => $list)
                    <optgroup label="{{ __('cash.cat_type_'.$type) }}">
                        @foreach ($list as $c)
                            <option value="{{ $c->id }}" data-type="{{ $c->type }}" data-vehicle="{{ $c->links_vehicle ? 1 : 0 }}"
                                    @selected(old('category_id') == $c->id)>{{ $c->displayName() }}{{ $c->account ? ' — '.$c->account->code.' '.$c->account->displayName() : '' }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('cash.exp_category_hint') }}</div>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('cash.exp_account_optional') }}</label>
            <select name="account_id" style="width:100%">
                <option value="">{{ __('cash.exp_account_from_category') }}</option>
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
            <div id="expSafeBox">
                <label class="f">{{ __('cash.exp_safe') }} <b class="req-star">*</b></label>
                <select name="safe_id" id="expSafe" onchange="expSafeCenter()" style="width:100%">
                    <option value="">{{ __('ui.choose', ['x' => __('cash.exp_safe')]) }}</option>
                    @foreach ($safes as $sf)
                        <option value="{{ $sf->id }}" data-kind="{{ $sf->kind }}" data-center="{{ $sf->cost_center_id }}"
                                @selected(old('safe_id') == $sf->id)>{{ $sf->code }} · {{ $sf->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div id="expRepBox" hidden>
                <label class="f">{{ __('gl.rep') }} <b class="req-star">*</b></label>
                <select name="paid_from_user_id" data-req-repcash style="width:100%">
                    <option value="">{{ __('ui.choose', ['x' => __('ui.l_rep')]) }}</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected(old('paid_from_user_id') == $r->id)>{{ $r->displayName() }} ({{ $r->code }})</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('cash.exp_center') }} <b class="req-star">*</b></label>
            <select name="cost_center_id" id="expCenter" required style="width:100%">
                <option value="">{{ __('ui.choose', ['x' => __('cash.exp_center')]) }}</option>
                @foreach ($centers as $cc)
                    <option value="{{ $cc->id }}" @selected(old('cost_center_id') == $cc->id)>{{ $cc->code }} · {{ $cc->displayName() }}</option>
                @endforeach
            </select>
        </div>

        {{-- بنزين (links_vehicle) — المندوب والعربية --}}
        <div id="expFuelBox" style="margin-top:10px" hidden>
            <div class="frow">
                <div>
                    <label class="f">{{ __('cash.exp_rep') }}</label>
                    <select name="rep_id" style="width:100%">
                        <option value="">{{ __('ui.choose', ['x' => __('ui.l_rep')]) }}</option>
                        @foreach ($reps as $r)
                            <option value="{{ $r->id }}" @selected(old('rep_id') == $r->id)>{{ $r->displayName() }} ({{ $r->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="f">{{ __('cash.exp_vehicle') }}</label>
                    <select name="vehicle_id" style="width:100%">
                        <option value="">{{ __('ui.choose', ['x' => __('cash.exp_vehicle')]) }}</option>
                        @foreach ($vehicles as $v)
                            <option value="{{ $v->id }}" @selected(old('vehicle_id') == $v->id)>{{ $v->plate }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('cash.exp_fuel_hint') }}</div>
        </div>

        {{-- بند أصل (L4-D10) — الأصل بيتسجل عند الدفع --}}
        <div id="expAssetBox" style="margin-top:10px" hidden>
            <div class="frow">
                <div>
                    <label class="f">{{ __('cash.exp_asset_name') }}</label>
                    <input type="text" name="asset_name" maxlength="120" value="{{ old('asset_name') }}" style="width:100%">
                </div>
                <div>
                    <label class="f">{{ __('cash.exp_asset_life') }}</label>
                    <input type="number" name="asset_life_months" min="1" max="600" step="1" dir="ltr" value="{{ old('asset_life_months') }}" style="width:100%">
                </div>
                <div>
                    <label class="f">{{ __('cash.exp_asset_start') }}</label>
                    <input type="date" name="asset_start_date" value="{{ old('asset_start_date') }}" style="width:100%">
                </div>
            </div>
            <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('cash.exp_asset_hint') }}</div>
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
                <option value="">{{ __('ui.choose', ['x' => __('ui.l_supplier')]) }}</option>
                @foreach ($suppliers as $s)
                    <option value="{{ $s->id }}" @selected(old('payee_supplier_id') == $s->id)>{{ $s->displayName() }}</option>
                @endforeach
            </select>
        </div>

        <div id="expPayeeEmployee" style="margin-top:10px" hidden>
            <label class="f">{{ __('gl.payee_employee') }} <b class="req-star">*</b></label>
            <select name="payee_user_id" data-req-payee style="width:100%">
                <option value="">{{ __('ui.choose', ['x' => __('ui.l_user')]) }}</option>
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
            <label class="f">{{ __('cash.exp_receipt') }} <b class="req-star">*</b></label>
            <input type="file" name="attachment" required accept="image/*,application/pdf" style="width:100%">
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
// نقدية المندوب: خانة المندوب بتبان والخزنة بتختفي (المندوب مش خزنة) —
// غير كده الخزنة بتبان ومتفلترة على نوع «مدفوع من» (خزنة / بنك).
// الخانة اللي بتتخبّى بتتفضّى، عشان اختيار قديم مايتبعتش مع السند.
function expToggleRep() {
    var sel = document.getElementById('expPaidFrom');
    var box = document.getElementById('expRepBox');
    var safeBox = document.getElementById('expSafeBox');
    var safe = document.getElementById('expSafe');
    if (!sel || !box) return;
    var on = sel.value === 'rep_cash';
    box.hidden = !on;
    var field = box.querySelector('[name="paid_from_user_id"]');
    if (field) { field.required = on; if (!on) field.value = ''; }
    if (safeBox && safe) {
        safeBox.hidden = on;
        safe.required = !on;
        var kind = sel.value === 'bank' ? 'bank' : 'cash';
        Array.prototype.forEach.call(safe.options, function (o) {
            if (!o.value) return;
            o.hidden = o.getAttribute('data-kind') !== kind;
        });
        var cur = safe.options[safe.selectedIndex];
        if (on || (cur && cur.value && cur.hidden)) safe.value = '';
    }
}

// الخزنة بتملا مركز التكلفة بمركزها لو المركز لسه فاضي
function expSafeCenter() {
    var safe = document.getElementById('expSafe');
    var center = document.getElementById('expCenter');
    if (!safe || !center || center.value) return;
    var o = safe.options[safe.selectedIndex];
    var c = o ? o.getAttribute('data-center') : '';
    if (c) center.value = c;
}

// البند بيحدد الخانات الإضافية: البنزين (مندوب + عربية) والأصل (اسم + عمر + بداية)
function expToggleCategory() {
    var sel = document.getElementById('expCategory');
    if (!sel) return;
    var o = sel.options[sel.selectedIndex];
    var fuel = !!o && o.getAttribute('data-vehicle') === '1';
    var asset = !!o && o.getAttribute('data-type') === 'asset';
    [['expFuelBox', fuel], ['expAssetBox', asset]].forEach(function (p) {
        var box = document.getElementById(p[0]);
        if (!box) return;
        box.hidden = !p[1];
        if (!p[1]) box.querySelectorAll('input,select').forEach(function (f) { f.value = ''; });
    });
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

// الرفض بسبب — الفورم واحد والـaction بيتحط للسند المختار
function expReject(d) {
    var f = document.getElementById('formExpReject');
    if (!f) return;
    f.action = d.url;
    var n = document.getElementById('expRejectNo');
    if (n) n.textContent = d.number;
    f.querySelector('[name="note"]').value = '';
    openDlg('dlgExpReject');
}

expToggleRep();
expToggleCategory();
expTogglePayee();
@if ($canPost && $errors->any() && old('amount') !== null)
    openDlg('dlgExpense');
@endif
</script>

@endsection
