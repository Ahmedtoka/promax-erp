@extends('layouts.system')

@section('title', __('ops.invoices'))

@php $fmt = fn ($n) => number_format((float) $n); @endphp

@section('content')

<div class="card">
    <form class="searchbar" method="GET">
        <select name="user">
            <option value="">{{ __('ops.all_reps') }}</option>
            @foreach ($field as $f)
                <option value="{{ $f->id }}" @selected((int) ($filters['user'] ?? 0) === $f->id)>{{ $f->name }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}">
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}">
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.invoices') }}">{{ __('common.clear') }}</a>
        <span class="badge b-green">{{ __('common.total') }}: {{ $fmt($sum) }} {{ __('common.currency') }}</span>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('ops.invoice') }}</th><th>{{ __('client.client') }}</th><th>{{ __('ops.rep') }}</th>
                <th>{{ __('ops.payment') }}</th><th>{{ __('common.subtotal') }}</th><th>{{ __('common.discount') }}</th>
                <th>{{ __('common.total') }}</th><th>{{ __('common.date') }}</th>
            </tr>
            @forelse ($invoices as $inv)
                <tr class="clickable" onclick="location.href='{{ route('ops.invoice', $inv) }}'">
                    <td><b>{{ $inv->number }}</b></td>
                    <td>{{ $inv->client->displayName() }}</td>
                    <td style="color:var(--muted)">{{ $inv->user->displayName() }}</td>
                    <td><span class="badge {{ $inv->payment === 'cash' ? 'b-green' : 'b-orange' }}">{{ $inv->paymentLabel() }}</span></td>
                    <td class="num">{{ $fmt($inv->subtotal) }}</td>
                    <td class="num mid">{{ $fmt($inv->discount) }}</td>
                    <td class="num pos"><b>{{ $fmt($inv->total) }}</b></td>
                    <td class="num">{{ $inv->created_at->format('Y-m-d h:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.no_invoices_found') }}</td></tr>
            @endforelse
        </table>
    </div>
    <div class="pag">{{ $invoices->links('pagination::simple-default') }}</div>
</div>

@endsection
