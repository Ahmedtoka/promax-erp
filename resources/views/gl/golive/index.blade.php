@extends('layouts.system')

{{--
    معالج تشغيل دفتر الأستاذ (الخط ٣، ١٩/١٠/٢٠٢٦) — شريط الخطوات التسعة
    على الجنب بلون حالة كل خطوة (حية)، والخطوة المفتوحة في النص. «تأكيد
    الخطوة» أدمن بس، ومقفول لو فيها أحمر. الحالة بتتحسب كل مرة — خطوة
    اتأكدت وبعدين بقت حمرا بتبان حمرا تاني.
--}}

@section('title', __('golive.title'))

@php
    $canAdmin = \App\Support\Access::action(auth()->user(), 'act.gl.admin');
    $lvlBadge = ['red' => 'b-red', 'yellow' => 'b-orange', 'green' => 'b-green', 'todo' => 'b-gray'];
    $lvlIcon = ['red' => '⛔', 'yellow' => '⚠️', 'green' => '✓', 'todo' => '…'];
    $cur = $states[$n]['state'];
@endphp

@section('content')
<style>
.gw{display:grid;grid-template-columns:240px 1fr;gap:16px;align-items:start}
@media (max-width:900px){.gw{grid-template-columns:1fr}}
.gw-rail{display:flex;flex-direction:column;gap:6px}
.gw-rail a{display:flex;gap:8px;align-items:center;padding:9px 11px;border-radius:10px;background:var(--card);border:1px solid var(--border);text-decoration:none;color:inherit;font-size:13px}
.gw-rail a.on{border-color:var(--royal-blue,#12399B);box-shadow:var(--shadow)}
.gw-rail .no{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;background:var(--card2)}
.gw-rail .no.red{background:#FDECEC;color:var(--red)}
.gw-rail .no.yellow{background:#FDF1E3;color:#B96C0A}
.gw-rail .no.green{background:#E7F7EE;color:#0F7A38}
.gw-rail .t{flex:1}
.gw-rail .d{font-size:11px;color:var(--muted)}
</style>

<div class="gw">
    <nav class="gw-rail" aria-label="{{ __('golive.title') }}">
        @foreach ($steps as $k => $s)
            @php $st = $states[$k]['state']; @endphp
            <a href="{{ route('gl.golive.show', $k) }}" class="{{ $k === $n ? 'on' : '' }}">
                <span class="no {{ $st->level }}">{{ $k }}</span>
                <span class="t">{{ __($s->titleKey()) }}
                    @if ($states[$k]['done'])<span class="d">· {{ __('golive.done') }}</span>@endif
                    @if ($k === $current)<span class="d">· {{ __('golive.current') }}</span>@endif
                </span>
                <span title="{{ __('golive.level_'.$st->level) }}">{{ $lvlIcon[$st->level] }}</span>
            </a>
        @endforeach
    </nav>

    <div>
        <div class="card">
            <h3>{{ $n }}. {{ __($step->titleKey()) }}
                <span class="side"><span class="badge {{ $lvlBadge[$cur->level] }}">{{ __('golive.level_'.$cur->level) }}</span></span>
            </h3>

            @if (session('err'))
                <div class="alert" style="margin-bottom:10px"><span>⛔</span><span>{{ session('err') }}</span></div>
            @endif

            @if ($cur->messages !== [])
                <div class="alert {{ $cur->level === 'red' ? '' : ($cur->level === 'yellow' ? 'warn' : 'info') }}" style="margin-bottom:10px">
                    <span>{{ $lvlIcon[$cur->level] }}</span>
                    <span>@foreach ($cur->messages as $m)<div>• {{ $m }}</div>@endforeach</span>
                </div>
            @endif

            @include($step->view(), $data + ['canAdmin' => $canAdmin])

            @if ($canAdmin)
                <form method="POST" action="{{ route('gl.golive.confirm', $n) }}" style="margin-top:14px;display:flex;gap:8px;align-items:center">
                    @csrf
                    <button class="btn green" type="submit" @disabled($cur->blocks())>✓ {{ __('golive.confirm_step') }}</button>
                    @if ($cur->blocks())<span class="sub2" style="font-size:12px;color:var(--muted)">{{ __('golive.confirm_disabled') }}</span>@endif
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
