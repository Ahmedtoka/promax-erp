{{-- كارت مهمة في بورد «مهامي» — $t المهمة و$who الطرف المعروض (creator) --}}
<a href="{{ route('erp.tasks.show', $t) }}" class="tk-card" style="display:block;text-decoration:none;color:inherit;
        background:#fff;border:1px solid var(--border);border-radius:12px;padding:10px 12px;margin-bottom:8px">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <b style="font-size:13px;flex:1;min-width:0">{{ $t->title }}</b>
        <span class="badge {{ $t->priorityBadge() }}" style="font-size:9.5px">{{ $prLabel($t) }}</span>
    </div>
    <div style="display:flex;gap:10px;font-size:11px;color:var(--muted);margin-top:5px;flex-wrap:wrap">
        <span>👤 {{ $t->{$who}?->displayName() ?? '—' }}</span>
        @if ($t->deadline)
            <span dir="ltr" @if($t->isLate()) style="color:var(--red,#DC2626);font-weight:800" @endif>
                🗓 {{ $t->deadline->format('d/m H:i') }}</span>
        @endif
        @if ($t->status === 'submitted')<span class="badge b-orange" style="font-size:9px">⏳ {{ __('tasks.st_submitted') }}</span>@endif
        @if ($t->status === 'approved')<span class="badge b-green" style="font-size:9px">✓ {{ __('tasks.st_approved') }}</span>@endif
        @if ($t->rejections > 0)<span class="badge b-red" style="font-size:9px">↩️ ×{{ $t->rejections }}</span>@endif
    </div>
</a>
