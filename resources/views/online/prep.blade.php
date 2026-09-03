@extends('layouts.system')

@section('title', __('online.prep_title'))

@php
    $canPrep = in_array(auth()->user()->role, ['admin', 'manager', 'warehouse_keeper'], true);
@endphp

@section('content')

@if ($errors->any())
    <div class="alert" style="margin-bottom:12px">{{ $errors->first() }}</div>
@endif
@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

{{-- ═══ ليستة أوردرات (٤/٩ — قرار المالك): جدول صف لكل أوردر،
     والضغط على الصف بيفتح بوب اب الأصناف بالصور والباتش والرف
     والكمية بخط كبير. المطلوب تجهيزه بس — اللي خلص بيختفي. ═══ --}}
<div class="card">
    <h3>📦 {{ __('online.prep_title') }}
        <span class="badge b-gold">{{ $picks->count() }}</span></h3>
    <div class="dash-hint" style="margin-bottom:10px">{{ __('online.prep_hint2') }}</div>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('online.shopify_no') }}</th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('common.phone') }}</th>
                <th>{{ __('online.area') }}</th>
                <th class="num" data-nosum>{{ __('online.items_n') }}</th>
                <th class="num" data-nosum>{{ __('online.pieces') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($picks as $pick)
                @php
                    $o = $orders[$pick->id] ?? null;

                    // بايلود البوب اب — الأصناف بالصور والباتش والرف
                    // ⚠️ ممنوع @json — بيكسّر بارسر البليد (الفخ الموثق)
                    $payload = json_encode([
                        'number' => $pick->number,
                        'order_no' => $o?->number,
                        'customer' => $o?->customer_name,
                        'phone' => $o?->phone,
                        'area' => $o?->area,
                        'status' => $pick->status,
                        'items' => $pick->items->map(fn ($i) => [
                            'img' => $i->product?->imageSrc(),
                            'name' => $i->product?->displayName() ?? '#'.$i->product_id,
                            'batch' => $i->batchNo(),
                            'shelf' => $i->locationCode(),
                            'qty' => (int) $i->qty_requested,
                        ])->values()->all(),
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                @endphp
                <tr style="cursor:pointer" onclick='openPrep({{ $pick->id }}, {!! $payload !!})'>
                    <td class="num s"><b>{{ $o !== null ? '#'.$o->number : $pick->number }}</b></td>
                    <td>{{ $o?->customer_name ?: '—' }}</td>
                    <td class="num s" dir="ltr">{{ $o?->phone ?: '—' }}</td>
                    <td class="s">{{ $o?->area ?: '—' }}</td>
                    <td class="num">{{ $pick->items->count() }}</td>
                    <td class="num"><b>{{ $pick->qtyRequested() }}</b></td>
                    <td><span class="badge {{ $pick->statusClass() }}">{{ $pick->statusLabel() }}</span></td>
                    <td class="num" onclick="event.stopPropagation()">
                        @if ($canPrep)
                            @if ($pick->status === 'requested')
                                <form method="POST" action="{{ route('online.prep.start', $pick) }}" style="display:inline">
                                    @csrf
                                    <button class="btn sm gold" type="submit">▶ {{ __('online.prep_start') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('online.prep.done', $pick) }}" style="display:inline"
                                      onsubmit="return confirm(PREP_DONE_MSG)">
                                    @csrf
                                    <button class="btn sm green" type="submit">✅ {{ __('online.prep_finish') }}</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('online.prep_empty') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- ═══ بوب اب أصناف الأوردر — واضح في العين: صورة كبيرة، اسم عريض،
     باتش ورف كشارات كبيرة، والكمية أكبر حاجة في الصف ═══ --}}
<dialog id="dlgPrep" style="max-width:640px;width:94vw">
    <div class="dlg">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <h4 style="margin:0">📦 <span id="ppNum"></span></h4>
            <div id="ppCustomer" style="font-size:12px;color:var(--muted)"></div>
        </div>

        <div id="ppItems" style="display:flex;flex-direction:column;gap:10px;margin:14px 0;
             max-height:60vh;overflow-y:auto"></div>

        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgPrep')">{{ __('common.cancel') }}</button>
            @if ($canPrep)
                <form method="POST" id="ppStartForm" style="display:none">
                    @csrf
                    <button class="btn gold" type="submit">▶ {{ __('online.prep_start') }}</button>
                </form>
                <form method="POST" id="ppDoneForm" style="display:none"
                      onsubmit="return confirm(PREP_DONE_MSG)">
                    @csrf
                    <button class="btn green" type="submit">✅ {{ __('online.prep_finish') }}</button>
                </form>
            @endif
        </div>
    </div>
</dialog>

@endsection

@section('scripts')
<style>
/* صف صنف في البوب اب — مقروء من على بعد ذراع */
.pp-item{
    display:flex;align-items:center;gap:12px;
    border:1.5px solid var(--border);border-radius:14px;padding:10px 14px;background:#fff;
}
.pp-item img,.pp-item .noimg{
    width:64px;height:64px;object-fit:cover;border-radius:10px;flex-shrink:0;
    background:var(--blue-050,#E8F1FF);display:flex;align-items:center;justify-content:center;font-size:26px;
}
.pp-name{font-weight:900;font-size:15px}
.pp-meta{display:flex;gap:6px;margin-top:5px;flex-wrap:wrap}
.pp-tag{
    font-size:12px;font-weight:800;padding:3px 10px;border-radius:999px;
    background:var(--blue-050,#E8F1FF);border:1px solid var(--royal-blue,#12399B);
}
.pp-qty{
    margin-inline-start:auto;text-align:center;flex-shrink:0;
    font-size:26px;font-weight:900;color:var(--royal-blue,#12399B);min-width:64px;
}
.pp-qty small{display:block;font-size:10px;color:var(--muted);font-weight:700}
</style>
<script>
    const PREP_DONE_MSG = @js(__('online.prep_done_msg'));
    const T_BATCH = @js(__('online.batch'));
    const T_SHELF = @js(__('online.shelf'));
    const T_QTY = @js(__('common.qty'));
    const START_URL = @js(url('erp/online/prep'));

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function openPrep(pickId, p) {
        document.getElementById('ppNum').textContent =
            (p.order_no ? '#' + p.order_no : p.number);
        document.getElementById('ppCustomer').textContent =
            [p.customer, p.phone, p.area].filter(Boolean).join(' · ');

        var box = document.getElementById('ppItems');
        box.innerHTML = '';

        p.items.forEach(function (it) {
            var img = it.img
                ? '<img src="' + esc(it.img) + '" alt="">'
                : '<div class="noimg">📦</div>';

            box.insertAdjacentHTML('beforeend',
                '<div class="pp-item">' + img
                + '<div><div class="pp-name">' + esc(it.name) + '</div>'
                + '<div class="pp-meta">'
                + '<span class="pp-tag">🏷 ' + T_BATCH + ': <b>' + esc(it.batch) + '</b></span>'
                + '<span class="pp-tag">🗄 ' + T_SHELF + ': <b>' + esc(it.shelf) + '</b></span>'
                + '</div></div>'
                + '<div class="pp-qty">' + it.qty + '<small>' + T_QTY + '</small></div>'
                + '</div>');
        });

        /* زرار الأكشن المناسب لحالة الأمر */
        var startF = document.getElementById('ppStartForm');
        var doneF = document.getElementById('ppDoneForm');

        if (startF && doneF) {
            startF.style.display = p.status === 'requested' ? '' : 'none';
            doneF.style.display = p.status === 'picking' ? '' : 'none';
            startF.action = START_URL + '/' + pickId + '/start';
            doneF.action = START_URL + '/' + pickId + '/done';
        }

        openDlg('dlgPrep');
    }
</script>
@endsection
