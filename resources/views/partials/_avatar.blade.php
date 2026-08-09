{{--
    دايرة الموظف (٩ أغسطس ٢٠٢٦) — صورته لو رفعها، وإلا حروف اسمه
    على خلفية ثابتة من الـid (نفس الموظف = نفس اللون دايماً).

    الاستخدام: @include('partials._avatar', ['u' => $user, 'size' => 34])
    اختياري: 'ring' => '#hex' لإطار ملوّن (التراكينج بيلوّن لكل مندوب).
--}}
@php
    $size = $size ?? 34;
    $ring = $ring ?? null;
    $palette = ['#12399B', '#602D90', '#0F766E', '#B45309', '#B00020', '#2563EB', '#DB2777', '#059669'];
    $bg = $palette[$u->id % count($palette)];
    $border = $ring ? "border:2.5px solid {$ring};" : 'border:1.5px solid var(--border);';
@endphp
@if ($u->avatarUrl())
    <img src="{{ $u->avatarUrl() }}" alt="{{ $u->displayName() }}" title="{{ $u->displayName() }}"
         style="width:{{ $size }}px;height:{{ $size }}px;border-radius:50%;object-fit:cover;{{ $border }}flex-shrink:0;vertical-align:middle">
@else
    <span title="{{ $u->displayName() }}"
          style="width:{{ $size }}px;height:{{ $size }}px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:{{ $bg }};color:#fff;font-weight:900;font-size:{{ (int) round($size * .38) }}px;{{ $border }}flex-shrink:0;vertical-align:middle">{{ $u->initials() }}</span>
@endif
