@extends('layouts.system')

@section('title', __('supplier.suppliers'))

@php
    $money = fn ($n) => number_format((float) $n, 2);
    $manager = auth()->user()->canDecideOps();
@endphp

@section('actions')
    {{-- ⚠️ المحاسب بيشوف الموردين بس مش أوامر الشراء — اللينك من
         غير الشرط كان بيوديه على 403 --}}
    @if (\App\Support\Access::allows(auth()->user(), 'erp.purchasing'))
        <a class="btn" href="{{ route('erp.purchasing') }}">📥 {{ __('supplier.purchase_orders') }}</a>
    @endif
    @if ($manager)
        @if (\App\Support\Access::action(auth()->user(), 'act.suppliers.manage'))<button class="btn gold" onclick="openDlg('dlgNewSup')">+ {{ __('supplier.new_supplier') }}</button>@endif
    @endif
@endsection

@section('content')

{{-- الكروت بتفلتر: الكل / اللي علينا لهم فلوس (٢٢/٩) --}}
<div class="kpis">
    <a class="kpi {{ empty($filters['owed']) && empty($filters['q']) ? 'on' : '' }}" href="{{ route('erp.suppliers') }}">
        <div class="lbl">{{ __('supplier.suppliers') }}</div>
        <div class="val">{{ $supplierCount }}</div>
    </a>
    <a class="kpi {{ ! empty($filters['owed']) ? 'on' : '' }}" href="{{ route('erp.suppliers', ['owed' => 1]) }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">{{ __('supplier.total_owed') }}</div>
        <div class="val neg">{{ $money($totalOwed) }}</div>
        <div class="sub2">{{ __('supplier.total_owed_hint') }}</div>
    </a>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        @if (! empty($filters['owed']))<input type="hidden" name="owed" value="1">@endif
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="🔍 {{ __('supplier.search_ph') }}"></label>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.suppliers') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.code') }}</th>
                <th>{{ __('supplier.supplier') }}</th>
                <th>{{ __('common.phone') }}</th>
                <th>{{ __('supplier.contact_person') }}</th>
                <th data-nosum>{{ __('supplier.payment_days') }}</th>
                <th>{{ __('supplier.open_orders') }}</th>
                <th class="num">{{ __('supplier.balance') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>
            @forelse ($suppliers as $s)
                <tr class="clickable" onclick="location.href='{{ route('erp.suppliers.show', $s) }}'">
                    <td class="num">{{ $s->code }}</td>
                    <td><a href="{{ route('erp.suppliers.show', $s) }}"><b>{{ $s->displayName() }}</b></a></td>
                    <td class="num" dir="ltr">{{ $s->phone ?: '—' }}</td>
                    <td>{{ $s->contact_person ?: '—' }}</td>
                    <td class="num">{{ $s->payment_days ? __('client.days_countable', ['count' => $s->payment_days]) : '—' }}</td>
                    <td class="num">
                        @if ($s->open_orders > 0)
                            @if (\App\Support\Access::allows(auth()->user(), 'erp.purchasing'))
                                <a class="badge b-blue" onclick="event.stopPropagation()" href="{{ route('erp.purchasing', ['supplier' => $s->id, 'status' => 'open']) }}">{{ $s->open_orders }}</a>
                            @else
                                <span class="badge b-blue">{{ $s->open_orders }}</span>
                            @endif
                        @else — @endif
                    </td>
                    {{-- ⚠️ موجب = علينا له — أحمر لأنه التزام مش أصل --}}
                    <td class="num {{ (float) $s->balance > 0 ? 'neg' : 'pos' }}"><b>{{ $money($s->balance) }}</b></td>
                    <td>
                        @if ($s->active)
                            <span class="badge b-green">{{ __('common.active') }}</span>
                        @else
                            <span class="badge b-gray">{{ __('team.inactive') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('supplier.no_suppliers') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($manager)
<dialog id="dlgNewSup">
    <form class="dlg" method="POST" action="{{ route('erp.suppliers.store') }}">
        @csrf
        <h4>+ {{ __('supplier.new_supplier') }}</h4>
        @include('erp._supplier_fields', ['s' => null])
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewSup')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
