@extends('layouts.admin')

@section('title', 'الفواتير والـ POs')

@section('content')

<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">

    {{-- ===== فواتير الكاش فان ===== --}}
    <div class="bg-white rounded-2xl p-5 shadow-sm">
        <div class="font-bold mb-4">فواتير الكاش فان — النهارده</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-slate-500 text-xs border-b bg-slate-50">
                        <th class="text-start py-2.5 px-2">الفاتورة</th>
                        <th class="text-start py-2.5 px-2">العميل</th>
                        <th class="text-start py-2.5 px-2">المندوب</th>
                        <th class="text-start py-2.5 px-2">الدفع</th>
                        <th class="text-end py-2.5 px-2">القيمة</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoices as $inv)
                        <tr class="border-b last:border-0 hover:bg-slate-50">
                            <td class="py-2.5 px-2 font-semibold">{{ $inv['id'] }}</td>
                            <td class="py-2.5 px-2">{{ $inv['client'] }}</td>
                            <td class="py-2.5 px-2 text-slate-500 text-xs">{{ $inv['rep'] }}</td>
                            <td class="py-2.5 px-2">
                                <span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full {{ $inv['pay'] === 'كاش' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $inv['pay'] }}</span>
                            </td>
                            <td class="py-2.5 px-2 text-end font-extrabold">{{ $inv['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ===== POs الكورير ===== --}}
    <div class="bg-white rounded-2xl p-5 shadow-sm">
        <div class="font-bold mb-4">POs التوزيع — جورميه / رابيت</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-slate-500 text-xs border-b bg-slate-50">
                        <th class="text-start py-2.5 px-2">الـ PO</th>
                        <th class="text-start py-2.5 px-2">العميل</th>
                        <th class="text-start py-2.5 px-2">المصدر</th>
                        <th class="text-end py-2.5 px-2">الكمية</th>
                        <th class="text-end py-2.5 px-2">القيمة (hold)</th>
                        <th class="text-start py-2.5 px-2">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pos as $po)
                        @php
                            [$cls, $txt] = match ($po['status']) {
                                'delivered' => ['bg-emerald-100 text-emerald-700', 'اتسلم ' . $po['time']],
                                'arrived' => ['bg-amber-100 text-amber-700', 'جاري التسليم'],
                                default => ['bg-slate-100 text-slate-600', 'مستني'],
                            };
                        @endphp
                        <tr class="border-b last:border-0 hover:bg-slate-50">
                            <td class="py-2.5 px-2 font-semibold">{{ $po['id'] }}</td>
                            <td class="py-2.5 px-2">{{ $po['client'] }}</td>
                            <td class="py-2.5 px-2">
                                <span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full {{ $po['source'] === 'جورميه' ? 'bg-orange-100 text-orange-700' : 'bg-violet-100 text-violet-700' }}">{{ $po['source'] }}</span>
                            </td>
                            <td class="py-2.5 px-2 text-end">{{ $po['qty'] }}</td>
                            <td class="py-2.5 px-2 text-end font-extrabold">{{ $po['total'] }}</td>
                            <td class="py-2.5 px-2">
                                <span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full {{ $cls }}">{{ $txt }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@endsection
