@extends('layouts.system')

{{--
    فحص أسعار أوامر التوريد (٢٤ أغسطس ٢٠٢٦) — طلب المالك:
    «زرار أدوس عليه يشوف كل الـPO اللي المفروض نغير سعرها، وأعمل
    Select لكل واحد وأقول عدّل كله».

    كل أمر مفتوح اتقارن إجماليه المخزّن بالمتوقع من تسعيرة عميله
    الحالية — المخالف بس اللي بيظهر هنا، بالفرق بالجنيه. التحديد
    الجماعي بينفّذ نفس إعادة التسعير الفردية بالظبط (والمعتمد
    بيرجع لموافقة الحسابات).
--}}

@section('title', __('ops.po_check_title'))

@section('actions')
    <a class="btn" href="{{ route('ops.pos') }}">← {{ __('ops.purchase_orders') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>🔍 {{ __('ops.po_check_title') }}
        <span class="side">{{ __('ops.po_check_hint', ['n' => $scanned]) }}</span></h3>

    @if (session('ok'))
        <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
    @endif
    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px"><span>⚠️</span><span>{{ $errors->first() }}</span></div>
    @endif

    @if (count($rows) === 0)
        <div class="alert good"><span>✅</span><span>{{ __('ops.po_check_clean') }}</span></div>
    @else
        <form method="POST" action="{{ route('ops.pos.reprice.bulk') }}"
              onsubmit="return pocSubmit()">
            @csrf
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:10px">
                <span class="badge b-orange">{{ __('ops.po_check_found', ['n' => count($rows)]) }}</span>
                <button class="btn sm" type="button" onclick="pocAll(true)">{{ __('field.select_all') }}</button>
                <button class="btn sm" type="button" onclick="pocAll(false)">{{ __('field.unselect_all') }}</button>
                <span id="pocCount" style="font-size:12px;color:var(--muted)"></span>
                <button class="btn gold" type="submit" id="pocBtn" disabled style="margin-inline-start:auto">
                    🏷 {{ __('ops.po_check_apply') }}</button>
            </div>

            <div class="tablewrap" style="max-height:64vh;overflow-y:auto">
                <table>
                    <thead>
                        <tr>
                            <th style="width:34px"></th>
                            <th>{{ __('ops.po_number') }}</th>
                            <th style="text-align:start">{{ __('ops.branch_client') }}</th>
                            <th>{{ __('common.status') }}</th>
                            <th class="num">{{ __('ops.po_check_stored') }}</th>
                            <th class="num">{{ __('ops.po_check_expected') }}</th>
                            <th class="num">{{ __('ops.po_check_diff') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            @php $po = $r['po']; @endphp
                            <tr class="clickable" onclick="pocRow(this, event)">
                                <td>
                                    @if ($r['error'] === null)
                                        <input type="checkbox" name="ids[]" value="{{ $po->id }}"
                                               onchange="pocCount()" onclick="event.stopPropagation()">
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('ops.pos.show', $po) }}" onclick="event.stopPropagation()"
                                       style="font-weight:800" dir="ltr">{{ $po->number }}</a>
                                </td>
                                <td style="text-align:start">
                                    <b>{{ $po->client?->fullName() ?? '—' }}</b>
                                </td>
                                <td>
                                    <span class="badge {{ $po->statusColor() }}">{{ $po->statusLabel() }}</span>
                                </td>
                                @if ($r['error'] !== null)
                                    <td colspan="3" style="color:var(--red,#DC2626);font-size:11.5px">
                                        ⚠️ {{ __('ops.po_check_unpriced', ['product' => $r['error']]) }}</td>
                                @else
                                    <td class="num" dir="ltr">{{ number_format((float) $po->grand_total, 2) }}</td>
                                    <td class="num" dir="ltr" style="font-weight:800;color:var(--royal-blue,#12399B)">
                                        {{ number_format($r['expected'], 2) }}</td>
                                    <td class="num" dir="ltr"
                                        style="font-weight:900;color:{{ $r['diff'] < 0 ? 'var(--red,#DC2626)' : 'var(--green,#16A34A)' }}">
                                        {{ $r['diff'] > 0 ? '+' : '' }}{{ number_format($r['diff'], 2) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>
    @endif
</div>

@endsection

@section('scripts')
<script>
function pocBoxes() { return Array.from(document.querySelectorAll('input[name="ids[]"]')); }

function pocCount() {
    const n = pocBoxes().filter(b => b.checked).length;
    document.getElementById('pocCount').textContent = n ? @json(__('ops.po_check_selected')).replaceAll(':n', n) : '';
    document.getElementById('pocBtn').disabled = n === 0;
}

function pocAll(on) { pocBoxes().forEach(b => { b.checked = on; }); pocCount(); }

// الدوسة على الصف بتقلب علامته — واللينكات والتشيك نفسه مستثنيين
function pocRow(tr, e) {
    const cb = tr.querySelector('input[name="ids[]"]');
    if (! cb) return;
    cb.checked = ! cb.checked;
    pocCount();
}

function pocSubmit() {
    const n = pocBoxes().filter(b => b.checked).length;
    if (n === 0) return false;
    return confirm(@json(__('ops.po_check_confirm')).replaceAll(':n', n));
}
</script>
@endsection
