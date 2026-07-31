@extends('layouts.admin')

@section('title', 'المنتجات والمخزون')

@section('content')

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-emerald-600 text-white rounded-2xl p-5">
        <div class="text-sm opacity-80">قيمة المخزون (cash van)</div>
        <div class="text-2xl font-extrabold mt-1">{{ number_format($totalValue) }} ج.م</div>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm">
        <div class="text-sm text-slate-500">إجمالي الوحدات</div>
        <div class="text-2xl font-extrabold mt-1">{{ number_format($totalStock) }}</div>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm">
        <div class="text-sm text-slate-500">عدد الأصناف</div>
        <div class="text-2xl font-extrabold mt-1">{{ count($products) }}</div>
    </div>
</div>

<div class="bg-white rounded-2xl p-5 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <div class="font-bold">كتالوج المنتجات — شيت "منتج تام 30/6/2026"</div>
        <input id="search" type="text" placeholder="دوّر على صنف..."
               class="border rounded-xl px-4 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-emerald-500"
               oninput="filterRows(this.value)">
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" id="prodTable">
            <thead>
                <tr class="text-slate-500 text-xs border-b bg-slate-50">
                    <th class="text-start py-2.5 px-2">الكود</th>
                    <th class="text-start py-2.5 px-2">الصنف</th>
                    <th class="text-start py-2.5 px-2">الوحدة</th>
                    <th class="text-end py-2.5 px-2">سعر 50% hold</th>
                    <th class="text-end py-2.5 px-2">سعر 70% cash van</th>
                    <th class="text-end py-2.5 px-2">سعر cash van</th>
                    <th class="text-end py-2.5 px-2">المخزون</th>
                    <th class="text-end py-2.5 px-2">القيمة</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($products as $p)
                    <tr class="border-b last:border-0 hover:bg-slate-50">
                        <td class="py-2.5 px-2 text-slate-500">{{ $p['code'] }}</td>
                        <td class="py-2.5 px-2 font-semibold">{{ $p['name'] }}</td>
                        <td class="py-2.5 px-2 text-slate-500 text-xs">{{ $p['unit'] }}</td>
                        <td class="py-2.5 px-2 text-end">{{ number_format($p['hold'], 1) }}</td>
                        <td class="py-2.5 px-2 text-end">{{ number_format($p['p70'], 1) }}</td>
                        <td class="py-2.5 px-2 text-end font-bold">{{ number_format($p['cash']) }}</td>
                        <td class="py-2.5 px-2 text-end">{{ number_format($p['stock']) }}</td>
                        <td class="py-2.5 px-2 text-end font-extrabold text-emerald-700">{{ number_format($p['value']) }} ج.م</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection

@section('scripts')
<script>
    function filterRows(q) {
        q = q.trim();
        document.querySelectorAll('#prodTable tbody tr').forEach(tr => {
            tr.style.display = tr.textContent.includes(q) ? '' : 'none';
        });
    }
</script>
@endsection
