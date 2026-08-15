{{--
    ═══════════════════════════════════════════════════════════════
    زرار «عرض» لصف الجدول  ·  ١٥ أغسطس ٢٠٢٦
    ═══════════════════════════════════════════════════════════════

    طلب المالك: «أي حاجة في الجدول اعملي فيو ليها في تاب جديد أو
    يفتح على صفحتها».

    قبل كده كان الصف بيبقى `class="clickable" onclick="location.href"`
    — أفوردانس مخفي (المستخدم لازم يخمّن إن الصف بيتدوس عليه)،
    وبيفتح في نفس التاب فبيضيّع الفلتر والسكرول اللي المستخدم واقف
    عليه. وكمان صفوف كتير مكانش ليها أي مسار أصلاً (التحصيلات،
    طلبات البضاعة، الزيارات).

    ⚠️ `event.stopPropagation()` **ضروري**: الصف نفسه لسه `clickable`
    عشان اللي متعوّد عليه، ومن غيرها الدوسة على الزرار كانت هتفتح
    التاب الجديد **و** تنقل التاب الحالي في نفس اللحظة.

    ⚠️ `rel="noopener"` مع `target="_blank"` — الصفحة الجديدة
    مالهاش يبقى عندها `window.opener` على الداشبورد.

    ⚠️ لما مافيش صفحة للمستند (زي طلب البضاعة اللي لسه ماتحوّلش
    أمر توريد) الزرار بيتعرض **مطفي** مش بيختفي — عمود الأكشن
    بيفضل بنفس العرض في كل الصفوف والجدول مايرقصش.

    الاستخدام:
      @include('partials._view', ['url' => route('ops.invoice', $inv)])
      @include('partials._view', ['url' => $u, 'label' => __('ops.rc_show_docs')])
--}}
@php
    $vUrl = $url ?? null;
    $vLabel = $label ?? __('common.view');
@endphp
@if ($vUrl)
    <a class="vbtn" href="{{ $vUrl }}" target="_blank" rel="noopener"
       title="{{ $vLabel }}" aria-label="{{ $vLabel }}"
       onclick="event.stopPropagation()">
        <span class="vbtn-i" aria-hidden="true">↗</span><span class="vbtn-t">{{ $vLabel }}</span>
    </a>
@else
    <span class="vbtn off" title="{{ __('ops.rc_no_doc') }}" aria-hidden="true">↗</span>
@endif
