@extends('layouts.system')

{{--
    تسليم عهدة — اختار المندوب، حدد الأعداد، وسلّم.

    ⚠️ **الشاشة دي بتخرّج بضاعة فوراً.** أول ما تدوس تسليم الكراتين
    بتنزل من الأرفف وبتبقى مسؤولية المندوب.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
@endphp

@section('title', __('field.handout'))

@section('content')

<div class="card">
    <h3>🚚 {{ __('field.handout') }}
        <span class="side">{{ $warehouse?->displayName() ?? '—' }}</span></h3>

    {{-- ⚠️ التحذير ده مش زينة: البضاعة بتخرج قبل ما المندوب يدوس
         استلام، فاللي بيسلّم لازم يعرف إنه مسؤول عنها من دلوقتي. --}}
    <div class="alert warn" style="margin-bottom:14px">
        <span>⚠️</span><span>{{ __('field.handout_warning') }}</span>
    </div>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    @if ($warehouse === null)
        <div class="alert"><span>⛔</span><span>{{ __('stock.no_warehouses') }}</span></div>
    @else
    <form method="POST" action="{{ route('ops.handout.store') }}" id="hoForm">
        @csrf
        <input type="hidden" name="warehouse_id" value="{{ $warehouse->id }}">

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.warehouse') }}</label>
                <select style="width:100%" onchange="location.href='?warehouse='+this.value">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($w->id === $warehouse->id)>
                            {{ $w->displayName() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('ops.rep') }} <b class="req-star">*</b></label>
                <select name="rep_id" required style="width:100%">
                    <option value="">— {{ __('field.pick_rep') }} —</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected(old('rep_id') == $r->id)>
                            {{ $r->displayName() }} · {{ $r->code }} · {{ $r->roleLabel() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('field.carrier_note') }}</label>
                <input type="text" name="carrier_note" maxlength="190" style="width:100%"
                       value="{{ old('carrier_note') }}" placeholder="{{ __('field.carrier_note_ph') }}">
            </div>
        </div>

        <div class="tablewrap" style="margin-top:14px">
            <table>
                <tr>
                    <th style="width:40px"></th>
                    <th>{{ __('common.code') }}</th>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.family') }}</th>
                    <th class="num">{{ __('stock.available') }}</th>
                    <th class="num" style="width:110px">{{ __('field.qty_sale') }}</th>
                    {{-- ⚠️ **الهدايا خانة منفصلة عن البيع.** لو كانت
                         نفس الخانة، المندوب كان هيقفل عهدته و«يبيع»
                         عينات مجانية والفرق يضيع. --}}
                    <th class="num" style="width:110px">🎁 {{ __('field.qty_gift') }}</th>
                    <th class="num">{{ __('common.total') }}</th>
                </tr>

                @forelse ($products as $p)
                    <tr>
                        <td>
                            @if ($p->imageSrc())
                                <img src="{{ $p->imageSrc() }}" alt="" loading="lazy"
                                     style="width:30px;height:30px;object-fit:contain;border-radius:5px;
                                            border:1px solid var(--border);background:#fff">
                            @endif
                        </td>
                        <td class="num">{{ $p->code }}</td>
                        <td><b>{{ $p->displayName() }}</b>
                            <div style="font-size:10.5px;color:var(--muted)">{{ $p->unitLabel() }}</div>
                        </td>
                        <td><span class="badge b-gray">{{ $p->familyLabel() }}</span></td>
                        <td class="num {{ $p->available > 0 ? '' : 'muted' }}">
                            <b>{{ $fmt($p->available) }}</b>
                        </td>
                        <td class="num">
                            <input type="number" min="0" max="{{ (int) $p->available }}" style="width:100%"
                                   name="qty[{{ $p->id }}]" value="{{ old('qty.'.$p->id) }}"
                                   data-row="{{ $p->id }}" data-kind="qty"
                                   data-max="{{ (int) $p->available }}"
                                   oninput="syncRow({{ $p->id }})" @disabled($p->available <= 0)>
                        </td>
                        <td class="num">
                            <input type="number" min="0" max="{{ (int) $p->available }}" style="width:100%"
                                   name="gift[{{ $p->id }}]" value="{{ old('gift.'.$p->id) }}"
                                   data-row="{{ $p->id }}" data-kind="gift"
                                   oninput="syncRow({{ $p->id }})" @disabled($p->available <= 0)>
                        </td>
                        <td class="num" id="tot{{ $p->id }}">—</td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                        {{ __('stock.no_items') }}
                    </td></tr>
                @endforelse
            </table>
        </div>

        @if ($products->isNotEmpty())
            <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:14px">
                <span style="font-size:12.5px;color:var(--muted)">
                    {{ __('field.total_units') }}: <b id="grand">0</b>
                    · 🎁 <b id="grandGift">0</b>
                </span>
                <button class="btn gold" type="submit" id="hoBtn" disabled>
                    🚚 {{ __('field.do_handout') }}
                </button>
            </div>
        @endif
    </form>
    @endif
</div>

@if ($open->isNotEmpty())
<div class="card">
    <h3>⏳ {{ __('field.awaiting_receipt') }}
        <span class="side">{{ __('field.awaiting_hint') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.pick_order') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('stock.warehouse') }}</th>
                <th>{{ __('field.issued_at') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th class="num">🎁</th>
                <th></th>
            </tr>
            @foreach ($open as $o)
                <tr>
                    <td class="num"><b>{{ $o->number }}</b></td>
                    <td>{{ $o->rep?->displayName() ?? '—' }}</td>
                    <td style="font-size:11.5px">{{ $o->warehouse?->displayName() ?? '—' }}</td>
                    <td class="num" style="font-size:11.5px">{{ $o->issued_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="num">{{ $fmt($o->items->sum('qty_picked')) }}</td>
                    <td class="num">{{ $fmt($o->items->sum('gift_qty')) ?: '—' }}</td>
                    <td class="num">
                        <a class="btn sm" href="{{ route('ops.handout.print', $o) }}">🖨️</a>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

@endsection

@section('scripts')
<script>
/**
 * إجمالي السطر = بيع + هدايا، والحد الأقصى للاتنين مع بعض هو المتاح.
 *
 * ⚠️ **الهدية بتتحجز من نفس المخزون.** المتاح 40، وكتبت 40 بيع
 * و5 هدايا — السيرفر هيرفض الأمر كله. الشاشة بتقولها قبل ما تدوس.
 */
function syncRow(id) {
    const qty = document.querySelector('[data-row="' + id + '"][data-kind="qty"]');
    const gift = document.querySelector('[data-row="' + id + '"][data-kind="gift"]');
    const cell = document.getElementById('tot' + id);

    if (!qty || !gift || !cell) return;

    const max = Number(qty.dataset.max || 0);
    const sum = Number(qty.value || 0) + Number(gift.value || 0);
    const over = sum > max;

    cell.innerHTML = sum === 0 ? '—' : '<b>' + sum.toLocaleString() + '</b>';
    cell.className = over ? 'num neg' : 'num';
    qty.className = gift.className = over ? 'bad' : '';

    syncTotals();
}

function syncTotals() {
    let sale = 0, gift = 0, over = false;

    document.querySelectorAll('[data-kind="qty"]').forEach(q => {
        const g = document.querySelector('[data-row="' + q.dataset.row + '"][data-kind="gift"]');
        const s = Number(q.value || 0);
        const gv = Number(g ? g.value || 0 : 0);

        sale += s;
        gift += gv;

        if (s + gv > Number(q.dataset.max || 0)) over = true;
    });

    document.getElementById('grand').textContent = sale.toLocaleString();
    document.getElementById('grandGift').textContent = gift.toLocaleString();

    const btn = document.getElementById('hoBtn');

    if (btn) btn.disabled = over || (sale + gift) === 0;
}

syncTotals();
</script>
@endsection
