@extends('layouts.system')

{{--
    موافقات أوامر التوريد — جدول كولابس (2026-08-06):

    كل أمر صف واحد منسق، الضغط عليه بيفتح تفاصيله جوه الجدول (الأصناف
    والمتاح وتعديل الكميات والقرار). أكشنز سريعة من بره: موافقة فورية،
    رفض (بسبب إجباري)، تعديل، طباعة. «آخر القرارات» اتشالت — المتقرر
    فيه مكانه صفحة أوامر التوريد بفلاترها.

    القرار بيتاخد على أساس **رصيد الفرع**: موافقة ← أمر تجهيز بينزل
    المخزن فوراً. تعديل ← ملحوظة إجبارية وبيتبلغ مدير القناة. رفض ←
    سبب إجباري والأمر بيتلغي.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $f = $filters;
@endphp

@section('title', __('ops.po_approvals'))

@section('actions')
    @if ($pending->isNotEmpty())
        {{-- طباعة الكل: كل المعروض حالياً — أمر في صفحة --}}
        <a class="btn" target="_blank"
           href="{{ route('ops.po.print.batch', ['ids' => $pending->pluck('id')->join(',')]) }}">🖨️ {{ __('ops.print_all') }} ({{ $pending->count() }})</a>
        @if (\App\Support\Access::action(auth()->user(), 'act.ka.decide'))
            {{-- موافقة جماعية على المعروض — كل أمر في ترانزاكشن لوحده --}}
            <form method="POST" action="{{ route('ops.po.decide.all') }}" style="display:inline"
                  onsubmit="return confirm(@js(__('ops.approve_all_confirm', ['count' => $pending->count()])))">
                @csrf
                @foreach ($pending as $po)
                    <input type="hidden" name="ids[]" value="{{ $po->id }}">
                @endforeach
                <button class="btn gold" type="submit">✅ {{ __('ops.approve_all') }} ({{ $pending->count() }})</button>
            </form>
        @endif
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🔏 {{ __('ops.po_approvals') }}
        <span class="side">{{ __('ops.po_expand_hint') }}</span>
        <span class="badge b-orange" style="margin-inline-start:auto">{{ $pending->count() }} {{ __('ops.po_pending_count') }}</span>
    </h3>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    {{-- ═══ الفلاتر والترتيب — سيرفر سايد ═══ --}}
    <form method="GET" action="{{ route('ops.po.approvals') }}" class="searchbar" style="margin-bottom:12px">
        <input type="text" name="q" value="{{ $f['q'] ?? '' }}"
               placeholder="🔍 {{ __('ops.search_po_ph') }}" style="flex:1;min-width:200px">
        <select name="group" style="min-width:150px">
            <option value="">— {{ __('nav.chains') }} —</option>
            @foreach ($groups as $g)
                <option value="{{ $g->id }}" @selected(($f['group'] ?? '') == $g->id)>{{ $g->displayName() }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $f['from'] ?? '' }}" style="width:135px" title="{{ __('common.from') }}">
        <input type="date" name="to" value="{{ $f['to'] ?? '' }}" style="width:135px" title="{{ __('common.to') }}">
        <select name="sort">
            <option value="" @selected(($f['sort'] ?? '') === '')>⏰ {{ __('ops.sort_due') }}</option>
            <option value="value" @selected(($f['sort'] ?? '') === 'value')>💰 {{ __('ops.sort_value') }}</option>
            <option value="newest" @selected(($f['sort'] ?? '') === 'newest')>🕐 {{ __('ops.sort_newest') }}</option>
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('ops.po.approvals') }}">{{ __('common.clear') }}</a>
    </form>

    @if ($pending->isEmpty())
        <div class="alert"><span>✅</span><span>{{ __('ops.po_no_pending') }}</span></div>
    @else
    @php
        $canDecide = \App\Support\Access::action(auth()->user(), 'act.ka.decide');
        $canEdit = \App\Support\Access::action(auth()->user(), 'act.ka.edit');
    @endphp
    <div class="tablewrap" style="max-height:70vh;overflow-y:auto">
        <table>
            <thead style="position:sticky;top:0;z-index:5;background:var(--card, #fff);box-shadow:0 1px 0 var(--border)">
                <tr>
                    <th>{{ __('stock.pick_order') }}</th>
                    <th>{{ __('ops.branch_client') }}</th>
                    <th>{{ __('ops.rep') }}</th>
                    <th>{{ __('stock.warehouse') }}</th>
                    <th>{{ __('ops.due_at') }}</th>
                    <th class="num">{{ __('ops.items') }}</th>
                    <th class="num">{{ __('ops.po_amount') }}</th>
                    <th class="num">{{ __('ops.branch_balance') }}</th>
                    <th style="width:170px"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pending as $po)
                    @php
                        $client = $po->client;
                        // فيه صنف المتاح فيه أقل من المطلوب؟ — تحذير على الصف من بره
                        $short = $po->items->contains(fn ($it) =>
                            (int) ($shelfAvail[$po->warehouse_id][$it->product_id] ?? 0) < (int) $it->qty);
                    @endphp
                    {{-- الصف الرئيسي — الضغط عليه بيفتح التفاصيل --}}
                    <tr onclick="poToggle({{ $po->id }})" style="cursor:pointer" id="poRow{{ $po->id }}">
                        <td>
                            <b>{{ $po->number }}</b>
                            @if ($po->source)<div style="font-size:10px;color:var(--muted)" dir="ltr">{{ $po->source }}</div>@endif
                        </td>
                        <td>
                            <b style="font-size:12.5px">{{ $client?->fullName() ?? '—' }}</b>
                            @if ($client?->channel)<div style="font-size:10px;color:var(--muted)">{{ $client->channel->displayName() }}</div>@endif
                        </td>
                        <td class="s">{{ $po->courier?->name ?? '—' }}</td>
                        <td class="s">{{ $po->warehouse?->displayName() ?? '—' }}</td>
                        <td class="s">
                            {{ $po->due_at?->format('Y-m-d H:i') ?? '—' }}
                            @if ($po->isLate())<span class="badge b-red" style="font-size:9.5px">⏰ {{ __('ops.po_late') }}</span>@endif
                        </td>
                        <td class="num">
                            {{ $po->items->count() }}
                            @if ($short)<span class="badge b-red" style="font-size:9.5px" title="{{ __('ops.avail_in_wh') }}">⚠️</span>@endif
                        </td>
                        <td class="num"><b style="color:var(--royal-blue)">{{ $fmt($po->payable()) }}</b></td>
                        <td class="num">
                            @if ($client)
                                <b class="{{ (float) $client->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt(abs((float) $client->balance)) }}</b>
                            @else
                                —
                            @endif
                        </td>
                        {{-- الأكشنز السريعة — stopPropagation عشان الصف مايتفتحش معاها --}}
                        <td class="num" onclick="event.stopPropagation()">
                            @if ($canDecide)
                                <button class="btn sm gold" type="button" title="{{ __('ops.quick_approve') }}"
                                        onclick="poQuickApprove({{ $po->id }})">✅</button>
                                <button class="btn sm red" type="button" title="{{ __('common.reject') }}"
                                        onclick="poRejectDlg({{ $po->id }}, @json($po->number))">⛔</button>
                            @endif
                            @if ($canEdit)
                                <button class="btn sm" type="button" title="{{ __('ops.po_edit') }}"
                                        onclick="poToggle({{ $po->id }}, true)">✏️</button>
                            @endif
                            <a class="btn sm" href="{{ route('ops.po.print', $po) }}" target="_blank"
                               title="{{ __('ops.print') }}" onclick="event.stopPropagation()">🖨️</a>
                        </td>
                    </tr>
                    {{-- صف التفاصيل — مقفول افتراضياً --}}
                    <tr id="poDet{{ $po->id }}" hidden>
                        <td colspan="9" style="background:var(--card2, #fafafa);padding:14px 16px">
                            <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:10px;font-size:12.5px">
                                <div>{{ __('ops.by') }}: <b>{{ $po->creator?->name ?? '—' }}</b></div>
                                @if ($client)
                                    <div>
                                        {{ __('ops.branch_balance') }}:
                                        @if ((float) $client->balance > 0)
                                            <b style="color:#B00020">{{ __('ops.branch_owes') }} {{ $fmt($client->balance) }}</b>
                                        @else
                                            <b style="color:#1B7A3D">{{ __('ops.branch_credit') }} {{ $fmt(abs((float) $client->balance)) }}</b>
                                        @endif
                                    </div>
                                    <div style="color:var(--muted)">
                                        {{ __('ops.after_po') }}: <b style="color:var(--ink)">{{ $fmt((float) $client->balance + $po->payable()) }}</b>
                                    </div>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('ops.po.decide', $po) }}"
                                  id="poForm{{ $po->id }}" onsubmit="return poCheckNote(event, {{ $po->id }})">
                                @csrf
                                <div class="tablewrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>{{ __('stock.item') }}</th>
                                                <th class="num">{{ __('ops.qty_requested') }}</th>
                                                <th class="num">{{ __('ops.avail_in_wh') }}</th>
                                                <th class="num" style="width:130px">{{ __('ops.qty_after_edit') }}</th>
                                                <th class="num">{{ __('ops.price') }}</th>
                                                <th class="num">{{ __('common.total') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($po->items as $it)
                                                <tr>
                                                    <td><b>{{ $it->product?->displayName() ?? '—' }}</b></td>
                                                    <td class="num">{{ $fmt($it->qty) }}
                                                        @if ($bd = $it->product?->packBreakdown((int) $it->qty))
                                                            <div style="font-size:10px;color:var(--muted)">{{ $bd }}</div>
                                                        @endif
                                                    </td>
                                                    {{-- المتاح على أرفف مخزن الأمر — نفس مصدر الحجز.
                                                         الأحمر = الموافقة هترفض قبل ما تدوس --}}
                                                    @php $av = (int) ($shelfAvail[$po->warehouse_id][$it->product_id] ?? 0); @endphp
                                                    <td class="num {{ $av < (int) $it->qty ? 'neg' : 'pos' }}"><b>{{ $fmt($av) }}</b></td>
                                                    {{-- التعديل بالقطع — فاضية يعني سيبها زي ما هي، و0 يعني شيل الصنف --}}
                                                    <td class="num">
                                                        <input type="number" min="0" max="999999" style="width:100%"
                                                               name="qty_edit[{{ $it->id }}]" data-orig="{{ (int) $it->qty }}"
                                                               placeholder="{{ $fmt($it->qty) }}">
                                                    </td>
                                                    <td class="num">{{ number_format((float) $it->price, 2) }}</td>
                                                    <td class="num">{{ $fmt($it->total) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px">
                                    @if ($canEdit)<a class="btn" href="{{ route('ops.po.edit', $po) }}">✏️ {{ __('ops.po_edit') }}</a>@endif
                                    <a class="btn" href="{{ route('ops.po.print', $po) }}" target="_blank">🖨️ {{ __('ops.print') }}</a>
                                    <input type="text" name="note" id="poNote{{ $po->id }}" maxlength="500" style="flex:1;min-width:220px"
                                           placeholder="{{ __('ops.decision_note_ph') }}">
                                    @if ($canDecide)
                                        {{-- زرارين بنفس الفورم — قيمة decision بتحدد المسار --}}
                                        <button class="btn gold" name="decision" value="approved">✅ {{ __('ops.approve_and_prep') }}</button>
                                        <button class="btn red" name="decision" value="rejected">⛔ {{ __('common.reject') }}</button>
                                    @endif
                                </div>
                                <div style="font-size:11px;color:var(--muted);margin-top:6px">{{ __('ops.po_edit_hint') }}</div>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

{{-- ═══ دايالوج الرفض السريع — السبب إجباري ═══ --}}
<dialog id="dlgPoReject">
    <form class="dlg" method="POST" id="poRejectForm" action="">
        @csrf
        <input type="hidden" name="decision" value="rejected">
        <h4>⛔ {{ __('common.reject') }} <span id="poRejectNo" dir="ltr"></span></h4>
        <label class="f">{{ __('ops.decision_note_ph') }} <b class="req-star">*</b></label>
        <textarea name="note" id="poRejectNote" required maxlength="500" rows="3" style="width:100%"></textarea>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgPoReject')">{{ __('common.cancel') }}</button>
            <button class="btn red" type="submit">⛔ {{ __('common.reject') }}</button>
        </div>
    </form>
</dialog>

@endsection

@section('scripts')
<script>
const DECIDE_URL = @js(route('ops.po.decide', ['purchaseOrder' => '__ID__']));
const MSG_APPROVE = @js(__('ops.po_approve_confirm'));
const MSG_NOTE_EDIT = @js(__('ops.po_note_required_edit'));
const MSG_NOTE_REJECT = @js(__('ops.po_note_required_reject'));

/** فتح/قفل تفاصيل الأمر — focusNote بيوقّف على خانة الملحوظة (زرار ✏️) */
function poToggle(id, focusNote) {
    const det = document.getElementById('poDet' + id);
    det.hidden = focusNote ? false : !det.hidden;

    if (!det.hidden && focusNote) {
        document.getElementById('poNote' + id).focus();
    }
}

/** فيه كمية اتعدلت عن الأصلية في فورم الأمر ده؟ */
function poHasEdits(id) {
    return [...document.querySelectorAll('#poForm' + id + ' input[name^="qty_edit"]')]
        .some(inp => inp.value !== '' && Number(inp.value) !== Number(inp.dataset.orig));
}

/**
 * حارس الفورم: الرفض أو التعديل من غير ملحوظة مايتبعتش.
 * (السيرفر بيتأكد تاني — ده بس عشان المستخدم مايستناش round trip)
 */
function poCheckNote(e, id) {
    const decision = e.submitter?.value;
    const note = document.getElementById('poNote' + id).value.trim();

    if (decision === 'rejected' && !note) {
        alert(MSG_NOTE_REJECT);
        document.getElementById('poNote' + id).focus();
        return false;
    }

    if (decision === 'approved') {
        if (poHasEdits(id) && !note) {
            alert(MSG_NOTE_EDIT);
            document.getElementById('poNote' + id).focus();
            return false;
        }

        return confirm(MSG_APPROVE);
    }

    return true;
}

/** موافقة سريعة من الصف — بتبعت فورم التفاصيل نفسه بقرار approved */
function poQuickApprove(id) {
    // لو فيه تعديلات متكتبة جوه، نفس حراسة الملحوظة بتشتغل
    if (poHasEdits(id) && !document.getElementById('poNote' + id).value.trim()) {
        poToggle(id, true);
        alert(MSG_NOTE_EDIT);
        return;
    }

    if (!confirm(MSG_APPROVE)) return;

    const form = document.getElementById('poForm' + id);
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'decision';
    hidden.value = 'approved';
    form.appendChild(hidden);
    form.submit();
}

/** رفض سريع — دايالوج بسبب إجباري */
function poRejectDlg(id, number) {
    document.getElementById('poRejectForm').action = DECIDE_URL.replace('__ID__', id);
    document.getElementById('poRejectNo').textContent = number;
    document.getElementById('poRejectNote').value = '';
    openDlg('dlgPoReject');
    document.getElementById('poRejectNote').focus();
}

{{-- الفاليديشن رجّعت بخطأ؟ نفتح كل التفاصيل عشان الرسالة تبان في سياقها --}}
@if ($errors->any())
document.querySelectorAll('[id^="poDet"]').forEach(det => det.hidden = false);
@endif
</script>
@endsection
