@extends('layouts.system')

@section('title', __('mfg.boms_title'))

@section('content')
@php
    $badge = ['draft' => 'b-gray', 'approved' => 'b-green', 'archived' => 'b-purple'];
    $canEdit = \App\Support\Access::action(auth()->user(), 'act.mfg.boms_edit');
@endphp

@if ($canEdit)
{{-- إصدار جديد = مسودة بالرقم اللي بعده، منسوخة من آخر إصدار؛ المنتجات التامة بس --}}
<div class="card" data-noprint>
    <h3>➕ {{ __('mfg.new_bom') }} <span class="side">{{ __('mfg.new_bom_hint') }}</span></h3>
    <form method="POST" action="{{ route('mfg.boms.store') }}" class="searchbar">
        @csrf
        <label class="fl"><span>{{ __('mfg.col_product') }} *</span>
        <select name="product_id" required>
            <option value="">—</option>
            @foreach ($products as $p)
                <option value="{{ $p->id }}" @selected((string) old('product_id') === (string) $p->id)>{{ $p->code }} — {{ $p->displayName() }}</option>
            @endforeach
        </select></label>
        <button class="btn" type="submit">{{ __('mfg.new_bom') }}</button>
    </form>
</div>
@endif

<div class="card">
    <h3>🧾 {{ __('mfg.boms_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $boms->total()]) }}</span>
    </h3>

    {{-- فلتر «من — إلى» على `updated_at` — آخر تعديل للتركيبة --}}
    <form class="searchbar" method="GET" data-noprint>
        <label class="fl"><span>{{ __('mfg.col_product') }}</span>
        <select name="product_id">
            <option value="">{{ __('mfg.all_products') }}</option>
            @foreach ($products as $p)
                <option value="{{ $p->id }}" @selected(($filters['product_id'] ?? null) === $p->id)>{{ $p->displayName() }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status">
            <option value="">{{ __('mfg.all_statuses') }}</option>
            @foreach (\App\Models\Bom::STATUSES as $st)
                <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ __('mfg.bom_status_'.$st) }}</option>
            @endforeach
        </select></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.boms') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('mfg.col_product') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_version') }}</th>
                <th>{{ __('mfg.col_status') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_lines') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_waste_pct') }}</th>
                <th data-nosum>{{ __('mfg.col_updated_at') }}</th>
            </tr>
            @forelse ($boms as $b)
                <tr class="clickable" onclick="location.href='{{ route('mfg.boms.show', $b) }}'">
                    <td><a href="{{ route('mfg.boms.show', $b) }}">{{ $b->product?->displayName() ?? '—' }}</a></td>
                    <td class="num">{{ $b->version }}</td>
                    <td><span class="badge {{ $badge[$b->status] ?? 'b-gray' }}">{{ $b->statusLabel() }}</span></td>
                    <td class="num">{{ $b->lines_count }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $b->waste_pct, 2, '.', ''), '0'), '.') }}</td>
                    <td class="num s">{{ $b->updated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_boms') }}</td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $boms])
</div>
@endsection
