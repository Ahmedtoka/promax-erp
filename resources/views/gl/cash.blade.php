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
    $cols = $canPost ? 11 : 10;
    // L4-D3: نوع الخزنة المطلوب على كل طرف — العهدة بتقبل خزنة نقدية على طرف الخزنة بس
    $safeSides = $safeKinds + ['rep_advance' => ['cash', null], 'rep_return' => [null, 'cash']];
    $transferKinds = ['safe_transfer', 'bank_transfer'];
@endphp

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    @if ($canPost)
        <button class="btn gold" type="button" onclick="openDlg('dlgCash')">＋ {{ __('gl.new_cash') }}</button>
    @endif
@endsection

@section('content')

<div class="kpis">
    @foreach ($kinds as $k)
        {{-- (٢٢/٩) كل كارت فلتر على نوع الحركة — دوسة تانية بتشيله --}}
        <a class="kpi" href="{{ request()->fullUrlWithQuery(['kind' => request('kind') === $k ? null : $k, 'page' => null]) }}#gl-table" title="{{ __('ui.click_to_filter') }}">
            <div class="lbl">{{ __('gl.kind_'.$k) }}</div>
            <div class="val">{{ $fmt($byKind[$k] ?? 0) }}</div>
            <div class="sub2">{{ __('uib.gl_cash_kind_sub') }}</div>
        </a>
    @endforeach
    {{-- (٢٢/٩) الرقمين المركّبين اللي المحاسب بيدوّر عليهم: صافي الخزنة وصافي اللي مع المناديب في الفترة --}}
    @php
        $cDep = (float) ($byKind['deposit'] ?? 0); $cWd = (float) ($byKind['withdraw'] ?? 0);
        $cAdv = (float) ($byKind['rep_advance'] ?? 0); $cRet = (float) ($byKind['rep_return'] ?? 0);
    @endphp
    <a class="kpi" style="grid-column:span 2" href="#gl-table">
        <div class="lbl">{{ __('uib.gl_safe_net') }}</div>
        <div class="val {{ $cWd + $cRet - $cDep - $cAdv < 0 ? 'neg' : 'pos' }}" dir="ltr">{{ $fmt($cWd + $cRet - $cDep - $cAdv) }}</div>
        <div class="sub2">@include('erp._eq', ['total' => $cWd + $cRet - $cDep - $cAdv, 'zeros' => true, 'parts' => [[__('gl.kind_withdraw'), $cWd], [__('gl.kind_rep_return'), $cRet], [__('gl.kind_deposit'), $cDep, '-'], [__('gl.kind_rep_advance'), $cAdv, '-']]])</div>
    </a>
    <a class="kpi" style="grid-column:span 2" href="{{ request()->fullUrlWithQuery(['kind' => 'rep_advance', 'page' => null]) }}#gl-table">
        <div class="lbl">{{ __('uib.gl_rep_net') }}</div>
        <div class="val" dir="ltr">{{ $fmt($cAdv - $cRet) }}</div>
        <div class="sub2">@include('erp._eq', ['total' => $cAdv - $cRet, 'zeros' => true, 'parts' => [[__('gl.kind_rep_advance'), $cAdv], [__('gl.kind_rep_return'), $cRet, '-']]])</div>
    </a>
</div>

<div class="card" id="gl-table">
    <h3>🏦 {{ __('gl.cash') }} <span class="side">{{ __('gl.cash_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('gl.kind') }}</span>
            <select name="kind" onchange="this.form.submit()">
                <option value="">{{ __('ui.all_of', ['x' => __('uib.kinds')]) }}</option>
                @foreach ($kinds as $k)
                    <option value="{{ $k }}" @selected(request('kind') === $k)>{{ __('gl.kind_'.$k) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('gl.status') }}</span>
            <select name="status" onchange="this.form.submit()">
                <option value="">{{ __('ui.all_of', ['x' => __('uib.statuses')]) }}</option>
                <option value="posted" @selected(request('status') === 'posted')>{{ __('gl.status_posted') }}</option>
                <option value="void" @selected(request('status') === 'void')>{{ __('gl.status_void') }}</option>
            </select></label>
        {{-- ⚠️ `all => false`: الفترة الفاضية هنا = الشهر الحالي (`DateRange` month) مش «كل الفترات» --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('gl.cash') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('gl.number') }}</th>
                <th>{{ __('common.date') }}</th>
                <th style="text-align:start">{{ __('gl.kind') }}</th>
                <th style="text-align:start">{{ __('gl.rep') }}</th>
                <th style="text-align:start">{{ __('cash.from_safe') }}</th>
                <th style="text-align:start">{{ __('cash.to_safe') }}</th>
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
                            <a href="{{ route('ops.rep', $m->user->id) }}">{{ $m->user->displayName() }}</a>
                            <div style="font-size:10px;color:var(--muted)">{{ $m->user->code }}</div>
                        @else — @endif
                    </td>
                    @foreach ([$m->fromSafe, $m->toSafe] as $sf)
                        <td style="text-align:start">
                            @if ($sf)
                                {{ $sf->displayName() }}
                                <div style="font-size:10px;color:var(--muted)">{{ $sf->code }}</div>
                            @else — @endif
                        </td>
                    @endforeach
                    <td style="font-size:11px">
                        {{ $m->reference ?: '—' }}
                        @if ($m->costCenter)
                            <div style="font-size:10px;color:var(--muted)">{{ $m->costCenter->displayName() }}</div>
                        @endif
                    </td>
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
            {{-- الجدول مقسم صفحات — الإجمالي من السيرفر على الفلتر كله (المرحّل بس) = إجمالي ملف الإكسيل --}}
            @if ($rows->total() > 0)
                <tfoot><tr>
                    <td colspan="9" style="text-align:start">{{ __('gl.total_posted') }} · {{ __('ui.rows_n', ['n' => number_format($rows->total())]) }}</td>
                    <td class="num">{{ $fmt($total) }}</td>
                    @if ($canPost)<td></td>@endif
                </tr></tfoot>
            @endif
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
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
                <select name="kind" id="cmKind" required onchange="cmToggleRep();cmToggleSafes()" style="width:100%">
                    @foreach ($kinds as $k)
                        <option value="{{ $k }}" @selected(old('kind') === $k)>{{ __('gl.kind_'.$k) }}</option>
                    @endforeach
                </select>
            </div>
            <div id="cmRepBox" hidden>
                <label class="f">{{ __('gl.rep') }} <b class="req-star">*</b></label>
                <select name="user_id" data-req-repkind style="width:100%">
                    <option value="">{{ __('ui.choose', ['x' => __('ui.l_rep')]) }}</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected(old('user_id') == $r->id)>{{ $r->displayName() }} ({{ $r->code }})</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="frow" style="margin-top:10px">
            @foreach (['from' => 0, 'to' => 1] as $side => $i)
                <div id="cmSafeBox_{{ $side }}" data-side="{{ $i }}" hidden>
                    <label class="f">{{ __('cash.'.$side.'_safe') }} <b class="req-star" data-safe-star hidden>*</b></label>
                    <select name="{{ $side }}_safe_id" style="width:100%">
                        <option value="">{{ __('cash.choose_safe') }}</option>
                        @foreach ($safes as $sf)
                            <option value="{{ $sf->id }}" data-kind="{{ $sf->kind }}" @selected(old($side.'_safe_id') == $sf->id)>
                                {{ $sf->displayName() }} ({{ $sf->code }}){{ $sf->branch ? ' · '.$sf->branch->displayName() : '' }}
                            </option>
                        @endforeach
                    </select>
                    <div data-safe-hint style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('cash.safe_optional') }}</div>
                </div>
            @endforeach
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('cash.cost_center') }}</label>
            <select name="cost_center_id" style="width:100%">
                <option value="">{{ __('cash.center_auto') }}</option>
                @foreach ($costCenters as $cc)
                    <option value="{{ $cc->id }}" @selected(old('cost_center_id') == $cc->id)>{{ $cc->code }} — {{ $cc->displayName() }}</option>
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

// خانات الخزن (L4-D3): كل طرف بيبان لو النوع بيقبل خزنة عليه، وبيعرض
// الخزن من النوع المطابق بس (خزنة/بنك)؛ إجباري في التحويلات بس
var CM_SAFE_SIDES = @json($safeSides);
var CM_TRANSFER = @json($transferKinds);
function cmToggleSafes() {
    var sel = document.getElementById('cmKind');
    if (!sel) return;
    var sides = CM_SAFE_SIDES[sel.value] || [null, null];
    var req = CM_TRANSFER.indexOf(sel.value) !== -1;
    ['from', 'to'].forEach(function (side, i) {
        var box = document.getElementById('cmSafeBox_' + side);
        if (!box) return;
        var want = sides[i];
        box.hidden = !want;
        var field = box.querySelector('select');
        field.required = !!want && req;
        box.querySelector('[data-safe-star]').hidden = !(want && req);
        box.querySelector('[data-safe-hint]').hidden = req;
        Array.prototype.forEach.call(field.options, function (o) {
            if (!o.value) return;
            var ok = o.getAttribute('data-kind') === want;
            o.hidden = !ok; o.disabled = !ok;
        });
        if (!want || (field.selectedOptions[0] && field.selectedOptions[0].disabled)) field.value = '';
    });
}
cmToggleSafes();
</script>

@endsection
