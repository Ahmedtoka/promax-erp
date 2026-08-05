{{--
    ترقيم صفحات مرقّم — بديل `simple-default` اللي بيوري سابق/تالي بس.

    الاستخدام: @include('partials._pagination', ['p' => $clients])
    محتاج LengthAwarePaginator (يعني `paginate()` مش `simplePaginate()`).

    بيوري: ‹ 1 … 4 [5] 6 … 12 › + «صفحة 5 من 12 — 583».
--}}

@php
    $current = $p->currentPage();
    $last = max(1, $p->lastPage());

    // نافذة ±2 حوالين الحالية + الأولى والأخيرة دايماً
    $pages = collect(range(max(1, $current - 2), min($last, $current + 2)));
    if (! $pages->contains(1)) {
        $pages = $pages->prepend(1);
    }
    if (! $pages->contains($last)) {
        $pages = $pages->push($last);
    }
@endphp

@if ($p->total() > 0)
<div class="pag" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:12px">
    @if (! $p->onFirstPage())
        <a class="btn sm" href="{{ $p->previousPageUrl() }}">‹</a>
    @endif

    @php $prev = 0; @endphp
    @foreach ($pages as $n)
        @if ($n - $prev > 1)
            <span style="color:var(--muted)">…</span>
        @endif
        @if ($n === $current)
            <span class="btn sm gold" style="pointer-events:none">{{ $n }}</span>
        @else
            <a class="btn sm" href="{{ $p->url($n) }}">{{ $n }}</a>
        @endif
        @php $prev = $n; @endphp
    @endforeach

    @if ($p->hasMorePages())
        <a class="btn sm" href="{{ $p->nextPageUrl() }}">›</a>
    @endif

    <span style="font-size:11.5px;color:var(--muted);margin-inline-start:auto">
        {{ __('common.page_of', ['p' => $current, 'n' => $last]) }}
        — <b style="color:var(--ink)">{{ number_format($p->total()) }}</b>
    </span>
</div>
@endif
