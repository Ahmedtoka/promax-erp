@extends('layouts.admin')

@section('title', 'نظرة عامة')

@section('content')

{{-- ===== KPIs ===== --}}
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
    @foreach ($kpis as $k)
        <div class="bg-white rounded-2xl p-4 shadow-sm">
            <div class="text-2xl mb-2">{{ $k['icon'] }}</div>
            <div class="font-extrabold text-lg leading-tight">{{ $k['value'] }}</div>
            <div class="text-xs text-slate-500 mt-1">{{ $k['label'] }}</div>
        </div>
    @endforeach
</div>

{{-- ===== Charts ===== --}}
<div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-2xl p-5 shadow-sm xl:col-span-2">
        <div class="font-bold mb-3">مبيعات الأسبوع (كاش فان vs كورير)</div>
        <canvas id="salesChart" height="110"></canvas>
    </div>
    <div class="bg-white rounded-2xl p-5 shadow-sm">
        <div class="font-bold mb-3">المبيعات حسب مجموعة المنتج</div>
        <canvas id="groupChart" height="220"></canvas>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">

    {{-- ===== طلبات مستنية ===== --}}
    <div class="bg-white rounded-2xl p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <div class="font-bold">طلبات العملاء الجدد</div>
            <a href="{{ route('dashboard.approvals') }}" class="text-xs text-emerald-600 font-bold hover:underline">كل الموافقات ←</a>
        </div>
        <div class="space-y-2">
            @foreach ($requests as $r)
                @php
                    [$cls, $txt] = match ($r['status']) {
                        'pending' => ['bg-slate-100 text-slate-600', 'مستني الموافقة'],
                        'review' => ['bg-amber-100 text-amber-700', 'تحت المراجعة'],
                        'approved' => ['bg-emerald-100 text-emerald-700', 'متوافق عليه'],
                        default => ['bg-rose-100 text-rose-700', 'مرفوض'],
                    };
                @endphp
                <div class="flex items-center justify-between border rounded-xl px-4 py-2.5">
                    <div>
                        <div class="font-semibold text-sm">{{ $r['name'] }}</div>
                        <div class="text-[11px] text-slate-500">{{ $r['id'] }} • {{ $r['rep'] }} • {{ $r['time'] }}</div>
                    </div>
                    <span class="text-[11px] font-bold px-3 py-1 rounded-full {{ $cls }}">{{ $txt }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ===== لايف تراكينج ===== --}}
    <div class="bg-white rounded-2xl p-5 shadow-sm">
        <div class="font-bold mb-3">آخر التحركات (لايف تراكينج)</div>
        <div class="space-y-2 max-h-80 overflow-y-auto pe-1">
            @foreach ($tracking as $t)
                @php
                    $dot = match ($t['type']) {
                        'sale' => 'bg-emerald-500',
                        'deliver' => 'bg-teal-500',
                        'in' => 'bg-blue-500',
                        'out' => 'bg-rose-500',
                        'req' => 'bg-amber-500',
                        default => 'bg-violet-500',
                    };
                @endphp
                <div class="flex items-center gap-3 text-sm">
                    <span class="w-2.5 h-2.5 rounded-full {{ $dot }} shrink-0"></span>
                    <span class="text-slate-400 text-xs w-16 shrink-0">{{ $t['time'] }}</span>
                    <span class="font-semibold text-xs text-slate-500 w-24 shrink-0">{{ $t['rep'] }}</span>
                    <span class="text-[13px]">{{ $t['event'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ===== آخر الفواتير ===== --}}
<div class="bg-white rounded-2xl p-5 shadow-sm mt-4">
    <div class="flex items-center justify-between mb-3">
        <div class="font-bold">آخر الفواتير</div>
        <a href="{{ route('dashboard.orders') }}" class="text-xs text-emerald-600 font-bold hover:underline">كل الفواتير ←</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-slate-500 text-xs border-b">
                    <th class="text-start py-2">الفاتورة</th>
                    <th class="text-start py-2">العميل</th>
                    <th class="text-start py-2">المندوب</th>
                    <th class="text-start py-2">الدفع</th>
                    <th class="text-start py-2">الوقت</th>
                    <th class="text-end py-2">القيمة</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $inv)
                    <tr class="border-b last:border-0">
                        <td class="py-2.5 font-semibold">{{ $inv['id'] }}</td>
                        <td class="py-2.5">{{ $inv['client'] }}</td>
                        <td class="py-2.5 text-slate-500">{{ $inv['rep'] }}</td>
                        <td class="py-2.5">
                            <span class="text-[11px] font-bold px-2.5 py-0.5 rounded-full {{ $inv['pay'] === 'كاش' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $inv['pay'] }}</span>
                        </td>
                        <td class="py-2.5 text-slate-500">{{ $inv['time'] }}</td>
                        <td class="py-2.5 text-end font-extrabold">{{ $inv['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection

@section('scripts')
<script>
    new Chart(document.getElementById('salesChart'), {
        type: 'bar',
        data: {
            labels: @json($salesWeek['labels']),
            datasets: [
                { label: 'كاش فان', data: @json($salesWeek['cashVan']), backgroundColor: '#059669', borderRadius: 6 },
                { label: 'كورير (POs)', data: @json($salesWeek['courier']), backgroundColor: '#2563eb', borderRadius: 6 },
            ],
        },
        options: {
            plugins: { legend: { position: 'bottom', rtl: true, labels: { font: { family: 'Cairo' } } } },
            scales: { y: { ticks: { font: { family: 'Cairo' } } }, x: { ticks: { font: { family: 'Cairo' } } } },
        },
    });

    new Chart(document.getElementById('groupChart'), {
        type: 'doughnut',
        data: {
            labels: @json($productGroups['labels']),
            datasets: [{
                data: @json($productGroups['values']),
                backgroundColor: ['#7c3aed', '#059669', '#f59e0b', '#2563eb'],
            }],
        },
        options: {
            plugins: { legend: { position: 'bottom', rtl: true, labels: { font: { family: 'Cairo' } } } },
        },
    });
</script>
@endsection
