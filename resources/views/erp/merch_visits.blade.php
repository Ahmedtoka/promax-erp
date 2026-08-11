@extends('layouts.system')

@section('title', __('ops.merch_visits'))

@section('content')

<div class="card">
    <form class="searchbar" method="GET">
        <select name="user">
            <option value="">{{ __('ops.all_merchandisers') }}</option>
            @foreach ($promoters as $p)
                <option value="{{ $p->id }}" @selected((int) ($filters['user'] ?? 0) === $p->id)>{{ $p->name }}</option>
            @endforeach
        </select>
        <input type="date" name="date" value="{{ $filters['date'] ?? '' }}">
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.merch') }}">{{ __('common.clear') }}</a>
        <span class="badge b-gray">{{ __('ops.visit_countable', ['count' => $visits->total()]) }}</span>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.branch') }}</th><th>{{ __('ops.merchandiser') }}</th><th>{{ __('ops.checked_in') }}</th><th>{{ __('ops.duration') }}</th>
                <th>{{ __('ops.moved_to_shelf') }}</th><th>{{ __('ops.short') }}</th><th>{{ __('ops.shelf_photos') }}</th><th>{{ __('ops.items') }}</th>
            </tr>
            @forelse ($visits as $v)
                <tr>
                    <td><b>{{ $v->client->displayName() }}</b>
                        @if ($v->client->channel)
                            <br><span class="badge {{ $v->client->channel->badgeClass() }}">{{ $v->client->channel->displayName() }}</span>
                        @endif
                    </td>
                    <td>{{ $v->user->displayName() }}</td>
                    <td class="num">{{ $v->checked_in_at?->format('m-d h:i A') ?? '—' }}</td>
                    <td class="num">{{ $v->minutes() !== null ? __('ops.minutes', ['count' => $v->minutes()]) : __('ops.in_progress') }}</td>
                    <td class="num pos"><b>{{ $v->movedTotal() }}</b></td>
                    <td class="num {{ $v->outOfStockCount() > 0 ? 'neg' : '' }}">{{ $v->outOfStockCount() }}</td>
                    <td>
                        @if ($v->photoBeforeUrl())
                            <a class="btn sm" href="{{ $v->photoBeforeUrl() }}" target="_blank">{{ __('ops.before') }}</a>
                        @endif
                        @if ($v->photoAfterUrl())
                            <a class="btn sm" href="{{ $v->photoAfterUrl() }}" target="_blank">{{ __('ops.after') }}</a>
                        @endif
                        @if (! $v->photoBeforeUrl() && ! $v->photoAfterUrl())—@endif
                    </td>
                    <td style="white-space:normal;max-width:300px;font-size:11px">
                        @foreach ($v->refills as $r)
                            <div style="color:{{ $r->out_of_stock ? 'var(--red)' : 'inherit' }}">
                                {{ $r->product->displayName() }}:
                                @if ($r->out_of_stock)
                                    {{ __('ops.out_of_stock') }}
                                @else
                                    {{ $r->shelf_before }} ← {{ $r->shelfAfter() }}
                                    <span style="color:var(--muted)">({{ __('ops.from_store_room_qty', ['qty' => $r->moved_qty]) }})</span>
                                @endif
                            </div>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.no_visits') }}</td></tr>
            @endforelse
        </table>
    </div>
    <div class="pag">{{ $visits->links('pagination::simple-default') }}</div>
</div>

@endsection
