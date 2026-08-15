{{--
    صفوف التحويلات جوه مودالات كارت المندوب — مستخدمة مرتين:
    «مرجّع للمخزن» (rep_wh رايح بس) و«التحويلات» (الاتجاهين).

    الباراميترز:
      - rows:   كوليكشن صفوف من `OpsController::custodyDrill`
                (كل صف فيه `pid` عشان الفلترة في الفرونت)
      - seeDoc: بوليان — يعرض لينك ورقة التحويل ولا لأ

    ⚠️ كل صف عليه `data-pid` والغلاف `data-sec` — `repDrill()` بتخفي
    الصفوف اللي مش بتاعة الصنف المضغوط، وبتخفي القسم كله لو فضي.
--}}
@php
    $trFm = fn ($n) => number_format((float) $n);
    $trDt = fn ($d) => $d?->copy()->timezone('Africa/Cairo')->format('d/m h:i A');
@endphp
<div data-sec>
    <div class="tablewrap" style="max-height:58vh;overflow:auto">
        <table>
            <thead>
            <tr>
                <th>{{ __('ops.rc_d_doc') }}</th>
                <th data-nosum>{{ __('ops.rc_d_dir') }}</th>
                <th style="text-align:start">{{ __('ops.rc_d_party') }}</th>
                <th data-nosum>{{ __('common.time') }}</th>
                <th>{{ __('ops.rc_d_qty') }}</th>
                <th data-nosum>{{ __('stock.batch') }}</th>
                <th style="text-align:start" data-nosum>{{ __('stock.transfer_reason') }}</th>
                <th class="act" data-nosum></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $r)
                <tr data-pid="{{ $r['pid'] }}">
                    <td>
                        @if ($seeDoc)
                            <a href="{{ route('wh.transfers.print', $r['id']) }}"><b>{{ $r['doc'] }}</b></a>
                        @else
                            <b>{{ $r['doc'] }}</b>
                        @endif
                    </td>
                    <td><span class="badge {{ $r['out'] ? 'b-red' : 'b-green' }}">
                        {{ $r['out'] ? __('ops.rc_d_out') : __('ops.rc_d_in') }}</span>
                        <div style="font-size:10px;color:var(--muted)" dir="ltr">
                            {{ \App\Models\StockTransfer::KINDS[$r['kind']][0] ?? '' }}</div>
                    </td>
                    <td style="text-align:start">{{ $r['party'] ?: '—' }}</td>
                    <td class="num" dir="ltr">{{ $trDt($r['at']) }}</td>
                    <td class="num"><b>{{ $trFm($r['qty']) }}</b></td>
                    <td class="num" dir="ltr">{{ $r['batch'] ?: '—' }}</td>
                    <td style="text-align:start;font-size:11.5px;color:var(--muted)">{{ $r['reason'] ?: '—' }}</td>
                    <td class="act">@include('partials._view', [
                        'url' => $seeDoc ? route('wh.transfers.print', $r['id']) : null,
                    ])</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
