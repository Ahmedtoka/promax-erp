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
    {{-- ═══════════════════════════════════════════════════════
         المستوى الأول: جدول بأعمدة شاشة القنوات — «السايكل
         الجديدة» (طلب المالك ١٧/٨: «الموضوع ده يتفور»)
         ═══════════════════════════════════════════════════════

         ⚠️ نفس أرقام شاشة القنوات بس مجمّعة بالديفيجن: عملاء ·
         مشتريات · محصَّل · رصيد · مبيعات النهارده · كميات ·
         مدى الخصم — وكلها من مصادرها (عدادات العميل المجمّعة
         بـrecalculate + الفواتير مباشرة لليوم والكميات). --}}
    @php
        $pct = fn ($v) => $v === null ? null : rtrim(rtrim(number_format((float) $v * 100, 1, '.', ''), '0'), '.');
        $tot = ['n' => 0, 'purchases' => 0.0, 'collections' => 0.0, 'balance' => 0.0, 'today' => 0.0, 'qty' => 0];
    @endphp
    <div class="card">
        <h3>🗂️ {{ __('client.divisions') }}
            <span class="side">{{ __('client.divisions_hint') }}</span></h3>
        <div class="tablewrap">
            <table data-plain>
                <thead>
                <tr>
                    <th>{{ __('client.division') }}</th>
                    <th>{{ __('client.ff_type') }}</th>
                    <th class="num">{{ __('nav.clients') }}</th>
                    <th class="num">{{ __('client.purchases') }}</th>
                    <th class="num">{{ __('client.collected') }}</th>
                    <th class="num">{{ __('client.balance') }}</th>
                    <th class="num">{{ __('client.sales_today') }}</th>
                    <th class="num">{{ __('client.qty_sold') }}</th>
                    <th class="num">{{ __('client.discount_range') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach (Divisions::keys() as $key)
                    @php
                        $r = $agg->get($key);
                        $t = (float) ($today[$key] ?? 0);
                        $q = (int) ($qty[$key] ?? 0);
                        $tot['n'] += (int) ($r->n ?? 0);
                        $tot['purchases'] += (float) ($r->purchases ?? 0);
                        $tot['collections'] += (float) ($r->collections ?? 0);
                        $tot['balance'] += (float) ($r->balance ?? 0);
                        $tot['today'] += $t; $tot['qty'] += $q;
                    @endphp
                    <tr class="clickable"
                        onclick="location.href='{{ route('erp.divisions', ['division' => $key]) }}'">
                        <td><b>{{ Divisions::label($key) }}</b></td>
                        <td><span class="badge {{ Divisions::fulfillmentBadge($key) }}">
                            {{ Divisions::fulfillmentLabel($key) }}</span></td>
                        <td class="num"><b>{{ number_format($r->n ?? 0) }}</b></td>
                        <td class="num">{{ number_format((float) ($r->purchases ?? 0)) }}</td>
                        <td class="num pos">{{ number_format((float) ($r->collections ?? 0)) }}</td>
                        <td class="num {{ (float) ($r->balance ?? 0) > 0 ? 'neg' : '' }}">
                            {{ number_format((float) ($r->balance ?? 0)) }}</td>
                        <td class="num">{{ $t > 0 ? number_format($t) : '—' }}</td>
                        <td class="num">{{ $q > 0 ? number_format($q) : '—' }}</td>
                        <td class="num" style="font-size:11.5px">
                            @if ($r && $r->dmax > 0)
                                {{ $pct($r->dmin ?? 0) }}% — {{ $pct($r->dmax) }}%
                                <br><span style="color:var(--muted);font-size:10px">
                                    {{ __('client.avg_n', ['n' => $pct($r->davg ?? 0)]) }}</span>
                            @else — @endif
                        </td>
                        <td>@include('partials._view', ['url' => route('erp.divisions', ['division' => $key])])</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr style="font-weight:800;background:var(--card2)">
                    <td>Σ {{ __('common.total') }}</td>
                    <td></td>
                    <td class="num">{{ number_format($tot['n']) }}</td>
                    <td class="num">{{ number_format($tot['purchases']) }}</td>
                    <td class="num">{{ number_format($tot['collections']) }}</td>
                    <td class="num">{{ number_format($tot['balance']) }}</td>
                    <td class="num">{{ number_format($tot['today']) }}</td>
                    <td class="num">{{ number_format($tot['qty']) }}</td>
                    <td></td><td></td>
                </tr>
                </tfoot>
            </table>
        </div>

        @if ($unassigned > 0)
            {{-- ⚠️ نفس لافتة «فيه عملاء من غير قناة» بتاعت الشاشة
                 القديمة — بس للديفيجن، وبتوديك على طول --}}
            <a href="{{ route('erp.divisions', ['division' => 'none']) }}"
               class="alert warn" style="display:flex;margin-top:12px;text-decoration:none;color:inherit">
                <span>⚠️</span>
                <span style="flex:1">{{ __('client.unassigned_note', ['count' => $unassigned]) }}</span>
                <span>←</span>
            </a>
        @endif
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
