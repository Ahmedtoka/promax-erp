@extends('layouts.system')

{{--
    أوامر التحويل — القايمة المتطورة (2026-08-06):
    KPIs من نفس الأساس المفلتر + فلاتر (رقم/مخزن/حالة) + بنود كل
    شحنة تحت صفها. الإنشاء بقى صفحة كاملة (`wh.transfers.new`)
    بالبحث بالصور — الدايالوج القديم اتشال.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);

    // ⚠️ **`isManager()` مش نفس `role:admin,manager`.** الأولى بتشمل
    // `branch_manager` كمان، وراوت إنشاء التحويل مقفول على أدمن
    // ومدير القناة بس.
    $u = auth()->user();
    $manager = $u->isAdmin() || $u->role === 'manager';
    $f = $filters;
@endphp

@section('title', __('stock.transfers'))

@section('actions')
    <a class="btn" href="{{ route('wh.index') }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    @if ($manager && \App\Support\Access::action(auth()->user(), 'act.wh.transfer'))
        <a class="btn gold" href="{{ route('wh.transfers.new') }}">+ {{ __('stock.new_transfer') }}</a>
    @endif
    {{-- ⚠️ أكشن مستقل (١٤/٨): ده بيسحب من عهدة مندوب مش من رف مخزن --}}
    @if ($manager && \App\Support\Access::action(auth()->user(), 'act.wh.van_transfer'))
        <a class="btn" href="{{ route('wh.transfers.van') }}">🔄 {{ __('stock.van_transfer') }}</a>
    @endif
@endsection

@section('content')

{{-- الكروت بتفلتر بالحالة على نفس باقي الفلاتر — الرقم = عدد صفوف الجدول بعد الدوسة (٢٢/٩).
     ⚠️ كارت «الميداني» نوعين (مندوب←مخزن + مندوب←مندوب) والفلتر بياخد نوع واحد، فبيودّي على الجدول. --}}
@php $kUrl = fn (array $x) => request()->fullUrlWithQuery($x + ['page' => null, 'export' => null]); @endphp
<div class="kpis">
    <a class="kpi {{ ($f['status'] ?? '') === '' ? 'on' : '' }}" href="{{ $kUrl(['status' => null]) }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">🚚 {{ __('stock.transfers_total') }}</div>
        <div class="val">{{ $fmt($kpi['total']) }}</div>
        {{-- الإجمالي = في الطريق + مستلمة + باقي الحالات — من نفس الأساس المفلتر (٢٢/٩) --}}
        @php $kOther = $kpi['total'] - $kpi['sent'] - $kpi['received']; @endphp
        <div class="sub2">{{ __('uid.tr_total_how') }}<br><span dir="ltr">{{ $fmt($kpi['total']) }} = {{ $fmt($kpi['sent']) }} + {{ $fmt($kpi['received']) }}@if ($kOther !== 0) + {{ __('uid.tr_other') }} {{ $fmt($kOther) }}@endif</span></div>
    </a>
    <a class="kpi {{ ($f['status'] ?? '') === 'sent' ? 'on' : '' }}" href="{{ $kUrl(['status' => 'sent']) }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">📦 {{ __('stock.in_transit') }}</div>
        <div class="val mid">{{ $fmt($kpi['sent']) }}</div>
        <div class="sub2">{{ __('uid.tr_sent_how') }} — {{ $fmt($kpi['transit_units']) }} {{ __('stock.units') }}</div>
    </a>
    <a class="kpi {{ ($f['status'] ?? '') === 'received' ? 'on' : '' }}" href="{{ $kUrl(['status' => 'received']) }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">✅ {{ __('stock.received_count') }}</div>
        <div class="val pos">{{ $fmt($kpi['received']) }}</div>
        <div class="sub2">{{ __('uid.tr_recv_how') }}</div>
    </a>
    {{-- التحويلات الميدانية (١٤/٨) — من نفس الأساس المفلتر --}}
    <a class="kpi {{ ($f['kind'] ?? '') === 'van' ? 'on' : '' }}" href="{{ $kUrl(['kind' => 'van', 'status' => null]) }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">🚐 {{ __('stock.van_transfers_count') }}</div>
        <div class="val">{{ $fmt($kpi['van']) }}</div>
        <div class="sub2">{{ __('uid.tr_van_how') }}</div>
    </a>
</div>

<div class="card">
    <h3>🚚 {{ __('stock.transfers') }}
        <span class="side">{{ __('stock.transfer_hint') }}</span></h3>

    @if (session('ok'))
        <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
    @endif

    <form class="searchbar" method="GET" style="margin-bottom:12px">
        <label class="fl"><span>{{ __('ui.l_search') }}</span>
            <input type="search" name="q" value="{{ $f['q'] ?? '' }}" placeholder="🔍 {{ __('stock.transfer') }}…"></label>
        <label class="fl"><span>{{ __('ui.l_warehouse') }}</span>
        <select name="wh">
            <option value="">{{ __('stock.all_warehouses') }}</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}" @selected((int) ($f['wh'] ?? 0) === $w->id)>{{ $w->displayName() }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status">
            <option value="">{{ __('stock.all_statuses') }}</option>
            <option value="sent" @selected(($f['status'] ?? '') === 'sent')>{{ __('stock.in_transit') }}</option>
            <option value="received" @selected(($f['status'] ?? '') === 'received')>{{ __('stock.received_count') }}</option>
        </select></label>
        {{-- فلتر الاتجاه (١٤/٨) --}}
        <label class="fl wide"><span>{{ __('ui.l_direction') }}</span>
        <select name="kind">
            <option value="">{{ __('stock.all_kinds') }}</option>
            {{-- «ميداني» = النوعين اللي من عربية مع بعض — ده اللي كارت الميداني بيعدّه (٢٢/٩) --}}
            <option value="van" @selected(($f['kind'] ?? '') === 'van')>🚐 {{ __('stock.van_transfers_count') }}</option>
            @foreach (\App\Models\StockTransfer::KINDS as $kCode => $kMeta)
                <option value="{{ $kCode }}" @selected(($f['kind'] ?? '') === $kCode)>
                    {{ $kMeta[0] }} {{ __('stock.kind_'.$kCode) }}
                </option>
            @endforeach
        </select></label>
        {{-- فلتر «من — إلى» على يوم الإرسال `sent_on` (٩/٩/٢٠٢٦) --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('wh.transfers') }}">{{ __('common.clear') }}</a>
        {{-- القايمة صفحات — ده بينزّل كل النتيجة المفلترة (٢٢/٩) --}}
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap" style="max-height:64vh;overflow-y:auto">
        <table>
            <thead>
            <tr>
                <th>{{ __('stock.transfer') }}</th>
                <th>{{ __('stock.kind') }}</th>
                <th>{{ __('stock.from_warehouse') }}</th>
                <th>{{ __('stock.to_warehouse') }}</th>
                <th data-nosum>{{ __('stock.sent_on') }}</th>
                <th class="num">{{ __('stock.qty_sent') }}</th>
                <th class="num">{{ __('stock.qty_received') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($transfers as $t)
                <tr>
                    <td class="num">
                        <a href="{{ route('wh.transfers.show', $t) }}"><b>{{ $t->number }}</b></a>
                        <br><span style="font-size:10.5px;color:var(--muted)">
                            {{ __('stock.transfer_created_by') }}:
                            {{ $t->creator?->displayName() ?? $t->sender?->displayName() ?? '—' }}
                        </span>
                    </td>
                    {{-- شارة الاتجاه (١٤/٨) — 🏭→🏭 / 🚐→🏭 / 🚐→🚐 --}}
                    <td><span class="badge {{ $t->kindClass() }}" style="white-space:nowrap">
                        {{ $t->kindArrow() }} {{ $t->kindLabel() }}</span></td>
                    {{-- الطرف بيفتح صفحته: المخزن على رصيده والمندوب على كارته (٢٢/٩) --}}
                    <td>
                        @if ($t->isVan() && $t->fromUser)<a href="{{ route('ops.rep', $t->fromUser) }}">{{ $t->fromLabel() }}</a>
                        @elseif (! $t->isVan() && $t->fromWarehouse)<a href="{{ route('erp.warehouses.stock', $t->fromWarehouse) }}">{{ $t->fromLabel() }}</a>
                        @else{{ $t->fromLabel() }}@endif
                    </td>
                    <td>
                        @if ($t->kindKey() === 'rep_rep' && $t->toUser)<a href="{{ route('ops.rep', $t->toUser) }}">{{ $t->toLabel() }}</a>
                        @elseif ($t->kindKey() !== 'rep_rep' && $t->toWarehouse)<a href="{{ route('erp.warehouses.stock', $t->toWarehouse) }}">{{ $t->toLabel() }}</a>
                        @else{{ $t->toLabel() }}@endif
                    </td>
                    <td>{{ $t->sent_on?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num">{{ $fmt($t->qtySent()) }}</td>
                    <td class="num">
                        @if ($t->status === 'received')
                            {{ $fmt($t->qtyReceived()) }}
                            @if ($t->hasVariance())
                                <br><span class="badge b-red">{{ __('stock.variance') }}
                                    {{ $fmt($t->qtyReceived() - $t->qtySent()) }}</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td><span class="badge {{ $t->statusClass() }}">{{ $t->statusLabel() }}</span></td>
                    <td class="num" style="white-space:nowrap">
                        {{-- ⚠️ **الطباعة متاحة دايماً.** الورقة الممضية ممكن
                             تضيع، واللي محتاج نسخة تانية مش هيعرف يطلّعها
                             من غير اللينك ده. --}}
                        <a class="btn sm" href="{{ route('wh.transfers.print', $t) }}">🖨️</a>
                        @if ($t->isVan())
                            {{-- ورقة واحدة للتحويل الميداني — مفيش استلام منفصل --}}
                        @elseif ($t->status === 'received')
                            <a class="btn sm" href="{{ route('wh.transfers.receipt_print', $t) }}">
                                {{ __('stock.receipt_note') }}
                            </a>
                        @else
                            <a class="btn sm gold" href="{{ route('wh.transfers.show', $t) }}">
                                {{ __('stock.receive_transfer') }} ←
                            </a>
                        @endif
                    </td>
                </tr>

                {{-- بنود التحويل تحت كل صف — المستلم يشوف اللي جايله من غير ما يفتح صفحة.
                     ⚠️ زوّدنا عمود الاتجاه فوق فالـcolspan بقى 9. --}}
                <tr>
                    <td colspan="9" style="padding:6px 14px 12px">
                        {{-- ⚠️ **السبب بيبان في القايمة نفسها.** مستند بيسحب
                             بضاعة من عربية وسببه مدفون جوه صفحة = محدش
                             هيقراه، والمراجعة بعد أسبوع بتبقى تخمين. --}}
                        @if ($t->reason)
                            <div style="font-size:11.5px;margin-bottom:4px">
                                <b>{{ __('stock.transfer_reason') }}:</b>
                                <span style="color:var(--muted)">{{ $t->reason }}</span>
                            </div>
                        @endif
                        @foreach ($t->items as $it)
                            <span class="badge b-gray" style="margin:2px 2px 0 0;display:inline-block">
                                @if ($it->product)<a href="{{ route('erp.products.show', $it->product) }}">{{ $it->product->displayName() }}</a>@else — @endif
                                · {{ $it->batch_no }}
                                · {{ $fmt($it->qty_sent) }}
                                @if ($it->expires_on)
                                    · {{ $it->expires_on->format('Y-m-d') }}
                                @endif
                                {{-- مصدر البضاعة اللي اتسحبت — طلب المالك --}}
                                @if ($it->sourceLabel())
                                    · {{ $it->sourceLabel() }}{{ $it->sourceRefLabel() ? ' '.$it->sourceRefLabel() : '' }}
                                @endif
                            </span>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('stock.no_transfers') }}
                </td></tr>
            @endforelse
            </tbody>
            {{-- إجمالي كل النتيجة المفلترة من السيرفر — مش الصفحة (٢٢/٩) --}}
            @if ($transfers->total() > 0)
                <tfoot><tr>
                    <td colspan="5"><b>{{ __('common.total') }}</b> <span class="s" style="color:var(--muted)">({{ __('ui.rows_n', ['n' => $transfers->total()]) }})</span></td>
                    <td class="num"><b>{{ $fmt($totals->sent) }}</b></td>
                    <td class="num"><b>{{ $fmt($totals->received) }}</b></td>
                    <td colspan="2"></td>
                </tr></tfoot>
            @endif
        </table>
    </div>

    @include('partials._pagination', ['p' => $transfers])
</div>

@endsection
