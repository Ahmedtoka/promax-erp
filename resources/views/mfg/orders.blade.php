@extends('layouts.system')

@section('title', __('mfg.orders_title'))

@section('content')
@php
    $badge = ['planned' => 'b-gray', 'in_progress' => 'b-blue', 'finished' => 'b-orange', 'closed' => 'b-green', 'cancelled' => 'b-red'];
    $canManage = \App\Support\Access::action(auth()->user(), 'act.mfg.orders_manage');
    $activeLines = $lines->where('active', true);
@endphp
<div class="card">
    <h3>📋 {{ __('mfg.orders_title') }}
        <span class="side">
            {{ __('ui.rows_n', ['n' => $orders->total()]) }}
            @if ($canManage)
                <button class="btn sm gold" type="button" onclick="openDlg('dlgOrder')" data-noprint>➕ {{ __('mfg.new_order') }}</button>
            @endif
        </span>
    </h3>

    {{-- فلتر «من — إلى» على `planned_on` — تاريخ الخطة مش وقت الإدخال --}}
    <form class="searchbar" method="GET" data-noprint>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status">
            <option value="">{{ __('mfg.all_statuses') }}</option>
            @foreach (\App\Models\ProductionOrder::STATUSES as $st)
                <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ __('mfg.order_status_'.$st) }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('mfg.col_line') }}</span>
        <select name="line_id">
            <option value="">{{ __('mfg.all_lines') }}</option>
            @foreach ($lines as $l)
                <option value="{{ $l->id }}" @selected(($filters['line_id'] ?? null) === $l->id)>{{ $l->displayName() }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('mfg.col_product') }}</span>
        <select name="product_id">
            <option value="">{{ __('mfg.all_products') }}</option>
            @foreach ($products as $p)
                <option value="{{ $p->id }}" @selected(($filters['product_id'] ?? null) === $p->id)>{{ $p->displayName() }}</option>
            @endforeach
        </select></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.orders') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('mfg.col_number') }}</th>
                <th>{{ __('mfg.col_product') }}</th>
                <th>{{ __('mfg.col_line') }}</th>
                <th data-nosum>{{ __('mfg.col_batch_no') }}</th>
                <th data-nosum>{{ __('mfg.col_planned_on') }}</th>
                <th class="num">{{ __('mfg.col_planned_qty') }}</th>
                <th class="num">{{ __('mfg.col_output_qty') }}</th>
                <th>{{ __('mfg.col_status') }}</th>
            </tr>
            @forelse ($orders as $o)
                <tr class="clickable" onclick="location.href='{{ route('mfg.orders.show', $o) }}'">
                    <td class="num"><a href="{{ route('mfg.orders.show', $o) }}"><b>{{ $o->number }}</b></a></td>
                    <td>{{ $o->product?->displayName() ?? '—' }}</td>
                    <td>{{ $o->line?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $o->batch_no }}</td>
                    <td class="num s">{{ $o->planned_on?->format('Y-m-d') }}</td>
                    <td class="num">{{ number_format($o->planned_qty) }}</td>
                    <td class="num">{{ $o->output_qty !== null ? number_format($o->output_qty) : '—' }}</td>
                    <td><span class="badge {{ $badge[$o->status] ?? 'b-gray' }}">{{ $o->statusLabel() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_orders') }}</td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $orders])
</div>

@if ($canManage)
{{-- أمر جديد: منتج تام له BOM معتمدة + خط نشط. رقم التشغيلة مقترح = كود المنتج-تاريخ الخطة،
     والسيرفر بيرفض الرقم لو مستخدم للمنتج في أمر أو باتش. المخازن الفاضية = إعداد التصنيع. --}}
<dialog id="dlgOrder">
    <form class="dlg" method="POST" action="{{ route('mfg.orders.store') }}">
        @csrf
        <h4>➕ {{ __('mfg.new_order') }}</h4>
        @if ($orderable->isEmpty())
            <div class="alert">{{ __('mfg.no_orderable') }}</div>
        @endif
        @if ($activeLines->isEmpty())
            <div class="alert">{{ __('mfg.no_active_lines') }}</div>
        @endif
        <div class="frow">
            <div>
                <label class="f">{{ __('mfg.col_product') }} *</label>
                <select name="product_id" id="moProduct" required onchange="moSuggest()">
                    <option value="">—</option>
                    @foreach ($orderable as $p)
                        <option value="{{ $p->id }}" data-code="{{ $p->code }}" @selected((string) old('product_id') === (string) $p->id)>{{ $p->code }} — {{ $p->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('mfg.col_line') }} *</label>
                <select name="line_id" required>
                    <option value="">—</option>
                    @foreach ($activeLines as $l)
                        <option value="{{ $l->id }}" @selected((string) old('line_id') === (string) $l->id)>{{ $l->code }} — {{ $l->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('mfg.col_planned_qty') }} *</label>
                <input type="number" name="planned_qty" step="1" min="1" required value="{{ old('planned_qty') }}">
            </div>
            <div>
                <label class="f">{{ __('mfg.col_planned_on') }} *</label>
                <input type="date" name="planned_on" id="moDate" required value="{{ old('planned_on', today()->toDateString()) }}" onchange="moSuggest()">
            </div>
            <div>
                <label class="f">{{ __('mfg.col_batch_no') }} *</label>
                <input type="text" name="batch_no" id="moBatch" maxlength="60" required value="{{ old('batch_no') }}">
                <div class="s" style="color:var(--muted)">{{ __('mfg.batch_no_hint') }}</div>
            </div>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('mfg.f_raw_warehouse') }}</label>
                <select name="raw_warehouse_id">
                    <option value="">{{ __('mfg.default_wh') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected((string) old('raw_warehouse_id') === (string) $w->id)>{{ $w->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('mfg.f_fg_warehouse') }}</label>
                <select name="warehouse_id">
                    <option value="">{{ __('mfg.default_wh') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected((string) old('warehouse_id') === (string) $w->id)>{{ $w->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('mfg.f_notes') }}</label>
                <textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgOrder')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('mfg.btn_create_order') }}</button>
        </div>
    </form>
</dialog>
<script>
// اقتراح رقم التشغيلة: كود المنتج-YYYYMMDD — بيتغير مع المنتج/التاريخ طول ما المستخدم ماكتبش رقمه
let moLast = '';
function moSuggest() {
    const p = document.getElementById('moProduct');
    const d = document.getElementById('moDate').value.replaceAll('-', '');
    const b = document.getElementById('moBatch');
    const code = p.selectedOptions[0]?.dataset.code || '';
    if (! code || ! d) { return; }
    if (b.value === '' || b.value === moLast) {
        moLast = code + '-' + d;
        b.value = moLast;
    }
}
@if ($errors->has('order') || $errors->hasAny(['product_id', 'line_id', 'planned_qty', 'batch_no', 'planned_on']))
    // `openDlg` بيتعرّف في اللاي أوت بعد المحتوى
    document.addEventListener('DOMContentLoaded', () => openDlg('dlgOrder'));
@endif
</script>
@endif
@endsection
