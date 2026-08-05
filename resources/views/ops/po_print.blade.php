@extends('layouts.system')

{{--
    مستند أمر التوريد — الحسابات بتطبعه **نسختين** وتختمهم بعد
    الموافقة: نسخة بتمشي مع السواق للفرع، ونسخة بترجع مختومة
    من الفرع (شرط رابت وأمثالها: مفيش استلام من غير أمر مختوم).

    الجسم والستايل في `ops/_po_doc` و `ops/_po_doc_style` —
    مشتركين مع الطباعة المجمعة (po_print_batch).
--}}

@section('title', __('ops.po_doc').' '.$po->number)

@section('actions')
    <a class="btn" href="{{ route('ops.pos') }}">← {{ __('ops.purchase_orders') }}</a>
    <button class="btn gold" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

@include('ops._po_doc', ['po' => $po])

@endsection

@section('scripts')
@include('partials._doc_style')
@include('ops._po_doc_style')
<script>
// بعد الطباعة (أو إلغائها): 3 ثواني ورجوع لموافقات التوريد —
// نفس نمط ورقة تسليم العهدة (قرار المالك 2026-08-05)
window.addEventListener('afterprint', () => {
    setTimeout(() => { window.location.href = @json(route('ops.po.approvals')); }, 3000);
});
</script>
@endsection
