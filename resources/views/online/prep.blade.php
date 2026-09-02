@extends('layouts.system')

@section('title', __('online.prep_title'))

@php
    $canPrep = in_array(auth()->user()->role, ['admin', 'manager', 'warehouse_keeper'], true);
@endphp

@section('content')

@if ($errors->any())
    <div class="alert" style="margin-bottom:12px">{{ $errors->first() }}</div>
@endif
@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

<div class="card">
    <h3>📦 {{ __('online.prep_title') }}</h3>
    <div class="dash-hint" style="margin-bottom:12px">{{ __('online.prep_hint') }}</div>

    @forelse ($picks as $pick)
        @php $o = $orders[$pick->id] ?? null; @endphp
        <div class="card" style="margin-bottom:12px;border:1.5px solid var(--border)">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div>
                    <b style="font-size:15px">{{ $pick->number }}</b>
                    <span class="badge {{ $pick->statusClass() }}">{{ $pick->statusLabel() }}</span>
                    @if ($o !== null)
                        <div style="font-size:12px;color:var(--muted);margin-top:3px">
                            👤 {{ $o->customer_name ?: '—' }} · 📞 <span dir="ltr">{{ $o->phone ?: '—' }}</span>
                            · 📍 {{ $o->area ?: '—' }}
                        </div>
                    @endif
                </div>
                @if ($canPrep)
                    <div style="display:flex;gap:6px">
                        @if ($pick->status === 'requested')
                            <form method="POST" action="{{ route('online.prep.start', $pick) }}">
                                @csrf
                                <button class="btn gold" type="submit">▶ {{ __('online.prep_start') }}</button>
                            </form>
                        @elseif ($pick->status === 'picking')
                            <form method="POST" action="{{ route('online.prep.done', $pick) }}"
                                  onsubmit="return confirm(PREP_DONE_MSG)">
                                @csrf
                                <button class="btn green" type="submit">✅ {{ __('online.prep_finish') }}</button>
                            </form>
                        @elseif ($pick->status === 'ready' && $o !== null)
                            <a class="btn" href="{{ route('online.invoice', $o) }}" target="_blank">
                                🖨 {{ __('online.print_invoice') }}</a>
                        @endif
                    </div>
                @endif
            </div>

            <div class="tablewrap" style="margin-top:10px">
                <table>
                    <tr>
                        <th>{{ __('stock.product') }}</th>
                        <th>{{ __('online.batch') }}</th>
                        <th>{{ __('online.shelf') }}</th>
                        <th class="num">{{ __('common.qty') }}</th>
                    </tr>
                    @foreach ($pick->items as $item)
                        <tr>
                            <td>{{ $item->product?->displayName() ?? '#'.$item->product_id }}</td>
                            <td class="s">{{ $item->batchNo() }}</td>
                            <td class="s">{{ $item->locationCode() }}</td>
                            <td class="num"><b>{{ $item->qty_requested }}</b></td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @empty
        <div style="text-align:center;color:var(--muted);padding:28px">{{ __('online.prep_empty') }}</div>
    @endforelse
</div>

@endsection

@section('scripts')
<script>
    const PREP_DONE_MSG = @js(__('online.prep_done_msg'));
</script>
@endsection
