@extends('layouts.system')

@section('title', __('field.returns'))

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@section('content')

{{-- ⚠️ الـKPIs من **نفس الكويري المفلترة** بتاعة الجدول — نطاق واحد.
     خلط نطاقين (كل المرتجعات في الكروت والمفلترة في الجدول) هو الفخ
     المعروف اللي بيخلي الشاشة تقول رقمين لنفس الحاجة. --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('common.total') }}</div>
        <div class="val neg">{{ $fmt($sumValue) }}</div>
        <div class="sub2">{{ __('common.currency') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('field.return_good_units') }}</div>
        <div class="val pos">{{ number_format($sumGood) }}</div>
        <div class="sub2">{{ __('common.piece') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('field.return_damaged_units') }}</div>
        <div class="val neg">{{ number_format($sumDamaged) }}</div>
        <div class="sub2">{{ __('common.piece') }}</div>
    </div>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <select name="policy">
            <option value="">{{ __('field.return_policy') }}</option>
            @foreach ($policies as $p)
                <option value="{{ $p }}" @selected(($filters['policy'] ?? '') === $p)>
                    {{ __('field.return_policy_'.$p) }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}">
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}">
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.returns') }}">{{ __('common.clear') }}</a>
        {{-- ⚠️ **الزرار متحرس بنفس مفتاح الراوت.** مدير الفرع عنده
             `!ops.returns.new` — من غير الحارس كان بيشوف الزرار وياخد
             403 أول ما يدوس، وده بالظبط اللي `Access` اتعملت تمنعه. --}}
        @if (\App\Support\Access::allows(auth()->user(), 'ops.returns.new'))
            <a class="btn green" href="{{ route('ops.returns.new') }}">+ {{ __('field.return_doc') }}</a>
        @endif
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.number') }}</th>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('field.return_policy') }}</th>
                <th>{{ __('field.return_good_units') }}</th>
                <th>{{ __('field.return_damaged_units') }}</th>
                <th>{{ __('common.total') }}</th>
                <th>{{ __('common.date') }}</th>
            </tr>
            @forelse ($returns as $r)
                <tr class="clickable" onclick="location.href='{{ route('ops.returns.show', $r) }}'">
                    <td><b>{{ $r->number }}</b></td>
                    <td>{{ $r->client?->fullName() ?? '—' }}</td>
                    <td style="color:var(--muted)">{{ $r->rep?->displayName() ?? __('common.office') }}</td>
                    <td><span class="badge b-purple">{{ $r->policyLabel() }}</span></td>
                    <td class="num">{{ number_format($r->good_units) }}</td>
                    <td class="num {{ $r->damaged_units > 0 ? 'neg' : '' }}">{{ number_format($r->damaged_units) }}</td>
                    <td class="num neg"><b>{{ $fmt($r->grand_total) }}</b></td>
                    <td class="num">{{ $r->created_at->format('Y-m-d h:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('common.no_results') }}</td></tr>
            @endforelse
        </table>
    </div>
    <div class="pag">{{ $returns->links('pagination::simple-default') }}</div>
</div>

@endsection
