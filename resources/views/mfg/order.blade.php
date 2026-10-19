@extends('layouts.system')

@section('title', __('mfg.order_heading', ['number' => $order->number]))

@section('content')
@php
    $badge = ['planned' => 'b-gray', 'in_progress' => 'b-blue', 'finished' => 'b-orange', 'closed' => 'b-green', 'cancelled' => 'b-red'];
    $user = auth()->user();
    $canManage = \App\Support\Access::action($user, 'act.mfg.orders_manage');
    $canBom = $order->bom && \App\Support\Access::allows($user, 'mfg.boms.show');
    $num = fn ($v, $dp = 3) => rtrim(rtrim(number_format((float) $v, $dp, '.', ''), '0'), '.') ?: '0';
    $hasShortage = $req->contains(fn ($r) => $r['shortage'] > 0.0005);
@endphp

<div class="card">
    <h3>📋 {{ __('mfg.order_heading', ['number' => $order->number]) }}
        <span class="side"><span class="badge {{ $badge[$order->status] ?? 'b-gray' }}">{{ $order->statusLabel() }}</span></span>
    </h3>

    <div class="frow" style="margin-bottom:0">
        <div><label class="f">{{ __('mfg.col_product') }}</label><div>{{ $order->product?->code }} — {{ $order->product?->displayName() ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_bom') }}</label><div>
            @if ($canBom)
                <a href="{{ route('mfg.boms.show', $order->bom) }}">{{ __('mfg.bom_version_n', ['version' => $order->bom->version]) }}</a>
            @else
                {{ $order->bom ? __('mfg.bom_version_n', ['version' => $order->bom->version]) : '—' }}
            @endif
        </div></div>
        <div><label class="f">{{ __('mfg.col_line') }}</label><div>{{ $order->line?->displayName() ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_batch_no') }}</label><div class="num"><b>{{ $order->batch_no }}</b></div></div>
    </div>
    <div class="frow" style="margin:12px 0 0">
        <div><label class="f">{{ __('mfg.col_planned_qty') }}</label><div class="num">{{ number_format($order->planned_qty) }} {{ $order->product?->unitLabel() }}</div></div>
        <div><label class="f">{{ __('mfg.col_planned_on') }}</label><div class="num">{{ $order->planned_on?->format('Y-m-d') ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.f_raw_warehouse') }}</label><div>{{ $order->rawWarehouse?->displayName() ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.f_fg_warehouse') }}</label><div>{{ $order->warehouse?->displayName() ?? '—' }}</div></div>
    </div>
    <div class="frow" style="margin:12px 0 0">
        <div><label class="f">{{ __('mfg.col_started_at') }}</label><div class="num">{{ $order->started_at?->format('Y-m-d H:i') ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_finished_at') }}</label><div class="num">{{ $order->finished_at?->format('Y-m-d H:i') ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_hours') }}</label><div class="num">{{ $order->hours !== null ? number_format((float) $order->hours, 2) : '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_output_qty') }}</label><div class="num">{{ $order->output_qty !== null ? number_format($order->output_qty) : '—' }}</div></div>
    </div>
    @if ($order->notes)
        <div class="s" style="margin-top:12px">📝 {{ $order->notes }}</div>
    @endif
    @if ($order->shortage_requested_at)
        <div class="alert" style="margin-top:12px">
            {{ __('mfg.shortage_requested_on', ['at' => $order->shortage_requested_at->format('Y-m-d H:i')]) }}
            @if ($order->purchase_request_id) — {{ __('mfg.purchase_request_n', ['id' => $order->purchase_request_id]) }}@endif
        </div>
    @endif

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px" data-noprint>
        <a class="btn" href="{{ route('mfg.orders') }}">← {{ __('mfg.back_to_orders') }}</a>

        @if ($canManage && $order->status === 'planned')
            <form method="POST" action="{{ route('mfg.orders.start', $order) }}"
                  onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('mfg.confirm_start')) }})">
                @csrf
                <button class="btn gold" type="submit">▶ {{ __('mfg.btn_start') }}</button>
            </form>
        @endif

        @if ($canManage && $order->status === 'in_progress')
            <button class="btn green" type="button" onclick="openDlg('dlgFinish')">⏹ {{ __('mfg.btn_finish') }}</button>
        @endif

        @if ($canManage && in_array($order->status, ['planned', 'in_progress'], true))
            <form method="POST" action="{{ route('mfg.orders.cancel', $order) }}"
                  onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('mfg.confirm_cancel')) }})">
                @csrf
                <button class="btn red" type="submit">✖ {{ __('mfg.btn_cancel_order') }}</button>
            </form>
        @endif
    </div>
</div>

<div class="card">
    <h3>🥣 {{ __('mfg.order_lines_title') }}
        @if ($canManage && $hasShortage && $order->isOpen())
            <span class="side" data-noprint>
                <form method="POST" action="{{ route('mfg.orders.shortage', $order) }}" style="display:inline"
                      onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('mfg.confirm_shortage')) }})">
                    @csrf
                    <button class="btn sm gold" type="submit">🛒 {{ __('mfg.btn_request_purchase') }}</button>
                </form>
            </span>
        @endif
    </h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('mfg.order_lines_hint') }}</div>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('mfg.col_material') }}</th>
                <th data-nosum>{{ __('mfg.col_unit') }}</th>
                <th class="num">{{ __('mfg.col_std_qty_planned') }}</th>
                <th class="num">{{ __('mfg.col_issued_net') }}</th>
                <th class="num">{{ __('mfg.col_remaining') }}</th>
                <th class="num">{{ __('mfg.col_available') }}</th>
                <th class="num">{{ __('mfg.col_shortage') }}</th>
            </tr>
            @forelse ($req as $r)
                <tr>
                    <td>{{ $r['product']?->code }} — {{ $r['product']?->displayName() ?? '—' }}</td>
                    <td>{{ $r['product']?->unitLabel() }}</td>
                    <td class="num">{{ $num($r['std_qty']) }}</td>
                    <td class="num">{{ $num($r['issued_net']) }}</td>
                    <td class="num">{{ $num($r['remaining']) }}</td>
                    <td class="num">{{ $num($r['available']) }}</td>
                    <td class="num">
                        @if ($r['shortage'] > 0.0005)
                            <span class="badge b-red">{{ $num($r['shortage']) }}</span>
                        @else
                            <span style="color:var(--muted)">0</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_order_lines') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- ═══ مهمة ٥: لوحة صرف الخامات (صرف المعياري / صرف زيادة / مرتجع للمخزن) هنا ═══ --}}

{{-- ═══ مهمة ٦: لوحة قفل الأمر (باتش التام في الحجر، التكلفة، الانحراف) هنا ═══ --}}

@if ($canManage && $order->status === 'in_progress')
<dialog id="dlgFinish">
    <form class="dlg" method="POST" action="{{ route('mfg.orders.finish', $order) }}">
        @csrf
        <h4>⏹ {{ __('mfg.btn_finish') }} — {{ $order->number }}</h4>
        <div class="frow">
            <div>
                <label class="f">{{ __('mfg.f_output_qty') }} *</label>
                <input type="number" name="output_qty" step="1" min="1" required value="{{ old('output_qty', $order->planned_qty) }}">
                <div class="s" style="color:var(--muted)">{{ __('mfg.output_qty_hint') }}</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgFinish')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('mfg.btn_finish') }}</button>
        </div>
    </form>
</dialog>
@endif
@endsection
