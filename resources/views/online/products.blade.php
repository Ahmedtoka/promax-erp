@extends('layouts.system')

@section('title', __('online.products_title'))

@php
    $canAct = in_array(auth()->user()->role, ['admin', 'manager'], true);

    // ⚠️ قايمة الأوبشنز بتتبني مرة واحدة وتتحقن بـ<template> —
    // سيلكت كامل المنتجات × ١٠٠ صف مكتوب في الـHTML كان هيتخن
    // الصفحة جامد (فخ «قايمة أوبشنز جوه جافاسكريبت» الموثق).
    $optsHtml = '<option value="">—</option>';
    foreach ($products as $p) {
        $optsHtml .= '<option value="'.$p->id.'">'
            .e($p->code.' · '.$p->displayName()).'</option>';
    }
@endphp

@section('content')

@if ($errors->any())
    <div class="alert" style="margin-bottom:12px">{{ $errors->first() }}</div>
@endif
@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px">
        <h3 style="margin:0">🔗 {{ __('online.products_title') }}
            @if ($unlinkedCount > 0)
                <span class="badge b-red">{{ __('online.unlinked_n', ['n' => $unlinkedCount]) }}</span>
            @endif
        </h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @if ($canAct)
                <form method="POST" action="{{ route('online.products.fetch') }}">
                    @csrf
                    <button class="btn" type="submit">⬇ {{ __('online.fetch_products') }}</button>
                </form>
            @endif
        </div>
    </div>
    <div class="dash-hint" style="margin-bottom:10px">{{ __('online.products_hint') }}</div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <form method="GET" class="searchbar" style="margin:0">
            @if ($filters['unlinked'] ?? false)
                <input type="hidden" name="unlinked" value="1">
            @endif
            <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="🔎 {{ __('common.search') }}">
        </form>
        <a class="btn {{ ($filters['unlinked'] ?? false) ? 'gold' : '' }}"
           href="{{ route('online.products', array_filter(['search' => $filters['search'] ?? null, 'unlinked' => ($filters['unlinked'] ?? false) ? null : 1])) }}">
            ⚠️ {{ __('online.only_unlinked') }}</a>
    </div>

    <form method="POST" action="{{ route('online.products.save') }}">
        @csrf
        <div class="tablewrap">
            <table>
                <tr>
                    <th style="width:52px"></th>
                    <th>{{ __('online.shopify_product') }}</th>
                    <th>SKU</th>
                    <th>{{ __('online.system_product') }}</th>
                    <th>{{ __('online.sku_pushed') }}</th>
                </tr>
                @forelse ($links as $link)
                    <tr>
                        <td>
                            @if ($link->image)
                                <img src="{{ $link->image }}" alt=""
                                     style="width:42px;height:42px;object-fit:cover;border-radius:8px">
                            @endif
                        </td>
                        <td>
                            <b>{{ $link->title }}</b>
                            @if ($link->variant_title)
                                <br><span style="font-size:11px;color:var(--muted)">{{ $link->variant_title }}</span>
                            @endif
                        </td>
                        <td class="num s" dir="ltr">{{ $link->sku ?: '—' }}</td>
                        <td>
                            @if ($canAct)
                                <select name="links[{{ $link->id }}]" class="link-select"
                                        data-current="{{ $link->product_id ?? '' }}"
                                        style="min-width:240px"></select>
                            @else
                                {{ $link->product?->displayName() ?: '—' }}
                            @endif
                        </td>
                        <td>
                            @if ($link->sku_pushed_at)
                                <span class="badge b-green" title="{{ $link->sku_pushed_at->format('Y-m-d h:i A') }}">✓</span>
                            @elseif ($link->product_id)
                                <span class="badge b-orange">{{ __('online.sku_pending') }}</span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:28px">
                        {{ __('online.products_empty') }}
                    </td></tr>
                @endforelse
            </table>
        </div>

        @if ($canAct && $links->count())
            <div style="margin-top:12px;display:flex;gap:10px;align-items:center">
                <button class="btn gold" type="submit">💾 {{ __('online.save_links') }}</button>
                <span class="dash-hint">{{ __('online.save_links_hint') }}</span>
            </div>
        @endif
    </form>

    @include('partials._pagination', ['p' => $links])
</div>

{{-- قايمة المنتجات مرة واحدة — بتتنسخ لكل سيلكت بالجافاسكريبت --}}
<template id="prodOpts">{!! $optsHtml !!}</template>

@endsection

@section('scripts')
<script>
    (function () {
        'use strict';

        var tpl = document.getElementById('prodOpts');
        if (!tpl) return;

        var html = tpl.innerHTML;

        document.querySelectorAll('.link-select').forEach(function (sel) {
            sel.innerHTML = html;
            sel.value = sel.dataset.current || '';
        });
    })();
</script>
@endsection
