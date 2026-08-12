@extends('layouts.system')

{{--
    صفحة أمر التوريد الكاملة (١٢ أغسطس ٢٠٢٦) — طلب المالك:
    «لما أدخل على أوامر التوريد يتفتحلي العرض والتعديل».

    الصف في لوحة التوريد بيفتح هنا: البنود بأسعارها المخزّنة (سعر
    القايمة والخصم والضريبة — من السطور نفسها مش إعادة حساب)، العميل،
    خط زمني للحالة، المستندات، وأزرار التعديل بنفس شرط poEditable
    وأكشن act.ka.edit بتوع اللوحة بالحرف.
--}}

@section('title', __('ops.purchase_order').' '.$po->number)

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $fmt0 = fn ($n) => number_format((float) $n);

    // نفس شرط زرار التعديل في اللوحة بالحرف — شرط واحد مش نسختين
    $canEdit = $editable && \App\Support\Access::action(auth()->user(), 'act.ka.edit');

    // خط السير: [الليبل، التفصيلة، حصل؟]
    $timeline = [];
    $timeline[] = [__('ops.po_tl_created'), ($po->creator?->name ?? '—').' · '.$po->created_at->format('Y-m-d h:i A'), true];

    if ($po->needsApproval()) {
        if ($po->approval_status === 'rejected') {
            $timeline[] = [__('ops.po_tl_rejected'), ($po->approvedBy?->name ?? '—').($po->approval_note ? ' · '.$po->approval_note : ''), true];
        } else {
            $timeline[] = [__('ops.po_tl_approved'), $po->approvedBy ? $po->approvedBy->name.' · '.$po->approved_at?->format('m-d h:i A') : __('enums.po_approval.pending'), $po->approval_status === 'approved'];
        }
    }

    if ($po->pickOrder) {
        $timeline[] = [__('ops.po_tl_pick'), $po->pickOrder->number.' · '.$po->pickOrder->statusLabel(), in_array($po->pickOrder->status, ['ready', 'handed'], true)];
    }

    $timeline[] = [__('ops.po_tl_arrived'), $po->arrived_at?->format('m-d h:i A') ?? '—', $po->arrived_at !== null];
    $timeline[] = [__('ops.po_tl_delivered'), $po->delivered_at?->format('m-d h:i A') ?? '—', $po->status === 'delivered'];
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.pos') }}">← {{ __('ops.purchase_orders') }}</a>
    <a class="btn" href="{{ route('ops.po.print', $po) }}" target="_blank">🖨️ {{ __('ops.print') }}</a>
    @if ($po->sheet_path)
        <a class="btn" href="{{ route('ops.po.sheet', $po) }}">📎 {{ __('ops.po_sheet') }}</a>
    @endif
    @if ($canEdit)
        <a class="btn gold" href="{{ route('ops.po.edit', $po) }}"
           @if ($po->approval_status === 'approved') onclick="return confirm(@js(__('ops.po_edit_back_confirm')))" @endif>
            ✏️ {{ __('ops.po_edit') }}
        </a>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('ops.order') }}</div>
        <div class="val">{{ $po->number }}</div>
        <div class="sub2">{{ $po->created_at->format('Y-m-d h:i A') }}
            @if ($po->source) · {{ $po->sourceLabel() }}@endif</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('common.status') }}</div>
        <div class="val" style="font-size:17px"><span class="badge {{ $po->statusClass() }}">{{ $po->statusLabel() }}</span></div>
        <div class="sub2">
            @if ($po->needsApproval())
                <span class="badge {{ $po->approvalClass() }}" style="font-size:10px">{{ $po->approvalLabel() }}</span>
            @endif
            @if ($po->isLate())<span class="badge b-red" style="font-size:10px">⏰ {{ __('ops.po_late') }}</span>@endif
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('ops.branch_client') }}</div>
        <div class="val" style="font-size:16px">
            <a href="{{ $po->client ? route('erp.clients.show', $po->client) : '#' }}" style="color:inherit">{{ $po->client?->fullName() ?? '—' }}</a>
        </div>
        <div class="sub2">
            @if ($po->client?->channel)<span class="badge {{ $po->client->channel->badgeClass() }}" style="font-size:9.5px">{{ $po->client->channel->displayName() }}</span>@endif
            {{ $po->address ?: $po->client?->address }}
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('ops.rep') }}</div>
        <div class="val" style="font-size:16px">{{ $po->courier?->displayName() ?? '—' }}</div>
        <div class="sub2">{{ $po->warehouse ? __('stock.warehouse').': '.$po->warehouse->displayName() : '—' }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('ops.due_at') }}</div>
        <div class="val" style="font-size:15px">{{ $po->due_at?->format('m-d h:i A') ?? $po->due_date?->format('Y-m-d') ?? '—' }}</div>
        <div class="sub2">@if ($po->pickup_at){{ __('ops.pickup_at') }}: {{ $po->pickup_at->format('m-d h:i A') }}@endif</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('ops.units') }}</div>
        <div class="val">{{ $fmt0($po->qtyTotal()) }}</div>
        {{-- بعد التسليم: المسلَّم فعلاً والفرق --}}
        @if ($po->status === 'delivered' && $po->qtyTotal() !== $po->deliveredQtyTotal())
            <div class="sub2" style="color:#B86E00;font-weight:800">{{ __('ops.po_delivered_qty') }} {{ $fmt0($po->deliveredQtyTotal()) }} · {{ __('ops.po_variance') }} {{ $fmt0($po->qtyTotal() - $po->deliveredQtyTotal()) }}</div>
        @endif
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('doc.total_with_tax') }}</div>
        <div class="val pos">{{ $fmt0($po->grand_total) }}</div>
        <div class="sub2">{{ __('doc.net_before_tax') }}: {{ $fmt0($po->total) }} · {{ __('tax.tax') }}: {{ $fmt0($po->tax_total) }}</div>
    </div>
</div>

@if ($po->abort_reason)
    <div class="alert" style="margin-bottom:14px">
        <span>⛔</span><span><b>{{ __('ops.po_aborted_note') }}:</b> {{ $po->abort_reason }}</span>
    </div>
@endif

<div class="grid2">
    {{-- ═══ البنود — الأرقام المخزّنة على السطور، مفيش إعادة حساب ═══ --}}
    <div class="card">
        <h3>🧺 {{ __('ops.items') }} <span class="side">{{ $po->items->count() }}</span></h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('stock.item') }}</th>
                    <th data-nosum>{{ __('stock.list_price') }}</th>
                    <th data-nosum>{{ __('doc.discount_pct') }}</th>
                    <th data-nosum>{{ __('doc.unit_price') }}</th>
                    <th>{{ __('common.qty') }}</th>
                    <th>{{ __('common.total') }}</th>
                    <th>{{ __('tax.tax') }}</th>
                    @if ($po->status === 'delivered')<th>{{ __('ops.po_delivered_qty') }}</th>@endif
                </tr>
                @foreach ($po->items as $item)
                    <tr>
                        <td>
                            <b>{{ $item->product?->displayName() ?? '#'.$item->product_id }}</b>
                            @if ($item->product)
                                <br><span style="font-size:10.5px;color:var(--muted)">{{ $item->product->code }}
                                    @if ($bd = $item->product->packBreakdown((int) $item->qty)) · {{ $bd }} @endif
                                </span>
                            @endif
                        </td>
                        <td class="num">{{ $fmt($item->list_price ?? $item->price) }}</td>
                        <td class="num">{{ (float) ($item->discount_pct ?? 0) > 0 ? number_format((float) $item->discount_pct * 100, 1).'%' : '—' }}</td>
                        <td class="num">{{ $fmt($item->price) }}</td>
                        <td class="num"><b>{{ $fmt0($item->qty) }}</b></td>
                        <td class="num">{{ $fmt($item->total) }}</td>
                        <td class="num">{{ $fmt($item->tax) }}</td>
                        @if ($po->status === 'delivered')
                            <td class="num {{ (int) $item->delivered_qty !== (int) $item->qty ? 'neg' : 'pos' }}">{{ $fmt0($item->delivered_qty) }}</td>
                        @endif
                    </tr>
                @endforeach
                <tr>
                    <td colspan="5" style="text-align:end;font-weight:800">{{ __('doc.net_before_tax') }}</td>
                    <td class="num" style="font-weight:800">{{ $fmt($po->total) }}</td>
                    <td class="num" style="font-weight:800">{{ $fmt($po->tax_total) }}</td>
                    @if ($po->status === 'delivered')<td></td>@endif
                </tr>
                <tr>
                    <td colspan="5" style="text-align:end;font-weight:800">{{ __('doc.total_with_tax') }}</td>
                    <td class="num pos" colspan="2" style="font-weight:800;font-size:14px">{{ $fmt($po->grand_total) }}</td>
                    @if ($po->status === 'delivered')<td></td>@endif
                </tr>
            </table>
        </div>
    </div>

    <div>
        {{-- ═══ خط سير الأمر ═══ --}}
        <div class="card">
            <h3>🛤️ {{ __('ops.po_timeline') }}</h3>
            <div class="alerts">
                @foreach ($timeline as [$label, $detail, $done])
                    <div class="alert {{ $done ? 'good' : '' }}">
                        <span>{{ $done ? '✅' : '⏳' }}</span>
                        <span><b>{{ $label }}</b> — <span style="color:var(--muted)">{{ $detail }}</span></span>
                    </div>
                @endforeach
                @if ($po->was_edited && $po->editor)
                    <div class="alert warn">
                        <span>✏️</span>
                        <span><b>{{ __('ops.po_tl_edited') }}</b> — {{ $po->editor->name }} · {{ $po->edited_at?->format('m-d h:i A') }}</span>
                    </div>
                @endif
                @if ($po->prepMinutes() !== null)
                    <div class="alert info">
                        <span>⏱️</span>
                        <span>{{ __('stock.prep_duration') }}: <b>{{ $po->prepMinutes() }}</b> {{ __('common.minutes') }}</span>
                    </div>
                @endif
            </div>
            @if ($po->pickOrder)
                <div style="margin-top:10px">
                    <a class="btn sm" href="{{ route('wh.picks.show', $po->pickOrder) }}">📋 {{ __('stock.pick_order') }} {{ $po->pickOrder->number }}</a>
                </div>
            @endif
            @if ($replenishment)
                <div style="margin-top:8px;font-size:12px">
                    {{ __('stock.pick_source') }}:
                    <a href="{{ route('ops.replenishments') }}" style="font-weight:800;color:var(--primary)">
                        {{ __('ops.request') }} {{ $replenishment->number }} — {{ $replenishment->originLabel() }}
                    </a>
                </div>
            @endif
        </div>

        {{-- ═══ المستندات ═══ --}}
        @if ($po->imageUrl() || $po->sheet_path)
            <div class="card">
                <h3>🗂️ {{ __('ops.po_docs') }}</h3>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    @if ($po->imageUrl())
                        <a class="btn" href="{{ $po->imageUrl() }}" target="_blank">🖼️ {{ __('ops.po_image') }}</a>
                    @endif
                    @if ($po->sheet_path)
                        <a class="btn" href="{{ route('ops.po.sheet', $po) }}">📎 {{ __('ops.po_sheet') }}</a>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

@endsection
