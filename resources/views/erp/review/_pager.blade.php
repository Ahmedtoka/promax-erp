{{--
    ترقيم شاشات المراجعة (١٢/١١): «عرض ١–٢٥ من ٢٢٢» + كام في الصفحة + الصفحات.
    @include('erp.review._pager', ['p' => $paginator, 'per' => 25])

    ⚠️ كلاس `pag` مقصود: سكريبت الجداول العام بيدوّر عليه عشان يعرف إن الترقيم
    من السيرفر — فمايجمعش صفحة واحدة كأنها الإجمالي ولا يحط ترقيم تاني فوقه.
    لازم يبقى جوه نفس الـ`.card` اللي فيها الجدول.
--}}
@php
    $cur = $p->currentPage();
    $last = max(1, $p->lastPage());
    $win = collect(range(max(1, $cur - 2), min($last, $cur + 2)));
    if (! $win->contains(1)) { $win->prepend(1); }
    if (! $win->contains($last)) { $win->push($last); }
    $from = $p->total() ? ($cur - 1) * $p->perPage() + 1 : 0;
    $to = min($p->total(), $cur * $p->perPage());
    $sizes = $sizes ?? [25, 50, 100, 200];
@endphp
<div class="rv-pager pag" data-noprint>
    <span class="info">{!! __('review.pager_info', ['from' => '<b>'.number_format($from).'</b>', 'to' => '<b>'.number_format($to).'</b>', 'n' => '<b>'.number_format($p->total()).'</b>']) !!}</span>
    <label class="rv-sub">{{ __('review.pager_per') }}
        <select onchange="location.href=this.value" aria-label="{{ __('review.pager_per') }}">
            @foreach ($sizes as $n)
                <option value="{{ request()->fullUrlWithQuery(['per' => $n, 'page' => null, 'doc' => null]) }}" @selected((int) ($per ?? $p->perPage()) === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </label>
    @if ($last > 1)
        @if ($cur > 1)<a href="{{ $p->url($cur - 1) }}" rel="prev" aria-label="‹">‹</a>@endif
        @php $prev = 0; @endphp
        @foreach ($win as $n)
            @if ($n - $prev > 1)<span class="gap">…</span>@endif
            @if ($n === $cur)<span class="cur" aria-current="page">{{ $n }}</span>@else<a href="{{ $p->url($n) }}">{{ $n }}</a>@endif
            @php $prev = $n; @endphp
        @endforeach
        @if ($cur < $last)<a href="{{ $p->url($cur + 1) }}" rel="next" aria-label="›">›</a>@endif
    @endif
</div>
