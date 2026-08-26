{{-- كارت مهمة في بورد «مهامي» — $t المهمة و$who الطرف المعروض (creator).
     data-search/pr/st = خامة الفلترة اللايف في tasks.blade.
     الحرف الجانبي الملون = الأولوية (عاجلة حمرا / مهمة برتقالي /
     عادية زرقا / منخفضة رمادي) — والمتأخرة بتغلب بالأحمر. --}}
@php
    $tkSt = $t->isLate() ? 'late' : $t->status;
    $tkEdge = $t->isLate() ? '#DC2626' : match ($t->priority) {
        'urgent' => '#DC2626',
        'high' => '#B96C0A',
        'low' => '#9AA0AA',
        default => '#12399B',
    };
    $tkWho = $t->{$who};
@endphp
<a href="{{ route('erp.tasks.show', $t) }}" class="tk-card"
   data-search="{{ mb_strtolower($t->title.' '.($tkWho?->displayName() ?? '')) }}"
   data-pr="{{ $t->priority }}" data-st="{{ $tkSt }}" data-emp="{{ $t->assigned_to }}"
   style="display:block;text-decoration:none;color:inherit;background:#fff;
        border:1px solid var(--border);border-inline-start:4px solid {{ $tkEdge }};
        border-radius:12px;padding:10px 12px;margin-bottom:8px">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <b style="font-size:13px;flex:1;min-width:0">{{ $t->title }}</b>
        <span class="badge {{ $t->priorityBadge() }}" style="font-size:9.5px">{{ $prLabel($t) }}</span>
    </div>
    <div style="display:flex;gap:8px;font-size:11px;color:var(--muted);margin-top:7px;flex-wrap:wrap;align-items:center">
        @if ($tkWho)
            @include('partials._avatar', ['u' => $tkWho, 'size' => 20])
            <span style="font-weight:700">{{ $tkWho->displayName() }}</span>
        @endif
        @if ($t->deadline)
            <span dir="ltr" @if($t->isLate()) style="color:var(--red,#DC2626);font-weight:800" @endif>
                🗓 {{ $t->deadline->format('d/m H:i') }}</span>
        @endif
        <span style="margin-inline-start:auto;display:flex;gap:4px">
            @if ($t->status === 'submitted')<span class="badge b-orange" style="font-size:9px">⏳ {{ __('tasks.st_submitted') }}</span>@endif
            @if ($t->status === 'approved')<span class="badge b-green" style="font-size:9px">✓ {{ __('tasks.st_approved') }}</span>@endif
            @if ($t->rejections > 0)<span class="badge b-red" style="font-size:9px">↩️ ×{{ $t->rejections }}</span>@endif
        </span>
    </div>
</a>
