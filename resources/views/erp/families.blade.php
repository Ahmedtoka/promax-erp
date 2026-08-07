@extends('layouts.system')

{{--
    العائلات والصلاحية (2026-08-06):

    كارت ١: تعديل العائلات — الاسمين + **مدة الصلاحية بالشهور**.
    الحفظ بيعيد حساب انتهاء كل الباتشات من (تاريخ الإنتاج + مدة
    العائلة) فوراً — تقرير الصلاحية والبلوكات بيتظبطوا من هنا.

    كارت ٢: تسكين المنتجات — سيلكت عائلة لكل منتج بالصورة والبحث.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
@endphp

@section('title', __('stock.families_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.stock') }}">📦 {{ __('nav.inventory') }}</a>
    <a class="btn" href="{{ route('wh.expiry') }}">⏳ {{ __('stock.expiry_report') }}</a>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif
@if ($errors->any())
    <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)
            <div class="errline" style="margin:0">{{ $msg }}</div>
        @endforeach
    </div>
@endif

{{-- ═══ كارت ١: العائلات ومدد الصلاحية ═══ --}}
<div class="card">
    <h3>🧬 {{ __('stock.families_title') }}
        <span class="side">{{ __('stock.families_hint') }}</span></h3>

    <div class="alert warn" style="margin-bottom:12px">
        <span>⚠️</span><span>{{ __('stock.families_recompute_warn') }}</span>
    </div>

    <form method="POST" action="{{ route('erp.families.save') }}">
        @csrf
        <div class="tablewrap fam-tbl">
            <table>
                <tr>
                    <th>{{ __('stock.family') }} (AR)</th>
                    <th>{{ __('stock.family') }} (EN)</th>
                    <th style="width:140px">{{ __('stock.shelf_life_months') }}</th>
                    <th style="width:110px">{{ __('stock.equals_years') }}</th>
                    <th class="num" style="width:90px">{{ __('stock.skus') }}</th>
                </tr>
                @foreach ($families as $f)
                    <tr>
                        <td><input type="text" name="rows[{{ $f->id }}][name]" value="{{ $f->name }}" required maxlength="120" style="width:100%"></td>
                        <td><input type="text" name="rows[{{ $f->id }}][name_en]" value="{{ $f->name_en }}" maxlength="120" dir="ltr" style="width:100%"></td>
                        <td>
                            <input type="number" name="rows[{{ $f->id }}][months]" value="{{ $f->shelf_life_months }}"
                                   min="0" max="120" step="1" dir="ltr" style="width:100%;text-align:center;font-weight:800"
                                   data-fam-months oninput="famYears(this)">
                        </td>
                        {{-- «= سنة ونص» — عرض بس عشان الرقم يتفهم بالعين --}}
                        <td class="s" data-fam-years style="color:var(--muted);font-size:11px">—</td>
                        <td class="num">{{ $fmt($f->products_count) }}</td>
                    </tr>
                @endforeach
                {{-- صف الإضافة — عائلة جديدة بمفتاح ثابت من الاسم الإنجليزي --}}
                <tr style="background:var(--card2, #fafbff)">
                    <td><input type="text" name="new_name" maxlength="120" placeholder="+ {{ __('stock.new_family') }}" style="width:100%"></td>
                    <td><input type="text" name="new_name_en" maxlength="120" dir="ltr" placeholder="Family name" style="width:100%"></td>
                    <td><input type="number" name="new_months" min="0" max="120" step="1" dir="ltr" style="width:100%;text-align:center"></td>
                    <td class="s" style="color:var(--muted)">—</td>
                    <td class="num">—</td>
                </tr>
            </table>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn gold" type="submit"
                    onclick="return confirm(@js(__('stock.families_save_confirm')))">💾 {{ __('stock.save_and_recompute') }}</button>
        </div>
    </form>
</div>

{{-- ═══ كارت ٢: تسكين المنتجات على العائلات ═══ --}}
<div class="card">
    <h3>📦 {{ __('stock.assign_products') }}
        <span class="side">{{ __('stock.assign_products_hint') }}</span></h3>

    <form method="POST" action="{{ route('erp.families.assign') }}">
        @csrf
        <div class="searchbar" style="margin-bottom:10px">
            <input type="search" id="fpFilter" placeholder="🔍 {{ __('field.search_product_ph') }}"
                   oninput="fpApply()" style="flex:1;min-width:220px">
            <select id="fpFam" onchange="fpApply()" style="min-width:160px">
                <option value="">{{ __('stock.family') }}: {{ __('common.all') }}</option>
                @foreach ($families as $f)
                    <option value="{{ $f->key }}">{{ $f->displayName() }}</option>
                @endforeach
            </select>
            <span class="s" style="color:var(--muted)"><b id="fpCount">{{ $products->count() }}</b> {{ __('stock.rows_visible') }}</span>
            <button class="btn gold" type="submit">💾 {{ __('stock.save_assignment') }}</button>
        </div>

        <div class="tablewrap fam-tbl" style="max-height:62vh;overflow-y:auto">
            <table>
                <thead>
                    <tr>
                        <th style="text-align:start">{{ __('stock.item') }}</th>
                        <th style="width:220px">{{ __('stock.family') }}</th>
                        <th style="width:150px">{{ __('stock.effective_life') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($products as $p)
                        <tr class="fp-row"
                            data-q="{{ mb_strtolower($p->displayName().' '.$p->code.' '.($p->name_en ?? '')) }}"
                            data-fam="{{ $p->family }}">
                            <td style="text-align:start">
                                <div style="display:flex;gap:10px;align-items:center">
                                    @if ($p->imageSrc())
                                        <img src="{{ $p->imageSrc() }}"
                                             style="width:44px;height:44px;object-fit:contain;border-radius:8px;border:1px solid var(--border);background:#fff;flex-shrink:0">
                                    @else
                                        <div style="width:44px;height:44px;border-radius:8px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>
                                    @endif
                                    <div>
                                        <b style="font-size:12.5px">{{ $p->displayName() }}</b>
                                        <div style="font-size:10px;color:var(--muted)">{{ $p->code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <select name="fam[{{ $p->id }}]" style="width:100%">
                                    <option value="">— {{ __('stock.no_family') }} —</option>
                                    @foreach ($families as $f)
                                        <option value="{{ $f->key }}" @selected($p->family === $f->key)>{{ $f->displayName() }}</option>
                                    @endforeach
                                </select>
                            </td>
                            {{-- المدة الفعالة النهارده — العائلة بتغلب، وخانة المنتج
                                 القديمة بتشتغل بس لو العائلة من غير مدة --}}
                            <td class="s">
                                <span class="badge b-blue">{{ $p->shelfLife() }} {{ __('stock.month_unit') }}</span>
                                @if ($p->shelf_life_months && ! \App\Models\ProductFamily::monthsFor($p->family))
                                    <span class="badge b-orange" style="font-size:9px" title="{{ __('stock.product_override') }}">!</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </form>
</div>

@endsection

@section('scripts')
<style>
.fam-tbl th, .fam-tbl td { text-align: center; vertical-align: middle; }
</style>
<script>
const FAM_YEAR = @json(__('stock.year_unit'));
const FAM_MONTH = @json(__('stock.month_unit'));

/** «= سنة ونص» جنب خانة الشهور — عرض بس */
function famYears(inp) {
    const m = Number(inp.value || 0);
    const cell = inp.closest('tr').querySelector('[data-fam-years]');

    if (!m) { cell.textContent = '—'; return; }
    cell.textContent = m % 12 === 0
        ? '= ' + (m / 12) + ' ' + FAM_YEAR
        : '= ' + m + ' ' + FAM_MONTH;
}

document.querySelectorAll('[data-fam-months]').forEach(famYears);

/** فلتر التسكين — بحث + عائلة */
function fpApply() {
    const q = (document.getElementById('fpFilter').value || '').trim().toLowerCase();
    const fam = document.getElementById('fpFam').value;
    let visible = 0;

    document.querySelectorAll('tr.fp-row').forEach(function (tr) {
        const show = (!q || (tr.dataset.q || '').includes(q)) && (!fam || tr.dataset.fam === fam);
        tr.hidden = !show;
        if (show) visible++;
    });

    document.getElementById('fpCount').textContent = visible;
}
</script>
@endsection
