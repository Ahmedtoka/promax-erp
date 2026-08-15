{{--
    شارات ناتج الزيارة + صور الرف (١٥ أغسطس ٢٠٢٦).

    الاستخدام:
      @include('partials._visit_outcome', ['o' => $vo])
      @include('partials._visit_outcome', ['o' => $vo, 'thumbs' => false])

    `$o` = صف من `App\Support\VisitOutcomes::map()` (أو `blank()`).
    ⚠️ الفلوس بالـgrand_total بمنزلتين — نفس عقيدة باقي الشاشات.
--}}
@php
    $thumbs = $thumbs ?? true;
    $money = fn ($n) => number_format((float) $n, 2);
@endphp

@foreach ($o['invoices'] as $iv)
    <a class="badge b-green" style="text-decoration:none"
       href="{{ route('ops.invoice', $iv->id) }}">🧾 {{ $iv->number }} · {{ $money($iv->grand_total) }}</a>
@endforeach

@if ($o['coll_count'] > 0)
    <span class="badge b-blue">💵 {{ $money($o['coll_total']) }}</span>
@endif

@if ($o['ret_count'] > 0)
    <span class="badge b-red">↩️ {{ $money($o['ret_total']) }}</span>
@endif

@if ($o['photo_count'] > 0)
    <span class="badge b-purple">📸 {{ $o['photo_count'] }}</span>
@endif

@if ($o['gift_count'] > 0)
    <span class="badge b-gold">🎁 {{ $o['gift_qty'] }}</span>
@endif

@if ($o['goods_count'] > 0)
    <span class="badge b-orange">📦 {{ $o['goods_count'] }}</span>
@endif

@if (! $o['any'])
    <span class="badge b-gray">{{ __('ops.vb_nothing') }}</span>
@endif

@if ($thumbs && $o['photo_count'] > 0)
    <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:5px">
        @foreach ($o['photos'] as $p)
            <a href="{{ $p->url() }}" target="_blank" rel="noopener"
               title="{{ $p->stage === 'before' ? __('field.shelf_before') : __('field.shelf_after') }}">
                <img src="{{ $p->url() }}" alt="" loading="lazy"
                     style="width:48px;height:48px;object-fit:cover;border-radius:7px;border:2px solid {{ $p->stage === 'before' ? 'var(--orange)' : 'var(--green)' }}">
            </a>
        @endforeach
    </div>
@endif
