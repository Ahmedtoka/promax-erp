@extends('layouts.system')

{{--
    لوحة التوريد — كي أكاونت وأونلاين (تطوير كامل 2026-08-05):

    KPIs كفلاتر بضغطة ← فلاتر (بحث بالفرع/الرقم، قناة، سلسلة، موافقة،
    حالة، من/إلى) ← جدول بهيدر ثابت وتراك كامل (أنشأ/وافق/عدّل) ←
    الإنشاء من نفس المكان: يدوي سريع، تسليم PO للمندوب، أو رفع شيتات.
    الموافقات كلها بتروح للحسابات — والأزرار محكومة بأكشنز الصلاحيات.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`
    $manager = auth()->user()->canDecideOps();
    $f = $filters;
    // لينك KPI بيحافظ على باقي الفلاتر ويصفّر الصفحة
    $kpiLink = fn (array $set) => request()->fullUrlWithQuery(
        $set + ['status' => null, 'approval' => null, 'late' => null, 'page' => 1],
    );
@endphp

@section('title', __('ops.purchase_orders'))

{{-- طريقتين رسميتين بس للإنشاء (قرار المالك 2026-08-06):
     PO للمندوب أو رفع شيتات — الإنشاء اليدوي السريع اتشال --}}
@section('actions')
    @if ($manager && \App\Support\Access::action(auth()->user(), 'act.ka.create'))
        <a class="btn gold" href="{{ route('ops.po.handout') }}">📦 {{ __('nav.po_handout') }}</a>
        <a class="btn" href="{{ route('ops.po.import') }}">📊 {{ __('nav.po_import') }}</a>
        {{-- فحص الأسعار (٢٤/٨) — الأوامر اللي تسعيرتها اتغيرت --}}
        <a class="btn" href="{{ route('ops.pos.reprice.check') }}">🔍 {{ __('ops.po_check_btn') }}</a>
    @endif
@endsection

@section('content')

{{-- ═══ الأوفرفيو: كل كارت فلتر بضغطة — وبنفس فلاتر القايمة ═══ --}}
<div class="kpis">
    <a class="kpi" href="{{ $kpiLink([]) }}" style="text-decoration:none;color:inherit">
        <div class="lbl">🚚 {{ __('ops.total_orders') }}</div>
        <div class="val">{{ $fmt($kpi['total']) }}</div>
        <div class="sub2">{{ __('ops.kpi_value') }}: <b>{{ $fmt($kpi['value']) }}</b></div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['approval' => 'pending']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['approval'] ?? '') === 'pending' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">🔏 {{ __('enums.po_approval.pending') }}</div>
        <div class="val mid">{{ $fmt($kpi['pending']) }}</div>
        <div class="sub2">{{ __('ops.kpi_pending_hint') }}</div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['approval' => 'approved']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['approval'] ?? '') === 'approved' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">✅ {{ __('enums.po_approval.approved') }}</div>
        <div class="val pos">{{ $fmt($kpi['approved']) }}</div>
        <div class="sub2">{{ __('ops.kpi_approved_hint') }}</div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['approval' => 'rejected']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['approval'] ?? '') === 'rejected' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">⛔ {{ __('enums.po_approval.rejected') }}</div>
        <div class="val neg">{{ $fmt($kpi['rejected']) }}</div>
        <div class="sub2">{{ __('ops.kpi_rejected_hint') }}</div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['status' => 'delivered']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['status'] ?? '') === 'delivered' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">📬 {{ __('enums.po_status.delivered') }}</div>
        <div class="val pos">{{ $fmt($kpi['delivered']) }}</div>
        <div class="sub2">{{ __('ops.kpi_delivered_hint') }}</div>
    </a>
    <a class="kpi" href="{{ $kpiLink(['late' => 1]) }}"
       style="text-decoration:none;color:inherit;{{ ($f['late'] ?? false) ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">⏰ {{ __('ops.po_late') }}</div>
        <div class="val {{ $kpi['late'] > 0 ? 'neg' : 'pos' }}">{{ $fmt($kpi['late']) }}</div>
        <div class="sub2">{{ __('ops.kpi_late_hint') }}</div>
    </a>
</div>

<div class="card">
    <h3>🚚 {{ __('ops.orders') }} <span class="side">{{ $pos->total() }} {{ trans_choice('ops.order_count', $pos->total()) }}</span></h3>

    {{-- الفلاتر: بحث ← القناة ← السلسلة ← الموافقة ← الحالة ← من/إلى --}}
    <form class="searchbar" method="GET">
        @if ($f['late'] ?? false)<input type="hidden" name="late" value="1">@endif
        <input type="text" name="q" value="{{ $f['q'] ?? '' }}"
               placeholder="🔍 {{ __('ops.search_po_ph') }}" style="flex:1;min-width:220px">
        <select name="channel" style="min-width:130px">
            <option value="">{{ __('client.all_channels') }}</option>
            @foreach ($channels as $ch)
                <option value="{{ $ch->id }}" @selected((int) ($f['channel'] ?? 0) === $ch->id)>{{ $ch->displayName() }}</option>
            @endforeach
        </select>
        <select name="group" style="min-width:140px">
            <option value="">— {{ __('nav.chains') }} —</option>
            @foreach ($groups as $g)
                <option value="{{ $g->id }}" @selected((int) ($f['group'] ?? 0) === $g->id)>{{ $g->displayName() }}</option>
            @endforeach
        </select>
        <select name="approval" style="min-width:130px">
            <option value="">{{ __('ops.decision') }}: {{ __('common.all') }}</option>
            @foreach (['pending', 'approved', 'rejected'] as $a)
                <option value="{{ $a }}" @selected(($f['approval'] ?? '') === $a)>{{ __('enums.po_approval.'.$a) }}</option>
            @endforeach
        </select>
        <select name="status" style="min-width:120px">
            <option value="">{{ __('common.status') }}: {{ __('common.all') }}</option>
            @foreach (array_keys(\App\Models\PurchaseOrder::STATUSES) as $k)
                <option value="{{ $k }}" @selected(($f['status'] ?? '') === $k)>{{ __('enums.po_status.'.$k) }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $f['from'] ?? '' }}" style="width:135px" title="{{ __('common.from') }}">
        <input type="date" name="to" value="{{ $f['to'] ?? '' }}" style="width:135px" title="{{ __('common.to') }}">
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('ops.pos') }}">{{ __('common.clear') }}</a>
    </form>

    {{-- هيدر ثابت — القايمة طويلة والأعمدة بتضيع وانت نازل --}}
    <div class="tablewrap" style="max-height:62vh;overflow-y:auto">
        <table>
            <thead>
            <tr>
                <th>{{ __('ops.order') }}</th>
                <th>{{ __('ops.branch_client') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('ops.due_at') }}</th>
                <th class="num">{{ __('ops.units') }}</th>
                <th class="num">{{ __('stock.value') }}</th>
                <th>{{ __('ops.decision') }}</th>
                <th>{{ __('common.status') }}</th>
                <th>{{ __('ops.track') }}</th>
                <th style="width:110px"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($pos as $po)
                {{-- الصف كله بيفتح صفحة الأمر (١٢/٨) — عرض + تعديل من مكان
                     واحد. الأزرار في آخر عمود عليها stopPropagation. --}}
                <tr class="clickable" onclick="location.href='{{ route('ops.pos.show', $po) }}'" style="cursor:pointer">
                    {{-- ⚠️ **كلمة «replenishment» اتشالت** (طلب المالك ١٥/٨).
                         كانت بتتطبع خام (مصطلح داخلي مش مسمى معتمد)، وحتى
                         بعد ترجمتها الشارة ماكانتش بتضيف معلومة — الأمر
                         أمر توريد في كل الأحوال. مكانها بقى الأصل الحقيقي:
                         رقم طلب البضاعة · مين طلبه · مين وافق. --}}
                    @php $orig = $po->origin(); @endphp
                    <td class="num"><b>{{ $po->number }}</b>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $po->created_at->format('m-d') }}</span>
                        @if ($orig)
                            <br><a href="{{ route('ops.replenishments') }}" target="_blank" rel="noopener"
                                   onclick="event.stopPropagation()"
                                   style="font-size:10px;font-weight:800" dir="ltr">{{ $orig['number'] }}</a>
                            @if ($orig['requester'])
                                <div style="font-size:9.5px;color:var(--muted)">🙋 {{ $orig['requester'] }}</div>
                            @endif
                            @if ($orig['approver'])
                                <div style="font-size:9.5px;color:var(--muted)">🔏 {{ $orig['approver'] }}</div>
                            @else
                                <div style="font-size:9.5px;color:var(--red)">🔏 {{ __('ops.po_creator_unknown') }}</div>
                            @endif
                        @elseif ($po->source)
                            <br><span style="font-size:9.5px;color:var(--muted)">{{ $po->source }}</span>
                        @endif
                    </td>
                    <td>
                        <b>{{ $po->client?->fullName() ?? '—' }}</b>
                        @if ($po->client?->channel)
                            <br><span class="badge {{ $po->client->channel->badgeClass() }}" style="font-size:9.5px">{{ $po->client->channel->displayName() }}</span>
                        @endif
                    </td>
                    <td>{{ $po->courier?->displayName() ?? '—' }}</td>
                    {{-- معاد التوريد بالساعة + شارة التأخير --}}
                    <td style="font-size:11.5px">
                        {{ $po->due_at?->format('m-d h:i A') ?? $po->due_date?->format('m-d') ?? '—' }}
                        @if ($po->isLate())<br><span class="badge b-red" style="font-size:9.5px">⏰ {{ __('ops.po_late') }}</span>@endif
                    </td>
                    <td class="num">{{ $po->qtyTotal() }}
                        {{-- الفرق بعد التسليم: سلم إيه وناقص إيه --}}
                        @if ($po->status === 'delivered' && $po->qtyTotal() !== $po->deliveredQtyTotal())
                            <br><span style="font-size:10px;color:#B86E00;font-weight:800">{{ __('ops.po_delivered_qty') }} {{ $po->deliveredQtyTotal() }} · {{ __('ops.po_variance') }} {{ $po->qtyTotal() - $po->deliveredQtyTotal() }}</span>
                        @endif
                    </td>
                    <td class="num pos">{{ $fmt($po->total) }}</td>
                    <td>
                        @if ($po->needsApproval())
                            <span class="badge {{ $po->approvalClass() }}">{{ $po->approvalLabel() }}</span>
                            @if ($po->was_edited)<br><span class="badge b-orange" style="font-size:9.5px">{{ __('ops.edited') }}</span>@endif
                        @else
                            <span class="badge b-gray">—</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $po->statusClass() }}">{{ $po->statusLabel() }}</span>
                        @if ($po->delivered_at)<br><span style="font-size:10.5px;color:var(--muted)">{{ $po->delivered_at->format('h:i A') }}</span>@endif
                        {{-- إلغاء تسليم بسبب (١١/٨) — بيتمسح مع أول تسليم ناجح --}}
                        @if ($po->abort_reason)
                            <br><span style="font-size:10px;color:var(--red)" title="{{ $po->abort_reason }}">⛔ {{ __('ops.po_aborted_note') }}: {{ \Illuminate\Support\Str::limit($po->abort_reason, 40) }}</span>
                        @endif
                    </td>
                    {{-- التراك: مين أنشأ / وافق / عدّل — كله موثق.
                         ⚠️ الأيقونة لوحدها ماكانتش بتقول إيه ده (بلاغ
                         المالك ١٥/٨: «مفيش مين اللي عمله» مع إن الاسم
                         كان معروض) — بقى قدامها لابل صريح، والفاضي
                         بقى «غير مسجَّل» مش شرطة غامضة. --}}
                    <td style="font-size:10.5px;color:var(--muted);line-height:1.9">
                        @if ($po->creator)
                            <span title="{{ __('ops.po_created_by') }}">✍️ {{ __('ops.po_created_by') }}:</span>
                            <b style="color:var(--text)">{{ $po->creator->displayName() }}</b>
                            <span style="font-size:9.5px">· {{ $po->creator->roleLabel() }}</span>
                        @else
                            <span title="{{ __('ops.po_created_by') }}">✍️ {{ __('ops.po_created_by') }}:</span>
                            <span style="color:var(--red)">{{ __('ops.po_creator_unknown') }}</span>
                        @endif
                        @if ($po->approvedBy)<br>🔏 {{ $po->approvedBy->name }} <span dir="ltr">{{ $po->approved_at?->format('m-d h:i A') }}</span>@endif
                        @if ($po->editor)<br>✏️ {{ $po->editor->name }} <span dir="ltr">{{ $po->edited_at?->format('m-d h:i A') }}</span>@endif
                        {{-- ⚠️ **مدة التجهيز كانت بتتقاس ومحدش بيعرضها**
                             (٨/٨/٢٠٢٦). `prep_started_at` بيتكتب وقت
                             موافقة الحسابات و`prepMinutes()` موجودة —
                             والرقم ده هو اللي بيقول ليه المندوب اتأخر
                             قدام الفرع: المخزن أخد كام دقيقة يجهّز. --}}
                        @if ($po->prepMinutes() !== null)
                            <br>⏱️ {{ __('stock.prep_duration') }}:
                            <b>{{ $po->prepMinutes() }}</b> {{ __('common.minutes') }}
                        @endif
                        {{-- وصورة الأمر الأصلي لو مرفوعة — لينك مباشر --}}
                        @if ($po->imageUrl())
                            <br><a href="{{ $po->imageUrl() }}" target="_blank">🖼️ {{ __('ops.po_image') }}</a>
                        @endif
                    </td>
                    <td onclick="event.stopPropagation()">
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <a class="btn sm" href="{{ route('ops.pos.show', $po) }}" title="{{ __('ops.po_view') }}">👁</a>
                            <a class="btn sm" href="{{ route('ops.po.print', $po) }}" target="_blank" title="{{ __('ops.print') }}">🖨️</a>
                            @if ($po->sheet_path)
                                {{-- شيت السلسلة الأصلي — المرجع المحفوظ وقت الرفع --}}
                                <a class="btn sm" href="{{ route('ops.po.sheet', $po) }}" title="{{ __('ops.po_sheet') }}">📎</a>
                            @endif
                            {{-- ⚠️ المعتمد بقى يتعدّل كمان (١٠/٨) طالما التسليم
                                 مابدأش والبضاعة ماخرجتش — والتعديل بيرجّعه
                                 لطابور الحسابات. نفس شرط poEditable في الكنترولر. --}}
                            @if (($po->approval_status === 'pending'
                                    || ($po->approval_status === 'approved' && $po->status === 'pending' && $po->pickOrder?->status !== 'handed'))
                                && \App\Support\Access::action(auth()->user(), 'act.ka.edit'))
                                <a class="btn sm" href="{{ route('ops.po.edit', $po) }}" title="{{ __('ops.po_edit') }}"
                                   @if ($po->approval_status === 'approved') onclick="return confirm(@js(__('ops.po_edit_back_confirm')))" @endif>✏️</a>
                            @endif
                            @if ($manager && $po->status === 'pending' && ! $po->needsApproval())
                                <button class="btn sm" onclick="assignPo({{ $po->id }}, '{{ $po->number }}')">{{ __('ops.assign') }}</button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.no_orders') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials._pagination', ['p' => $pos])
</div>

@if ($manager)
<dialog id="dlgAssign">
    <form class="dlg" method="POST" id="formAssign">
        @csrf
        <h4 id="aTitle">{{ __('ops.assign_to_driver') }}</h4>
        <label class="f">{{ __('ops.driver') }}</label>
        <select name="assigned_to" required style="width:100%">
            @foreach ($couriers as $co)<option value="{{ $co->id }}">{{ $co->name }} ({{ $co->code }})</option>@endforeach
        </select>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgAssign')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('ops.assign') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<script>
function assignPo(id, number) {
    const tpl = {!! json_encode(__('ops.assign_order', ['number' => '#N#']), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
    document.getElementById('aTitle').textContent = tpl.replace('#N#', number);
    document.getElementById('formAssign').action = '{{ url('ops/pos') }}/' + id + '/assign';
    openDlg('dlgAssign');
}
</script>
@endsection
