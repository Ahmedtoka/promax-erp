@extends('layouts.system')

@section('title', __('online.products_title'))

@php
    $canAct = in_array(auth()->user()->role, ['admin', 'manager'], true);

    // ⚠️ قايمة الأوبشنز بتتبني مرة واحدة وتتحقن بـ<template> —
    // سيلكت كامل المنتجات × ١٠٠ صف مكتوب في الـHTML كان هيتخن
    // الصفحة جامد (فخ «قايمة أوبشنز جوه جافاسكريبت» الموثق).
    // «اختار الصنف» بدل شرطة — 70 صف مش مربوط كانوا شكلهم خانات فاضية مش مطلوب منها حاجة (٢٢/٩)
    $optsHtml = '<option value="">'.e(__('ui.choose', ['x' => __('ui.l_product')])).'</option>';
    foreach ($products as $p) {
        $optsHtml .= '<option value="'.$p->id.'">'
            .e($p->code.' · '.$p->displayName()).'</option>';
    }
    $byId = $products->keyBy('id');
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
            <label class="fl"><span>{{ __('ui.l_search') }}</span>
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="🔎 {{ __('common.search') }}"></label>
        </form>
        <a class="btn {{ ($filters['unlinked'] ?? false) ? 'gold' : '' }}"
           href="{{ route('online.products', array_filter(['search' => $filters['search'] ?? null, 'unlinked' => ($filters['unlinked'] ?? false) ? null : 1])) }}">
            ⚠️ {{ __('online.only_unlinked') }}</a>
    </div>

    <form method="POST" action="{{ route('online.products.save') }}">
        @csrf
        <div class="tablewrap">
            {{-- جدول ربط (سيلكت وخانة لكل صف) — إكسيل الجدول بيقرا النص بس فكان هيطلّع الربط فاضي (٢٢/٩) --}}
            <table data-noxl>
                <tr>
                    <th style="width:52px"></th>
                    <th>{{ __('online.shopify_product') }}</th>
                    <th>SKU</th>
                    <th>{{ __('online.system_product') }}</th>
                    {{-- قطع الباك: فاريانت «pcs 12» = 12 قطعة من المنتج --}}
                    <th class="num" data-nosum title="{{ __('online.units_hint') }}">{{ __('online.units') }}</th>
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
                        @php
                            // ⚠️ ممنوع @json — بيكسّر بارسر البليد (الفخ الموثق)
                            $partsJson = json_encode($link->components(),
                                JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                        @endphp
                        <td>
                            @if ($link->isBundle())
                                {{-- باندل: مفيش سيلكت في الفورم الجماعي — الحفظ الجماعي مايلمسهوش --}}
                                <span class="badge b-purple">🧩 {{ __('online.bundle') }}</span>
                                @foreach ($link->components() as $c)
                                    <div style="font-size:11.5px;margin-top:3px">
                                        {{ $c['units'] }} × {{ $byId[$c['product_id']]?->displayName() ?? '#'.$c['product_id'] }}
                                    </div>
                                @endforeach
                                @if ($canAct)
                                    <button class="btn sm" type="button" style="margin-top:4px"
                                            onclick='openBundle({{ $link->id }}, {!! $partsJson !!})'>
                                        🧩 {{ __('online.bundle_edit') }}</button>
                                @endif
                            @elseif ($canAct)
                                <div style="display:flex;gap:6px;align-items:center">
                                    <select name="links[{{ $link->id }}]" class="link-select"
                                            data-current="{{ $link->product_id ?? '' }}"
                                            style="min-width:240px"></select>
                                    <button class="btn sm" type="button" title="{{ __('online.bundle_hint') }}"
                                            onclick='openBundle({{ $link->id }}, {!! $partsJson !!})'>
                                        🧩 {{ __('online.bundle_btn') }}</button>
                                </div>
                            @else
                                {{ $link->product?->displayName() ?: '—' }}
                            @endif
                        </td>
                        <td class="num">
                            @if ($link->isBundle())
                                {{ array_sum(array_column($link->components(), 'units')) }}
                            @elseif ($canAct)
                                <input type="number" name="units[{{ $link->id }}]" min="1" max="1000"
                                       value="{{ (int) ($link->units ?? 1) }}"
                                       style="width:68px;text-align:center">
                            @else
                                {{ (int) ($link->units ?? 1) }}
                            @endif
                        </td>
                        <td>
                            @if ($link->sku_pushed_at)
                                <span class="badge b-green" title="{{ $link->sku_pushed_at->format('Y-m-d h:i A') }}">✓</span>
                            @elseif ($link->isBundle())
                                —
                            @elseif ($link->product_id)
                                <span class="badge b-orange">{{ __('online.sku_pending') }}</span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px">
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

{{-- ═══ ديالوج الباندل (٢٦/٩) — فورم لوحده بره فورم الحفظ الجماعي ═══ --}}
<dialog id="dlgBundle" style="max-width:620px;width:94vw">
    <form class="dlg" method="POST" id="formBundle"
          onsubmit="this.querySelector('[type=submit]').disabled = true">
        @csrf
        <h4>🧩 {{ __('online.bundle_title') }}</h4>
        <div class="dash-hint" style="margin-bottom:10px">{{ __('online.bundle_hint') }}</div>
        <div id="bdRows" style="display:flex;flex-direction:column;gap:8px"></div>
        <button class="btn sm" type="button" style="margin-top:8px" onclick="addBundleRow()">➕ {{ __('online.bundle_add') }}</button>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgBundle')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">💾 {{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

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

    /* ═══ الباندل: صف لكل صنف (سيلكت + قطع + شيل) ═══ */
    const BUNDLE_URL = @js(url('erp/online/products'));
    const T_UNITS = @js(__('online.bundle_units'));
    var bdIdx = 0;

    function addBundleRow(pid, units) {
        var i = bdIdx++;
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:8px;align-items:center';
        row.innerHTML = '<select name="parts[' + i + '][product_id]" style="flex:1;min-width:0">'
            + document.getElementById('prodOpts').innerHTML + '</select>'
            + '<input type="number" name="parts[' + i + '][units]" min="1" max="1000" value="' + (units || 1) + '"'
            + ' title="' + T_UNITS + '" style="width:72px;text-align:center">'
            + '<button class="btn sm red" type="button">✖</button>';
        row.querySelector('select').value = pid ? String(pid) : '';
        row.querySelector('button').onclick = function () { row.remove(); };
        document.getElementById('bdRows').appendChild(row);
    }

    function openBundle(linkId, parts) {
        document.getElementById('formBundle').action = BUNDLE_URL + '/' + linkId + '/bundle';
        document.getElementById('bdRows').innerHTML = '';
        (parts && parts.length ? parts : [{}]).forEach(function (p) { addBundleRow(p.product_id, p.units); });
        if (!parts || parts.length < 2) addBundleRow();
        openDlg('dlgBundle');
    }
</script>
@endsection
