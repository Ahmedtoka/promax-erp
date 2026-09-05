@extends('layouts.system')

{{--
    ترصيف إذن الاستلام يدوي (٥/٩/٢٠٢٦ — نظام الاستاندات A–J):

    كل باتش لسه مترصّفش بياخد صف فيه دروب منيو بأرفف المخزن وكمية
    متملية بالباقي — أمين المخزن بيختار ويدوس حفظ. سطر من غير رف
    بيتساب زي ما هو. وزرار الترصيف الأوتوماتيك (بالبلوكات) لسه موجود
    تحت لو حابب يستخدمه.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $canAct = auth()->user()->canWorkWarehouse()
        && \App\Support\Access::action(auth()->user(), 'act.wh.putaway');
    $pending = $receipt->batches->filter(fn ($b) => $b->unshelvedQty() > 0);
@endphp

@section('title', __('stock.pa_title', ['number' => $receipt->number]))

@section('actions')
    <a class="btn" href="{{ route('wh.receipt', $receipt) }}">← {{ __('stock.goods_receipt') }} {{ $receipt->number }}</a>
    <a class="btn" href="{{ route('wh.locations', ['warehouse' => $receipt->warehouse_id]) }}">🗄️ {{ __('stock.shelf_map') }}</a>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif
@if ($errors->any())
    <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)
            <div class="errline" style="margin:0">{{ $msg }}</div>
        @endforeach
    </div>
@endif

<div class="card">
    <h3>📥 {{ __('stock.pa_title', ['number' => $receipt->number]) }}
        <span class="side">{{ __('stock.pa_hint') }}</span></h3>

    @if ($pending->isEmpty())
        <div class="alert good"><span>✅</span><span>{{ __('stock.pa_all_done') }}</span></div>
    @else
        <form method="POST" action="{{ route('wh.receipt.putaway.save', $receipt) }}">
            @csrf
            <div class="tablewrap">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('stock.batch') }}</th>
                            <th>{{ __('stock.product') }}</th>
                            <th>{{ __('stock.produced_on') }}</th>
                            <th>{{ __('stock.expires_on') }}</th>
                            <th>{{ __('stock.pa_left') }}</th>
                            <th>{{ __('stock.pa_on') }}</th>
                            <th>{{ __('stock.location') }}</th>
                            <th>{{ __('stock.qty') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pending as $b)
                            <tr>
                                <td class="num"><b>{{ $b->batch_no }}</b></td>
                                <td>{{ $b->product?->displayName() ?? '—' }}</td>
                                <td class="num">{{ $b->produced_on?->format('Y-m-d') ?? '—' }}</td>
                                <td class="num">{{ $b->expires_on?->format('Y-m-d') ?? '—' }}</td>
                                <td class="num"><b>{{ $fmt($b->unshelvedQty()) }}</b></td>
                                <td>
                                    @forelse ($b->locations->where('qty', '>', 0) as $bl)
                                        <span class="badge b-blue">{{ $bl->location?->code }} × {{ $fmt($bl->qty) }}</span>
                                    @empty
                                        <span style="color:var(--muted)">—</span>
                                    @endforelse
                                </td>
                                <td>
                                    <select name="rows[{{ $b->id }}][location_id]" @disabled(! $canAct)>
                                        <option value="">{{ __('stock.pa_choose') }}</option>
                                        @foreach ($locations as $loc)
                                            <option value="{{ $loc->id }}">{{ $loc->code }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="rows[{{ $b->id }}][qty]" min="1"
                                           value="{{ $b->unshelvedQty() }}" style="width:90px" @disabled(! $canAct)>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($canAct)
                <div style="display:flex;gap:8px;margin-top:12px;align-items:center;flex-wrap:wrap">
                    <button class="btn gold" type="submit">💾 {{ __('stock.pa_save') }}</button>
                </div>
            @endif
        </form>

        @if ($canAct)
            {{-- الترصيف الأوتوماتيك القديم (بالبلوكات حسب الصلاحية) — فورم مستقل --}}
            <form method="POST" action="{{ route('wh.receipt.putaway', $receipt) }}" style="margin-top:8px"
                  onsubmit="return confirm(@js(__('stock.putaway_receipt_confirm')))">
                @csrf
                <button class="btn sm" type="submit">⚡ {{ __('stock.pa_auto') }}</button>
            </form>
        @endif
    @endif
</div>

@endsection
