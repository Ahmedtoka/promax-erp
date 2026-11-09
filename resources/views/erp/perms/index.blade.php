@extends('layouts.system')

{{--
    شاشة الرولز والصلاحيات (٩/١١ — مهمة ٨). ست تابات بـ`?tab=`:
    الرولز · المصفوفة · ورقة الرول · مين معاه الصلاحية · مقارنة · السجل.
    الفعلي والمصدر جايين من PermissionMatrix (نفس الريزولفر) — الشاشة بتعرض بس.
--}}

@php
    use App\Services\PermissionMatrix;
    use App\Support\PermissionCatalog;

    $tabUrl = fn (string $t, array $x = []) => route('erp.perms', ['tab' => $t] + $x);
    $tabIcons = ['roles' => '👥', 'matrix' => '🧩', 'sheet' => '📄', 'who' => '🔎', 'compare' => '⚖️', 'history' => '🕓'];

    /** اسم أي رول (أساسي/مخصص) من الكتالوج */
    $roleName = fn (?string $k) => $k === null ? '—' : ($roles[$k]['label'] ?? __('enums.role.'.$k));

    /** نقطة الفعلي — مسموح/ممنوع */
    $dot = fn (bool $on) => '<span class="pm-dot '.($on ? 'on' : 'off').'" role="img" aria-label="'
        .e($on ? __('perm.allowed') : __('perm.denied')).'" title="'.e($on ? __('perm.allowed') : __('perm.denied')).'"></span>';

    /** شارة الفعلي بالكلام — للجداول والورقة */
    $state = fn (bool $on) => '<span class="pm-state '.($on ? 'on' : 'off').'">'
        .($on ? '✓ '.e(__('perm.allowed')) : '✗ '.e(__('perm.denied'))).'</span>';

    /** شارة المصدر + «يتبع صفحة X» لو افتراضيه جاي من صفحة */
    $src = function (string $source, ?string $via = null) {
        $html = '<span class="pm-src s-'.e($source).'" title="'.e(__('perm.src_hint_'.$source)).'">'.e(__('perm.src_'.$source)).'</span>';

        if ($via !== null) {
            $html .= ' <span class="pm-via">'.e(__('perm.via', ['page' => PermissionMatrix::labelOf($via)])).'</span>';
        }

        return $html;
    };

    /** شريحة الخطورة */
    $risk = fn (string $r) => '<span class="pm-risk r-'.e($r).'">'.e(PermissionCatalog::riskLabel($r)).'</span>';

    /** الاستثناء بالكلام: سماح / منع / وراثة */
    $ov = fn (?bool $v) => $v === null ? __('perm.seg_inherit') : ($v ? __('perm.seg_allow') : __('perm.seg_deny'));
@endphp

@section('title', __('perm.page_title'))

@section('actions')
    @if ($tab === 'sheet')
        <a class="btn sm green" href="{{ route('erp.perms.sheet.excel', ['role' => $sheetRole]) }}">⬇ {{ __('perm.sheet_excel') }}</a>
        <button class="btn sm" type="button" onclick="window.print()">🖨 {{ __('perm.sheet_print') }}</button>
    @endif
@endsection

@section('content')

<nav class="rng-q pm-tabs" aria-label="{{ __('perm.page_title') }}" data-noprint>
    @foreach (\App\Http\Controllers\PermissionController::TABS as $t)
        <a href="{{ $tabUrl($t) }}" @class(['on' => $tab === $t]) @if($tab === $t) aria-current="page" @endif>{{ $tabIcons[$t] }} {{ __('perm.tab_'.$t) }}</a>
    @endforeach
</nav>

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px" role="status"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif
@if ($errors->any())
    <div class="alert" style="margin-bottom:12px" role="alert"><span>⚠️</span>
        <span>@foreach ($errors->all() as $err)<div>{{ $err }}</div>@endforeach</span></div>
@endif

@include('erp.perms._'.$tab)

@endsection

@section('scripts')
<style>
/* ═══ شاشة الصلاحيات — نفس توكنز السيستم (البراند + الكروت والبادجات) ═══ */
/* ⚠️ `.alert`/`.pm-row` ليهم display صريح بيغلب خاصية hidden — الفلاتر والتحذيرات بتعتمد عليها */
[hidden]{display:none!important}
.pm-tabs{margin-bottom:14px;gap:6px;flex-wrap:wrap}
.pm-tabs a{font-size:13px;padding:8px 16px}
.pm-muted{color:var(--muted);font-size:12px}
.pm-key{font-family:ui-monospace,Consolas,monospace;font-size:10.5px;color:var(--muted);direction:ltr;unicode-bidi:isolate;word-break:break-all}

/* النقطة والحالة */
.pm-dot{display:inline-block;width:10px;height:10px;border-radius:50%;flex-shrink:0;vertical-align:middle}
.pm-dot.on{background:#16A34A;box-shadow:0 0 0 3px #E7F7EE}
.pm-dot.off{background:#C4C4CF;box-shadow:0 0 0 3px #F0EEE8}
.pm-state{display:inline-block;font-weight:800;font-size:12px;white-space:nowrap}
.pm-state.on{color:#0F7A38}
.pm-state.off{color:var(--muted)}

/* المصدر: كود رمادي · أساسي بنفسجي · الرول ده أزرق · يوزر برتقاني */
.pm-src{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800;white-space:nowrap}
.pm-src.s-code{background:#F0EEE8;color:var(--muted)}
.pm-src.s-base{background:#F1EAFD;color:var(--purple,#6D28D9)}
.pm-src.s-role{background:#E8EFFD;color:var(--royal-blue)}
.pm-src.s-user{background:#FDF1E3;color:#B96C0A}
.pm-via{font-size:10.5px;color:var(--muted);white-space:nowrap}

/* الخطورة */
.pm-risk{display:inline-block;padding:2px 9px;border-radius:6px;font-size:10.5px;font-weight:800;white-space:nowrap;border:1px solid transparent}
.pm-risk.r-low{background:#EEF7F1;color:#2F6B45}
.pm-risk.r-medium{background:#EEF2FB;color:#33508F}
.pm-risk.r-high{background:#FDF1E3;color:#9A5A08}
.pm-risk.r-critical{background:#FDECEC;color:#B00020;border-color:#F5B8BF}

/* التحكم التلاتي — سماح / منع / وراثة */
.pm-seg{display:inline-flex;border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--card);flex-shrink:0}
.pm-seg label{position:relative;display:block;margin:0;cursor:pointer}
.pm-seg input{position:absolute;opacity:0;pointer-events:none}
.pm-seg span{display:block;padding:5px 11px;font-size:11.5px;font-weight:700;color:var(--muted);white-space:nowrap;transition:background .12s,color .12s}
.pm-seg label+label span{border-inline-start:1px solid var(--border)}
.pm-seg label:hover span{color:var(--text);background:var(--card2)}
.pm-seg input:checked+span.v-1{background:#E7F7EE;color:#0F7A38}
.pm-seg input:checked+span.v-0{background:#FDECEC;color:var(--red)}
.pm-seg input:checked+span.v-{background:var(--blue-050);color:var(--royal-blue)}
.pm-seg input:focus-visible+span{outline:2px solid var(--royal-blue);outline-offset:-2px}
.pm-lock{font-size:11.5px;color:var(--muted);max-width:260px;line-height:1.5}

/* الأدوات */
.pm-bar{position:sticky;top:0;z-index:30;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;padding:10px 14px}
.pm-bar .pm-chk{display:flex;gap:6px;align-items:center;font-size:12px;font-weight:700;padding-bottom:9px;white-space:nowrap}
.pm-legend{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;font-size:11.5px;color:var(--muted);margin-top:10px}

@media print{
  .pm-tabs,.pm-bar,.pm-save{display:none!important}
}
</style>
@endsection
