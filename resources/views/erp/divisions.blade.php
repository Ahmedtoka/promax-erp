@extends('layouts.system')

{{--
    الديفيجنز — العملاء بالقسم التجاري وطريقة التعامل  ·  ١٧/٨/٢٠٢٦

    ⚠️ **طريقة التعامل معروضة على كل كارت وكل صف** — دي مش زينة،
    دي إجابة «البضاعة بتوصل للعميل ده إزاي»: كاش فان (عهدة + خط
    سير) / ديلفري (PO) / أونلاين (كوريير).
--}}

@section('title', __('client.divisions'))

@section('actions')
    @if ($division)
        <a class="btn" href="{{ route('erp.divisions') }}">← {{ __('client.all_divisions') }}</a>
    @endif
@endsection

@section('content')

@php use App\Support\Divisions; @endphp

@if (! $division)
    {{-- ═══════════ المستوى الأول: الكروت ═══════════ --}}
    <div class="kpis">
        @foreach (Divisions::keys() as $key)
            @php $row = $agg->get($key); @endphp
            <a class="kpi" href="{{ route('erp.divisions', ['division' => $key]) }}"
               style="text-decoration:none;color:inherit;cursor:pointer">
                <div class="lbl">{{ Divisions::label($key) }}</div>
                <div class="val">{{ number_format($row->n ?? 0) }}</div>
                <div class="sub2">
                    <span class="badge {{ Divisions::fulfillmentBadge($key) }}">
                        {{ Divisions::fulfillmentLabel($key) }}</span>
                    @if (($row->purchases ?? 0) > 0)
                        · {{ number_format((float) $row->purchases) }} {{ __('common.currency') }}
                    @endif
                </div>
            </a>
        @endforeach

        {{-- ⚠️ «بدون قسم» كارت زي الباقي — المشكلة الظاهرة بتتحل،
             والمخفية بتتنسي. أحمر لما فيه حد لسه ماتسكّنش. --}}
        <a class="kpi" href="{{ route('erp.divisions', ['division' => 'none']) }}"
           style="text-decoration:none;color:inherit;cursor:pointer">
            <div class="lbl">⚠️ {{ __('client.no_division') }}</div>
            <div class="val {{ $unassigned > 0 ? 'neg' : 'pos' }}">{{ number_format($unassigned) }}</div>
            <div class="sub2">{{ __('client.no_division_hint') }}</div>
        </a>
    </div>
@else
    {{-- ═══════════ المستوى الثاني: عملاء القسم ═══════════ --}}
    @php $isNone = $division === 'none'; @endphp
    <div class="card">
        <h3>
            {{ $isNone ? __('client.no_division') : Divisions::label($division) }}
            @unless ($isNone)
                <span class="badge {{ Divisions::fulfillmentBadge($division) }}">
                    {{ Divisions::fulfillmentLabel($division) }}</span>
            @endunless
            <span class="side">{{ __('client.client_countable', ['count' => $clients->count()]) }}</span>
        </h3>

        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('client.client') }}</th>
                    <th>{{ __('client.chain') }}</th>
                    <th>{{ __('client.zone') }}</th>
                    <th class="num">{{ __('client.purchases') }}</th>
                    <th class="num">{{ __('client.balance') }}</th>
                    {{-- تغيير القسم من الصف مباشرة — التسكين اليدوي
                         واحد ورا واحد من غير فتح فورم العميل --}}
                    <th style="min-width:210px" data-nosum>{{ __('client.division') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($clients as $c)
                    <tr>
                        <td>
                            <a href="{{ route('erp.clients.show', $c) }}"><b>{{ $c->fullName() }}</b></a>
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $c->displayAddress() ?: '—' }}</span>
                        </td>
                        <td style="color:var(--muted)">{{ $c->group?->displayName() ?? '—' }}</td>
                        <td style="color:var(--muted)">{{ $c->zone?->displayName() ?? '—' }}</td>
                        <td class="num">{{ number_format((float) $c->purchases) }}</td>
                        <td class="num {{ (float) $c->balance > 0 ? 'neg' : '' }}">{{ number_format((float) $c->balance) }}</td>
                        <td>
                            <form method="POST" action="{{ route('erp.divisions.assign', $c) }}"
                                  style="display:flex;gap:6px;align-items:center">
                                @csrf
                                <select name="division" style="flex:1;min-width:140px">
                                    <option value="">— {{ __('client.no_division') }} —</option>
                                    @foreach (Divisions::options() as $k => $lbl)
                                        <option value="{{ $k }}" @selected($c->division === $k)>{{ $lbl }}</option>
                                    @endforeach
                                </select>
                                <button class="btn sm" type="submit">💾</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:30px">
                        {{ $isNone ? __('client.all_assigned') : __('client.division_empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
