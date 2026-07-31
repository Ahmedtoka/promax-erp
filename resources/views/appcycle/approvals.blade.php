@extends('layouts.system')

@section('title', 'موافقات العملاء الجدد')

@section('content')

<div class="alert warn" style="margin-bottom:14px">
    <div>✅ <b>فلو المانجر</b> — المندوب بيسجل المحل/الجيم من الأبلكيشن، والقرار هنا بيوصله نوتفيكيشن ويضيف العميل لزونه.</div>
</div>

<div class="card">
    <h3>📝 الطلبات</h3>
    <div class="tablewrap">
        <table>
            <thead>
                <tr><th>الطلب</th><th>المكان</th><th>قدّمه</th><th>العنوان</th><th>تليفون</th><th>صورة</th><th>أوراق</th><th>الوقت</th><th>الحالة</th><th>قرار (ديمو)</th></tr>
            </thead>
            <tbody>
                @foreach ($requests as $r)
                    @php
                        [$cls, $txt] = match ($r['status']) {
                            'pending' => ['b-gray', 'مستني الموافقة'],
                            'review' => ['b-orange', 'تحت المراجعة'],
                            'approved' => ['b-green', 'متوافق عليه'],
                            default => ['b-red', 'مرفوض'],
                        };
                        $canDecide = in_array($r['status'], ['pending', 'review']);
                    @endphp
                    <tr>
                        <td class="num">{{ $r['id'] }}</td>
                        <td><b>{{ $r['name'] }}</b></td>
                        <td>{{ $r['rep'] }}</td>
                        <td style="white-space:normal;max-width:240px;color:var(--muted)">{{ $r['address'] }}</td>
                        <td class="num">{{ $r['phone'] }}</td>
                        <td>{{ $r['photo'] ? '✅' : '❌' }}</td>
                        <td>{{ $r['docs'] ? '✅' : '❌' }}</td>
                        <td class="num">{{ $r['time'] }}</td>
                        <td class="st"><span class="badge {{ $cls }}">{{ $txt }}</span></td>
                        <td class="ac">
                            @if ($canDecide)
                                <button class="btn green" onclick="decide(this,'ok')">موافقة</button>
                                <button class="btn red" onclick="decide(this,'no')">رفض</button>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div style="color:var(--muted);font-size:11.5px;margin-top:10px">
        الأزرار ديمو — بتغيّر الحالة على الشاشة بس من غير حفظ. لما نربط الداتابيز القرار هيبعت نوتفيكيشن حقيقي للمندوب.
    </div>
</div>

@endsection

@section('scripts')
<script>
function decide(btn, verdict) {
    const tr = btn.closest('tr');
    tr.querySelector('.st').innerHTML = verdict === 'ok'
        ? '<span class="badge b-green">متوافق عليه</span>'
        : '<span class="badge b-red">مرفوض</span>';
    tr.querySelector('.ac').textContent = '—';
}
</script>
@endsection
