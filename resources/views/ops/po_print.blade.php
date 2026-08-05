@extends('layouts.system')

{{--
    مستند أمر التوريد — الحسابات بتطبعه **نسختين** وتختمهم بعد
    الموافقة: نسخة بتمشي مع السواق للفرع، ونسخة بترجع مختومة
    من الفرع (شرط رابت وأمثالها: مفيش استلام من غير أمر مختوم).

    ⚠️ تصميم شبه أحادي اللون عن قصد (قرار المالك 2026-08-05):
    الطباعة الفعلية أبيض وأسود، فالألوان لمسات براندينج خفيفة بس —
    خط أزرق تحت الهيدر والعنوان وخط الإجمالي. والصاعقة في نص الورقة
    بأوباسيتي أخف. والفوتر لاصق في آخر الورقة (flex column).
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $qtyTotal = (int) $po->items->sum('qty');
@endphp

@section('title', __('ops.po_doc').' '.$po->number)

@section('actions')
    <a class="btn" href="{{ route('ops.pos') }}">← {{ __('ops.purchase_orders') }}</a>
    <button class="btn gold" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

<div class="doc po-doc has-bolt">
    <img class="bolt-mark po-bolt" src="{{ asset('brand/bolt.svg') }}" alt="">

    <header class="doc-head">
        <div class="doc-brand">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="doc-corp">{{ __('ops.corp_name') }}</div>
        </div>
        <div class="doc-id">
            <div class="doc-no">{{ $po->number }}</div>
            @if ($po->source)
                <div class="doc-date">{{ __('ops.po_source_no') }}: <b>{{ $po->source }}</b></div>
            @endif
            <div class="doc-date">{{ $po->created_at->format('Y-m-d — H:i') }}</div>
        </div>
    </header>

    {{-- العنوان الكبير في نص الورقة --}}
    <div class="po-title">{{ __('ops.po_doc') }}</div>

    <div class="doc-body">
        <div class="doc-parties">
            <div>
                <div class="k">{{ __('ops.branch_client') }}</div>
                <div class="v">{{ $po->client?->fullName() ?? '—' }}</div>
                <div class="s">{{ $po->client?->address ?: '—' }}</div>
            </div>
            <div>
                <div class="k">{{ __('ops.rep') }}</div>
                <div class="v">{{ $po->courier?->name ?? '—' }}</div>
                <div class="s">{{ __('ops.by') }}: {{ $po->creator?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="k">{{ __('stock.warehouse') }}</div>
                <div class="v">{{ $po->warehouse?->displayName() ?? '—' }}</div>
                <div class="s">{{ __('ops.due_at') }}:
                    {{ $po->due_at?->format('Y-m-d H:i') ?? ($po->due_date?->format('Y-m-d') ?? '—') }}</div>
            </div>
        </div>

        <div class="tablewrap">
            <table class="doc-table">
                <tr>
                    <th>#</th>
                    <th>{{ __('stock.barcode') }}</th>
                    <th>{{ __('stock.item') }}</th>
                    <th class="num">{{ __('common.qty') }}</th>
                    <th class="num">{{ __('ops.price') }}</th>
                    <th class="num">{{ __('common.total') }}</th>
                </tr>

                @foreach ($po->items as $i => $it)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td class="num">{{ $it->product?->barcode ?? '—' }}</td>
                        <td>
                            <b>{{ $it->product?->displayName() ?? '—' }}</b>
                            @if ($bd = $it->product?->packBreakdown((int) $it->qty))
                                <div class="s">{{ $bd }}</div>
                            @endif
                        </td>
                        <td class="num"><b>{{ number_format($it->qty) }}</b></td>
                        <td class="num">{{ $fmt($it->price) }}</td>
                        <td class="num">{{ $fmt($it->total) }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td colspan="3"><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ number_format($qtyTotal) }}</b></td>
                    <td></td>
                    <td class="num"><b>{{ $fmt($po->total) }}</b></td>
                </tr>
            </table>
        </div>

        <div class="doc-totals">
            @if ((float) $po->tax_total > 0)
                <div class="row"><span>{{ __('ops.tax_line') }}</span><span>{{ $fmt($po->tax_total) }}</span></div>
            @endif
            <div class="row grand"><span>{{ __('ops.po_amount') }}</span><span>{{ $fmt($po->payable()) }}</span></div>
        </div>

        {{-- خانات الختم — بتظهر في الطباعة بس (doc-sign) --}}
        <div class="doc-sign three">
            <div><span></span>{{ __('ops.stamp_accounting') }}</div>
            <div><span></span>{{ __('ops.stamp_warehouse') }}</div>
            <div><span></span>{{ __('ops.stamp_branch') }}</div>
        </div>
    </div>

    <footer class="doc-foot">
        <span>PROMAX FOOD INDUSTRIES</span>
        <span>{{ $po->number }}@if ($po->source) · {{ $po->source }}@endif · {{ __('ops.po_doc') }}</span>
    </footer>
</div>

@endsection

@section('scripts')
@include('partials._doc_style')
<style>
/* ═══ أمر التوريد: شبه أحادي اللون + فوتر لاصق + صاعقة في النص ═══ */

/* الفوتر في آخر الورقة — المستند عمود والجسم بياخد الفراغ */
.po-doc{display:flex;flex-direction:column}
.po-doc .doc-body{flex:1}

/* الهيدر: أبيض بدل التدرج — لمسة البراند خط تحته بس */
.po-doc .doc-head{
  background:#fff;color:var(--ink);
  border-bottom:3px solid var(--royal-blue);
  padding:18px 26px 14px;
}
.po-doc .doc-corp{color:var(--muted);opacity:1}
.po-doc .doc-no{color:var(--ink)}
.po-doc .doc-date{color:var(--muted);opacity:1}

/* العنوان الكبير في نص الورقة */
.po-title{
  position:relative;z-index:1;text-align:center;
  font-size:25px;font-weight:900;letter-spacing:-.3px;
  color:var(--royal-blue);margin:20px 0 2px;
}

/* بلوك الأطراف: أبيض ببرواز بدل الخلفية الزرقا */
.po-doc .doc-parties{background:#fff;border:1px solid var(--border)}
.po-doc .doc-parties .k{color:var(--muted)}

/* الأرقام كلها بلون الحبر — مفيش أزرق في الجدول */
.po-doc .doc-table td,
.po-doc .doc-totals .row{color:var(--ink)}
.po-doc .doc-totals .row.grand{border-top-color:var(--ink)}

/* الصاعقة: في نص الورقة، أخف، مش متاكلة من الجنب */
.po-doc .bolt-mark.po-bolt{
  width:480px;top:32%;
  inset-inline-start:50%;margin-inline-start:-240px;
  opacity:.04;transform:rotate(8deg);
}

@media print{
  /* ورقة A4 كاملة: 297mm − هوامش 12mm×2 — الفوتر بينزل آخرها */
  .po-doc{min-height:273mm}
  .po-doc .doc-head{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .po-doc .bolt-mark.po-bolt{opacity:.035 !important}
}
</style>
<script>
// بعد الطباعة (أو إلغائها): 3 ثواني ورجوع لموافقات التوريد —
// نفس نمط ورقة تسليم العهدة (قرار المالك 2026-08-05)
window.addEventListener('afterprint', () => {
    setTimeout(() => { window.location.href = @json(route('ops.po.approvals')); }, 3000);
});
</script>
@endsection
