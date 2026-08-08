@extends('layouts.system')

@section('title', __('field.return_doc'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);

    // ⚠️ **مجمّعة بالصنف هنا في الفيو الأم مش في الجدول.** الخدمة
    // بتوزّع الكمية على سطور الفواتير بسعر كل واحدة وقت الحفظ —
    // الشاشة بتوري إجمالي متاح وسعر آخر فاتورة للاسترشاد.
    $byProduct = [];

    foreach ($lines as $l) {
        $pid = $l['product_id'];
        $byProduct[$pid] ??= [
            'product' => $l['product'],
            'qty' => 0,
            'price' => $l['price'],
            'invoice' => $l['invoice_number'],
        ];
        $byProduct[$pid]['qty'] += $l['qty'];
    }
@endphp

@section('content')

<div class="card">
    <h3>{{ __('field.return_doc') }}</h3>

    {{-- اختيار العميل — الشاشة بتتعاد بالمتاح للرد بتاعه --}}
    <form class="searchbar" method="GET">
        <select name="client" onchange="this.form.submit()">
            <option value="">— {{ __('client.client') }} —</option>
            @foreach ($clients as $c)
                <option value="{{ $c->id }}" @selected($client && $client->id === $c->id)>
                    {{ $c->code }} — {{ $c->displayName() }}</option>
            @endforeach
        </select>
        <noscript><button class="btn gold" type="submit">{{ __('common.filter') }}</button></noscript>
    </form>

    @if ($client === null)
        <div class="alert info">{{ __('client.pick_client_first') }}</div>
    @elseif (empty($byProduct))
        {{-- ⚠️ رسالة صريحة بدل جدول فاضي — «مفيش صفوف» بتخلّي
             المستخدم يفتكر إن الشاشة بايظة. --}}
        <div class="alert warn">{{ __('field.return_none_returnable') }}</div>
    @else
        <form method="POST" action="{{ route('ops.returns.store') }}">
            @csrf
            <input type="hidden" name="client_id" value="{{ $client->id }}">

            <div class="frow">
                <label class="f">
                    <span>{{ __('field.return_policy') }} *</span>
                    {{-- ⚠️ **المسموح للعميل بس** — السيرفر بيفحصها تاني
                         في `Returns::create`، فالقايمة دي راحة مش حماية. --}}
                    <select name="policy" required>
                        @foreach ($policies as $p)
                            <option value="{{ $p }}">{{ __('field.return_policy_'.$p) }}</option>
                        @endforeach
                    </select>
                </label>
                {{-- ⚠️ **اختياري.** فاضي = البضاعة رجعت المخزن مباشرة.
                     لو اتحدد مندوب، البضاعة بتنزل في عهدته وبتظهر في
                     تصفيته — والسيرفر بيرفض لو مالوش عهدة مفتوحة. --}}
                <label class="f">
                    <span>{{ __('ops.rep') }}</span>
                    <select name="user_id">
                        <option value="">— {{ __('common.office') }} —</option>
                        @foreach ($reps as $r)
                            <option value="{{ $r->id }}">{{ $r->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="f">
                    <span>{{ __('common.note') }}</span>
                    <input type="text" name="note" maxlength="500">
                </label>
            </div>

            <div class="alert info">{{ __('field.return_cond_hint') }}</div>

            <div class="tablewrap">
                <table>
                    <tr>
                        <th>{{ __('stock.product') }}</th>
                        <th>{{ __('field.return_returnable') }}</th>
                        <th>{{ __('price.unit_price') }}</th>
                        <th>{{ __('common.qty') }}</th>
                        <th>{{ __('field.return_cond') }}</th>
                    </tr>
                    @foreach ($byProduct as $pid => $row)
                        <tr>
                            <td><b>{{ $row['product']?->displayName() ?? '—' }}</b>
                                <br><span style="font-size:11px;color:var(--muted)">{{ $row['invoice'] }}</span></td>
                            <td class="num">{{ number_format($row['qty']) }}</td>
                            <td class="num">{{ $fmt($row['price']) }}</td>
                            <td class="num">
                                {{-- ⚠️ `max` بيمنع الغلط في المتصفح، والسيرفر
                                     بيرفض الزيادة برضه — الاتنين مطلوبين. --}}
                                <input type="number" name="qty[{{ $pid }}]" min="0"
                                       max="{{ $row['qty'] }}" value="0" style="width:90px">
                            </td>
                            <td>
                                <select name="condition[{{ $pid }}]">
                                    <option value="good">{{ __('field.return_cond_good') }}</option>
                                    <option value="damaged">{{ __('field.return_cond_damaged') }}</option>
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            <div style="margin-top:14px">
                <button class="btn green" type="submit">{{ __('common.save') }}</button>
                <a class="btn" href="{{ route('ops.returns') }}">{{ __('common.cancel') }}</a>
            </div>
        </form>
    @endif
</div>

@endsection
