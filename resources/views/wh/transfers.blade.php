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
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">🚚 {{ __('stock.transfers_total') }}</div>
        <div class="val">{{ $fmt($kpi['total']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">📦 {{ __('stock.in_transit') }}</div>
        <div class="val mid">{{ $fmt($kpi['sent']) }}</div>
        <div class="sub2">{{ $fmt($kpi['transit_units']) }} {{ __('stock.units') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">✅ {{ __('stock.received_count') }}</div>
        <div class="val pos">{{ $fmt($kpi['received']) }}</div>
    </div>
</div>

<div class="card">
    <h3>🚚 {{ __('stock.transfers') }}
        <span class="side">{{ __('stock.transfer_hint') }}</span></h3>

    @if (session('ok'))
        <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
    @endif

    <form class="searchbar" method="GET" style="margin-bottom:12px">
        <input type="search" name="q" value="{{ $f['q'] ?? '' }}" placeholder="🔍 {{ __('stock.transfer') }}…" style="max-width:220px">
        <select name="wh" style="min-width:160px">
            <option value="">{{ __('stock.all_warehouses') }}</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}" @selected((int) ($f['wh'] ?? 0) === $w->id)>{{ $w->displayName() }}</option>
            @endforeach
        </select>
        <select name="status">
            <option value="">{{ __('common.status') }}: {{ __('common.all') }}</option>
            <option value="sent" @selected(($f['status'] ?? '') === 'sent')>{{ __('stock.in_transit') }}</option>
            <option value="received" @selected(($f['status'] ?? '') === 'received')>{{ __('stock.received_count') }}</option>
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('wh.transfers') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap" style="max-height:64vh;overflow-y:auto">
        <table>
            <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
            <tr>
                <th>{{ __('stock.transfer') }}</th>
                <th>{{ __('stock.from_warehouse') }}</th>
                <th>{{ __('stock.to_warehouse') }}</th>
                <th>{{ __('stock.sent_on') }}</th>
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
                            {{ __('stock.sent_by') }}: {{ $t->sender?->name ?? '—' }}
                        </span>
                    </td>
                    <td>{{ $t->fromWarehouse?->displayName() ?? '—' }}</td>
                    <td>{{ $t->toWarehouse?->displayName() ?? '—' }}</td>
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
                        @if ($t->status === 'received')
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

                {{-- بنود التحويل تحت كل صف — المستلم يشوف اللي جايله من غير ما يفتح صفحة --}}
                <tr>
                    <td colspan="8" style="padding:6px 14px 12px">
                        @foreach ($t->items as $it)
                            <span class="badge b-gray" style="margin:2px 2px 0 0;display:inline-block">
                                {{ $it->product?->displayName() ?? '—' }}
                                · {{ $it->batch_no }}
                                · {{ $fmt($it->qty_sent) }}
                                @if ($it->expires_on)
                                    · {{ $it->expires_on->format('Y-m-d') }}
                                @endif
                            </span>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('stock.no_transfers') }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="pag">{{ $transfers->links('pagination::simple-default') }}</div>
</div>

@endsection
