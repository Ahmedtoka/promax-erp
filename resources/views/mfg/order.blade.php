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
        @if ($canManage && $hasShortage && in_array($order->status, \App\Services\Mfg\PurchaseLink::STATUSES, true) && ! $order->shortage_requested_at)
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

{{-- ═══ صرف الخامات (مهمة ٥): صرف المعياري / صرف أو صرف زيادة / مرتجع للمخزن ═══ --}}
@php
    $canIssue = \App\Support\Access::action($user, 'act.mfg.issue') && $order->isOpen();
    $kindBadge = ['issue' => 'b-blue', 'extra' => 'b-orange', 'return' => 'b-green'];
    $materials = $order->lines->pluck('product')->filter();
    $anyRemaining = $req->contains(fn ($r) => $r['remaining'] > 0.0005);
    $anyHeld = $req->contains(fn ($r) => $r['issued_net'] > 0.0005);
@endphp
<div class="card">
    <h3>📦 {{ __('mfg.issue_panel_title') }}
        @if ($canIssue && $anyRemaining)
            <span class="side" data-noprint>
                <form method="POST" action="{{ route('mfg.orders.issue_standard', $order) }}" style="display:inline"
                      onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('mfg.confirm_issue_standard')) }})">
                    @csrf
                    <button class="btn sm gold" type="submit">⚡ {{ __('mfg.btn_issue_standard') }}</button>
                </form>
            </span>
        @endif
    </h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('mfg.issue_panel_hint') }}</div>

    @if ($canIssue && $materials->isNotEmpty())
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px" data-noprint>
            <form method="POST" action="{{ route('mfg.orders.issue', $order) }}" style="flex:1 1 320px">
                @csrf
                <h4 style="margin:0 0 8px">➖ {{ __('mfg.issue_form_title') }}</h4>
                <div class="frow">
                    <div>
                        <label class="f">{{ __('mfg.f_material') }} *</label>
                        <select name="material_id" required>
                            <option value="">—</option>
                            @foreach ($materials as $m)
                                <option value="{{ $m->id }}" @selected((string) old('material_id') === (string) $m->id)>{{ $m->code }} — {{ $m->displayName() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="f">{{ __('mfg.f_issue_qty') }} *</label>
                        <input type="number" name="qty" step="1" min="1" required value="{{ old('qty') }}">
                    </div>
                    <div>
                        <label class="f">{{ __('mfg.f_issue_kind') }} *</label>
                        <select name="kind" required>
                            @foreach (\App\Services\Mfg\Issues::ISSUE_KINDS as $k)
                                <option value="{{ $k }}" @selected(old('kind', 'issue') === $k)>{{ __('mfg.issue_kind_'.$k) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="frow">
                    <div>
                        <label class="f">{{ __('mfg.f_note') }}</label>
                        <input type="text" name="note" maxlength="500" value="{{ old('note') }}">
                    </div>
                </div>
                <div class="s" style="color:var(--muted)">{{ __('mfg.issue_whole_hint') }}</div>
                <button class="btn green" type="submit" style="margin-top:8px">{{ __('mfg.btn_issue') }}</button>
            </form>

            @if ($anyHeld)
                <form method="POST" action="{{ route('mfg.orders.return', $order) }}" style="flex:1 1 320px">
                    @csrf
                    <h4 style="margin:0 0 8px">↩ {{ __('mfg.return_form_title') }}</h4>
                    <div class="frow">
                        <div>
                            <label class="f">{{ __('mfg.f_material') }} *</label>
                            <select name="material_id" required>
                                <option value="">—</option>
                                @foreach ($req->filter(fn ($r) => $r['issued_net'] > 0.0005) as $r)
                                    <option value="{{ $r['product']->id }}">{{ $r['product']->code }} — {{ $r['product']->displayName() }} ({{ $num($r['issued_net']) }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="f">{{ __('mfg.f_return_qty') }} *</label>
                            <input type="number" name="qty" step="1" min="1" required>
                        </div>
                    </div>
                    <div class="frow">
                        <div>
                            <label class="f">{{ __('mfg.f_note') }}</label>
                            <input type="text" name="note" maxlength="500">
                        </div>
                    </div>
                    <div class="s" style="color:var(--muted)">{{ __('mfg.return_hint') }}</div>
                    <button class="btn" type="submit" style="margin-top:8px">{{ __('mfg.btn_return') }}</button>
                </form>
            @endif
        </div>
    @endif

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('mfg.col_issued_at') }}</th>
                <th>{{ __('mfg.col_material') }}</th>
                <th>{{ __('mfg.col_raw_batch') }}</th>
                <th>{{ __('mfg.col_kind') }}</th>
                <th class="num">{{ __('mfg.col_qty') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_unit_cost') }}</th>
                <th class="num">{{ __('mfg.col_value') }}</th>
                <th>{{ __('mfg.col_by') }}</th>
                <th>{{ __('mfg.f_note') }}</th>
            </tr>
            @forelse ($issues as $i)
                <tr>
                    <td class="num">{{ $i->issued_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $i->product?->code }} — {{ $i->product?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $i->batch?->batch_no ?? '—' }}</td>
                    <td><span class="badge {{ $kindBadge[$i->kind] ?? 'b-gray' }}">{{ $i->kindLabel() }}</span></td>
                    <td class="num">{{ $i->kind === 'return' ? '−' : '' }}{{ $num($i->qty) }}</td>
                    <td class="num">{{ number_format((float) $i->unit_cost, 4) }}</td>
                    <td class="num">{{ $i->kind === 'return' ? '−' : '' }}{{ number_format((float) $i->value, 2) }}</td>
                    <td>{{ $i->user?->displayName() ?? '—' }}</td>
                    <td>{{ $i->note }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_issues') }}</td></tr>
            @endforelse
            @if ($issues->isNotEmpty())
                {{-- إجمالي من السيرفر (صرف − مرتجع) — الـtfoot بيمنع الجمع التلقائي للعمود بالإشارات --}}
                <tfoot>
                    <tr>
                        <td colspan="6"><b>{{ __('mfg.material_cost_net') }}</b></td>
                        <td class="num"><b>{{ number_format($order->materialCost(), 2) }}</b></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- ═══ مهمة ٦: قفل الأمر ← باتش تام في الحجر (D6) ═══ --}}
@if ($canManage && $order->status === 'finished')
<div class="card" data-noprint>
    <h3>🔒 {{ __('mfg.close_panel_title') }}</h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('mfg.close_panel_hint') }}</div>
    <form method="POST" action="{{ route('mfg.orders.close', $order) }}"
          onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('mfg.confirm_close')) }})">
        @csrf
        <div class="frow">
            <div>
                <label class="f">{{ __('mfg.f_output_qty') }} *</label>
                <input type="number" name="output_qty" step="1" min="1" required value="{{ old('output_qty', $order->output_qty) }}">
                <div class="s" style="color:var(--muted)">{{ __('mfg.output_qty_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('mfg.f_produced_on') }} *</label>
                <input type="date" name="produced_on" required max="{{ today()->toDateString() }}"
                       value="{{ old('produced_on', ($order->finished_at ?? today())->format('Y-m-d')) }}">
            </div>
        </div>
        <button class="btn green" type="submit" style="margin-top:8px">🔒 {{ __('mfg.btn_close_order') }}</button>
    </form>
</div>
@endif

{{-- ═══ بعد القفل: التكلفة والانحراف لكل خامة + باتش التام وحالة الجودة ═══ --}}
@if ($order->status === 'closed')
@php
    $qcBadge = ['quarantine' => 'b-orange', 'released' => 'b-green', 'rejected' => 'b-red'];
    $canBatches = \App\Support\Access::allows($user, 'erp.batches');
    $sum = fn (string $col) => $order->lines->sum(fn ($l) => (float) $l->{$col});
    $signed = fn ($v, $dp) => ((float) $v > 0 ? '+' : '').number_format((float) $v, $dp);
@endphp
<div class="card">
    <h3>💰 {{ __('mfg.cost_card_title') }}
        <span class="side"><span class="badge {{ $qcBadge[$order->qc_status] ?? 'b-gray' }}">{{ __('mfg.col_qc_status') }}: {{ $order->qcLabel() }}</span></span>
    </h3>
    <div class="frow" style="margin-bottom:12px">
        <div><label class="f">{{ __('mfg.col_fg_batch') }}</label><div class="num"><b>
            @if ($order->fgBatch && $canBatches)
                <a href="{{ route('erp.batches', ['q' => $order->fgBatch->batch_no]) }}">{{ $order->fgBatch->batch_no }}</a>
            @else
                {{ $order->fgBatch?->batch_no ?? $order->batch_no }}
            @endif
        </b></div></div>
        <div><label class="f">{{ __('mfg.col_produced_on') }}</label><div class="num">{{ $order->produced_on?->format('Y-m-d') ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_expires_on') }}</label><div class="num">{{ $order->expires_on?->format('Y-m-d') ?? '—' }}
            <span class="s" style="color:var(--muted)">({{ __('mfg.expiry_source_'.($order->expiry_source ?? 'product')) }})</span></div></div>
        <div><label class="f">{{ __('mfg.col_closed_at') }}</label><div class="num">{{ $order->closed_at?->format('Y-m-d H:i') ?? '—' }}</div></div>
    </div>
    <div class="frow" style="margin-bottom:12px">
        <div><label class="f">{{ __('mfg.col_material_cost') }}</label><div class="num"><b>{{ number_format((float) $order->material_cost, 2) }}</b></div></div>
        <div><label class="f">{{ __('mfg.col_std_material_cost') }}</label><div class="num">{{ number_format((float) $order->std_material_cost, 2) }}</div></div>
        <div><label class="f">{{ __('mfg.col_unit_cost') }}</label><div class="num">{{ number_format((float) $order->unit_cost, 4) }}</div></div>
        <div><label class="f">{{ __('mfg.col_waste_pct') }} / {{ __('mfg.col_yield_pct') }}</label><div class="num">
            {{ $order->waste_pct !== null ? number_format((float) $order->waste_pct, 2).'%' : '—' }} /
            {{ $order->yield_pct !== null ? number_format((float) $order->yield_pct, 2).'%' : '—' }}</div></div>
    </div>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('mfg.col_material') }}</th>
                <th data-nosum>{{ __('mfg.col_unit') }}</th>
                <th class="num">{{ __('mfg.col_base_qty') }}</th>
                <th class="num">{{ __('mfg.col_std_qty') }}</th>
                <th class="num">{{ __('mfg.col_actual_qty') }}</th>
                <th class="num">{{ __('mfg.col_actual_cost') }}</th>
                <th class="num">{{ __('mfg.col_std_cost') }}</th>
                <th class="num">{{ __('mfg.col_variance_qty') }}</th>
                <th class="num">{{ __('mfg.col_variance_value') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_waste_pct') }}</th>
            </tr>
            @foreach ($order->lines as $l)
                <tr>
                    <td>{{ $l->product?->code }} — {{ $l->product?->displayName() ?? '—' }}</td>
                    <td>{{ $l->product?->unitLabel() }}</td>
                    <td class="num">{{ $num($l->base_qty_actual) }}</td>
                    <td class="num">{{ $num($l->std_qty_actual) }}</td>
                    <td class="num">{{ $num($l->actual_qty) }}</td>
                    <td class="num">{{ number_format((float) $l->actual_cost, 2) }}</td>
                    <td class="num">{{ number_format((float) $l->std_cost, 2) }}</td>
                    <td class="num">{{ $signed($l->variance_qty, 3) }}</td>
                    <td class="num">
                        <span class="badge {{ (float) $l->variance_value > 0.005 ? 'b-red' : ((float) $l->variance_value < -0.005 ? 'b-green' : 'b-gray') }}">{{ $signed($l->variance_value, 2) }}</span>
                    </td>
                    <td class="num">{{ $l->waste_pct !== null ? number_format((float) $l->waste_pct, 2).'%' : '—' }}</td>
                </tr>
            @endforeach
            {{-- الإجمالي من السيرفر — الكميات بوحدات مختلفة مابتتجمعش --}}
            <tfoot>
                <tr>
                    <td colspan="5"><b>{{ __('mfg.total') }}</b></td>
                    <td class="num"><b>{{ number_format($sum('actual_cost'), 2) }}</b></td>
                    <td class="num"><b>{{ number_format($sum('std_cost'), 2) }}</b></td>
                    <td></td>
                    <td class="num"><b>{{ $signed($sum('variance_value'), 2) }}</b></td>
                    <td class="num"><b>{{ $order->waste_pct !== null ? number_format((float) $order->waste_pct, 2).'%' : '—' }}</b></td>
                </tr>
            </tfoot>
        </table>
    </div>
    @if ($order->qc_note)
        <div class="s" style="margin-top:12px">🔬 {{ __('mfg.f_qc_note') }}: {{ $order->qc_note }}</div>
    @endif
</div>
@endif

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
