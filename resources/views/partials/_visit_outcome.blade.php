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
    $cur = __('common.currency');
    $money = fn ($n) => number_format((float) $n, 2).' '.$cur;
@endphp

{{-- ⚠️ **كل شارة عليها لابل بالكلام** (بلاغ المالك ١٥/٨: «مش فاهم
     إيه الشحنة دي ومكتوب 1»). الأيقونة لوحدها مع رقم عريان كانت
     بتخلّي «📦 1» لغز — دلوقتي مكتوب «طلب بضاعة: 1». والفلوس
     بعملتها عشان الرقم مايتلخبطش مع عدد. --}}

@foreach ($o['invoices'] as $iv)
    <a class="badge b-green" style="text-decoration:none"
       title="{{ __('ops.vo_invoice_hint') }}"
       href="{{ route('ops.invoice', $iv->id) }}">🧾 {{ __('ops.invoice') }}
        <span dir="ltr">{{ $iv->number }}</span> · {{ $money($iv->grand_total) }}
        · {{ $iv->payment === 'cash' ? __('enums.payment.cash') : __('enums.payment.credit') }}</a>
@endforeach

@if ($o['coll_count'] > 0)
    <span class="badge b-blue" title="{{ __('ops.vo_collection_hint') }}">
        💵 {{ __('ops.vo_collection') }}: {{ $money($o['coll_total']) }}</span>
@endif

@if ($o['ret_count'] > 0)
    <span class="badge b-red" title="{{ __('ops.vo_return_hint') }}">
        ↩️ {{ __('ops.vo_return') }}: {{ $money($o['ret_total']) }}</span>
@endif

@if ($o['photo_count'] > 0)
    <span class="badge b-purple" title="{{ __('ops.vo_photos_hint') }}">
        📸 {{ __('ops.vo_photos') }}: {{ $o['photo_count'] }}</span>
@endif

@if ($o['gift_count'] > 0)
    <span class="badge b-gold" title="{{ __('ops.vo_gift_hint') }}">
        🎁 {{ __('ops.vo_gift') }}: {{ number_format($o['gift_qty']) }} {{ __('common.piece') }}</span>
@endif

@if ($o['goods_count'] > 0)
    {{-- دي اللي كانت «📦 1» — طلب بضاعة (ريفيل) اتعمل من الزيارة --}}
    <span class="badge b-orange" title="{{ __('ops.vo_goods_hint') }}">
        📦 {{ __('ops.vo_goods') }}: {{ $o['goods_count'] }}</span>
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
