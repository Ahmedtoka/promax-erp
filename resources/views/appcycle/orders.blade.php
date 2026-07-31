@extends('layouts.system')

@section('title', 'الفواتير والـ POs')

@section('content')

<div class="grid2">
    <div class="card">
        <h3>🧾 فواتير الكاش فان — النهارده</h3>
        <div class="tablewrap">
            <table>
                <tr><th>فاتورة</th><th>العميل</th><th>المندوب</th><th>الدفع</th><th>الوقت</th><th>القيمة</th></tr>
                @foreach ($invoices as $inv)
                    <tr>
                        <td class="num">{{ $inv['id'] }}</td>
                        <td><b>{{ $inv['client'] }}</b></td>
                        <td style="color:var(--muted)">{{ $inv['rep'] }}</td>
                        <td><span class="badge {{ $inv['pay'] === 'كاش' ? 'b-green' : 'b-orange' }}">{{ $inv['pay'] }}</span></td>
                        <td class="num">{{ $inv['time'] }}</td>
                        <td class="num pos">{{ $inv['total'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    <div class="card">
        <h3>🚚 POs التوزيع — Gourrmet Egypt / Rabbit</h3>
        <div class="tablewrap">
            <table>
                <tr><th>PO</th><th>العميل</th><th>المصدر</th><th>وحدات</th><th>القيمة (hold)</th><th>الحالة</th></tr>
                @foreach ($pos as $po)
                    @php
                        [$cls, $txt] = match ($po['status']) {
                            'delivered' => ['b-green', 'اتسلم ' . $po['time']],
                            'arrived' => ['b-orange', 'جاري التسليم'],
                            default => ['b-gray', 'مستني'],
                        };
                    @endphp
                    <tr>
                        <td class="num">{{ $po['id'] }}</td>
                        <td><b>{{ $po['client'] }}</b></td>
                        <td><span class="badge {{ $po['source'] === 'جورميه' ? 'b-orange' : 'b-purple' }}">{{ $po['source'] }}</span></td>
                        <td class="num">{{ $po['qty'] }}</td>
                        <td class="num">{{ $po['total'] }}</td>
                        <td><span class="badge {{ $cls }}">{{ $txt }}</span></td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
</div>

@endsection
