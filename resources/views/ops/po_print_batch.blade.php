@extends('layouts.system')

{{--
    طباعة مجمعة لأوامر التوريد (2026-08-06) — كل الأوامر المطلوبة
    في مستند واحد، أمر في صفحة A4 لوحده (page-break بين كل اثنين).
    الحسابات بتطبع دفعة السلسلة كلها بضغطة بدل ما تفتح أمر أمر.
--}}

@section('title', __('ops.print_all').' — '.$pos->count().' '.trans_choice('ops.order_count', $pos->count()))

@section('actions')
    <a class="btn" href="{{ route('ops.po.approvals') }}">← {{ __('ops.po_approvals') }}</a>
    <button class="btn gold" onclick="window.print()">🖨️ {{ __('ops.print_all') }} ({{ $pos->count() }})</button>
@endsection

@section('content')

@foreach ($pos as $po)
    @include('ops._po_doc', ['po' => $po])
@endforeach

@endsection

@section('scripts')
@include('partials._doc_style')
@include('ops._po_doc_style')
<style>
/* كل أمر في ورقة لوحده — والفاصل بيبان على الشاشة كمان */
.po-doc + .po-doc{margin-top:24px}
@media print{
  .po-doc{page-break-after:always}
  .po-doc:last-child{page-break-after:auto}
}
</style>
@php
    // back=pos: جايين من فلو الرفع — الرجوع لأوامر التوريد مش الموافقات.
    // auto=1: الطباعة بتتفتح لوحدها أول ما الصفحة تحمّل.
    $backUrl = request()->query('back') === 'pos' ? route('ops.pos') : route('ops.po.approvals');
@endphp
<script>
// بعد الطباعة (أو الإلغاء): 3 ثواني وريديركت — نفس نمط الفردي
window.addEventListener('afterprint', () => {
    setTimeout(() => { window.location.href = @json($backUrl); }, 3000);
});

@if (request()->boolean('auto'))
// جايين من الإنشاء المتتابع — الطباعة بتتفتح أوتوماتيك
window.addEventListener('load', () => setTimeout(() => window.print(), 600));
@endif
</script>
@endsection
