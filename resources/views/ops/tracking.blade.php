@extends('layouts.system')

@section('title', __('ops.tracking'))

@section('content')

<div class="card">
    <form class="searchbar" method="GET">
        <select name="user">
            <option value="">{{ __('ops.all_reps') }}</option>
            @foreach ($field as $f)
                <option value="{{ $f->id }}" @selected($userId === $f->id)>{{ $f->name }} ({{ $f->code }})</option>
            @endforeach
        </select>
        <input type="date" name="date" value="{{ $date }}">
        <button class="btn gold" type="submit">{{ __('ops.show') }}</button>
        <span class="badge b-gray">{{ $events->count() }} {{ trans_choice('ops.event', $events->count()) }}</span>
    </form>

    <div class="grid2">
        <div>
            <div class="mapbox" id="map"></div>
            <div style="font-size:11px;color:var(--muted);margin-top:6px;display:flex;gap:12px;flex-wrap:wrap">
                <span>🟣 {{ __('ops.day_start') }}</span><span>🔵 {{ __('ops.arrival') }}</span><span>🟢 {{ __('ops.invoice_or_delivery') }}</span>
                <span>🔴 {{ __('ops.departure') }}</span><span>🟠 {{ __('field.replenishment') }}</span>
                <span style="margin-inline-start:auto">{{ __('ops.map_zoom_hint') }}</span>
            </div>
        </div>
        <div style="max-height:340px;overflow-y:auto">
            <div class="alerts">
                @forelse ($events as $e)
                    @php $cls = match ($e->type) { 'sale','deliver' => 'good', 'check_in','start' => 'info', 'request' => 'warn', default => '' }; @endphp
                    <div class="alert {{ $cls }}">
                        <div>
                            <b>{{ $e->happened_at->format('H:i') }} — {{ $e->user->displayName() }}:</b> {{ $e->title }}
                            @if ($e->subtitle)<br><span style="color:var(--muted);font-size:11.5px">{{ $e->subtitle }}</span>@endif
                        </div>
                    </div>
                @empty
                    <div style="text-align:center;color:var(--muted);padding:30px">{{ __('ops.no_movements_that_day') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
@php
    // بترتيب الوقت من الأقدم للأحدث عشان المسار يبقى صح
    $pts = $events->reverse()->values()
        ->filter(fn ($e) => $e->lat && $e->lng)
        ->map(fn ($e) => [
            'lat' => (float) $e->lat,
            'lng' => (float) $e->lng,
            'title' => $e->title,
            'subtitle' => $e->user->displayName().' • '.$e->happened_at->format('H:i')
                .($e->subtitle ? ' • '.$e->subtitle : ''),
            'type' => $e->type,
        ])->values();
@endphp
<script>
promaxMap('map', {!! json_encode($pts, JSON_UNESCAPED_UNICODE) !!}, { route: true });
</script>
@endsection
