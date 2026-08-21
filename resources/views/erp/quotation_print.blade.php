@extends('layouts.system')

@section('title', __('rpt.quotation').' '.$number)

@section('actions')
    <a class="btn" href="{{ route('erp.reports.quotation') }}">← {{ __('rpt.quotation') }}</a>
    {{-- المتصفح بيطبع → «حفظ PDF» — نفس مسار كل مستندات A4 في السيستم --}}
    <button class="btn gold" type="button" onclick="window.print()">🖨️ {{ __('rpt.qt_pdf') }}</button>
@endsection

@section('content')

@include('partials._doc_style')

<div class="doc has-bolt" id="qtDoc">
    {!! file_exists(public_path('brand/bolt-watermark.svg'))
        ? '<img src="'.asset('brand/bolt-watermark.svg').'" class="bolt-mark lg" alt="">' : '' !!}

    {{-- ═══ الهيدر — الشركة يمين والمستند شمال ═══ --}}
    <div style="background:var(--brand-gradient, linear-gradient(135deg,#12399B,#602D90));color:#fff;padding:22px 26px;position:relative;z-index:1">
        <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap">
            <div>
                <div style="font-size:19px;font-weight:900">{{ $co['name'] ?: 'PROMAX' }}</div>
                @if ($co['tax_id'])
                    <div style="font-size:11px;opacity:.85">{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></div>
                @endif
                @if ($co['cr'])
                    <div style="font-size:11px;opacity:.85">{{ __('doc.cr') }}: <b>{{ $co['cr'] }}</b></div>
                @endif
                @if ($co['phone'])
                    <div style="font-size:11px;opacity:.85">{{ $co['phone'] }}@if ($co['email']) · {{ $co['email'] }}@endif</div>
                @endif
            </div>
            <div style="text-align:center">
                <div style="font-size:13px;opacity:.85;letter-spacing:1px">{{ __('rpt.qt_doc_title') }}</div>
                <div style="font-size:24px;font-weight:900" dir="ltr">{{ $number }}</div>
                <div style="font-size:11.5px;opacity:.85">{{ today()->format('Y-m-d') }}</div>
            </div>
        </div>
    </div>

    <div style="padding:20px 26px;position:relative;z-index:1">
        {{-- ═══ لمين + الصلاحية ═══ --}}
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;
                    border:1px solid var(--border);border-radius:12px;padding:12px 15px;margin-bottom:16px">
            <div>
                <div style="font-size:10.5px;color:var(--muted)">{{ __('rpt.qt_to') }}</div>
                <div style="font-size:15px;font-weight:900">{{ $clientName }}</div>
            </div>
            <div style="text-align:end">
                <div style="font-size:10.5px;color:var(--muted)">{{ __('rpt.qt_valid_until') }}</div>
                <div style="font-size:13.5px;font-weight:800">{{ $validUntil->format('Y-m-d') }}</div>
            </div>
        </div>

        {{-- ═══ البنود ═══ --}}
        <table style="width:100%;border-collapse:collapse;font-size:12.5px">
            <thead>
            <tr style="background:var(--royal-blue,#12399B);color:#fff">
                <th style="padding:8px 10px;text-align:start">#</th>
                <th style="padding:8px 10px;text-align:start">{{ __('rpt.c_product') }}</th>
                <th style="padding:8px 10px">{{ __('rpt.k_qty') }}</th>
                <th style="padding:8px 10px">{{ __('rpt.qt_price') }}</th>
                <th style="padding:8px 10px">{{ __('common.total') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($lines as $i => $l)
                <tr style="border-bottom:1px solid var(--border)">
                    <td style="padding:7px 10px">{{ $i + 1 }}</td>
                    <td style="padding:7px 10px;font-weight:700">{{ $l['name'] }}</td>
                    <td style="padding:7px 10px;text-align:center" dir="ltr">{{ number_format($l['qty']) }}</td>
                    <td style="padding:7px 10px;text-align:center" dir="ltr">{{ number_format($l['price'], 2) }}</td>
                    <td style="padding:7px 10px;text-align:center;font-weight:800" dir="ltr">{{ number_format($l['total'], 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{-- ═══ التجميعة ═══ --}}
        <div style="display:flex;justify-content:flex-end;margin-top:14px">
            <table style="min-width:280px;font-size:12.5px;border-collapse:collapse">
                <tr>
                    <td style="padding:4px 10px;color:var(--muted)">{{ __('rpt.qt_subtotal') }}</td>
                    <td style="padding:4px 10px;text-align:end;font-weight:700" dir="ltr">{{ number_format($subtotal, 2) }}</td>
                </tr>
                @if ($discount > 0)
                    <tr>
                        <td style="padding:4px 10px;color:var(--muted)">{{ __('rpt.qt_disc') }} ({{ rtrim(rtrim(number_format($discountPct, 1), '0'), '.') }}%)</td>
                        <td style="padding:4px 10px;text-align:end;font-weight:700;color:var(--royal-blue,#12399B)" dir="ltr">-{{ number_format($discount, 2) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 10px;color:var(--muted)">{{ __('rpt.qt_net') }}</td>
                        <td style="padding:4px 10px;text-align:end;font-weight:700" dir="ltr">{{ number_format($net, 2) }}</td>
                    </tr>
                @endif
                @if ($tax > 0)
                    <tr>
                        <td style="padding:4px 10px;color:var(--muted)">{{ __('rpt.qt_tax') }} ({{ rtrim(rtrim(number_format($taxPct, 1), '0'), '.') }}%)</td>
                        <td style="padding:4px 10px;text-align:end;font-weight:700" dir="ltr">{{ number_format($tax, 2) }}</td>
                    </tr>
                @endif
                <tr style="border-top:2px solid var(--royal-blue,#12399B)">
                    <td style="padding:8px 10px;font-weight:900">{{ __('rpt.qt_grand') }}</td>
                    <td style="padding:8px 10px;text-align:end;font-weight:900;font-size:16px;color:var(--royal-blue,#12399B)" dir="ltr">
                        {{ number_format($grand, 2) }} {{ __('common.currency') }}</td>
                </tr>
            </table>
        </div>

        @if ($notes)
            <div style="margin-top:14px;border:1px dashed var(--border);border-radius:10px;padding:10px 13px;font-size:12px;line-height:1.8">
                <b>{{ __('rpt.qt_notes') }}:</b> {{ $notes }}
            </div>
        @endif

        {{-- بيانات التحويل البنكي — لو مسجلة في الإعدادات --}}
        @php $bankQt = array_filter($co['bank']); @endphp
        @if ($bankQt && ! ($co['bank_demo'] ?? false))
            <div style="margin-top:14px;border:1px solid var(--border);border-radius:10px;padding:10px 13px;font-size:11.5px">
                <b>🏦 {{ __('doc.bank_box') }}</b>
                <div style="display:flex;gap:6px 22px;flex-wrap:wrap;margin-top:5px">
                    @foreach ([
                        'doc.bank_name' => $co['bank']['name'],
                        'doc.bank_branch' => $co['bank']['branch'],
                        'doc.bank_account_name' => $co['bank']['account_name'],
                        'doc.bank_account_no' => $co['bank']['account_no'],
                        'doc.bank_iban' => $co['bank']['iban'],
                        'doc.bank_swift' => $co['bank']['swift'],
                    ] as $lk => $lv)
                        @if ($lv)
                            <span>{{ __($lk) }}: <b dir="ltr">{{ $lv }}</b></span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <div style="margin-top:18px;font-size:10.5px;color:var(--muted);line-height:1.8">
            {{ __('rpt.qt_footer', ['date' => $validUntil->format('Y-m-d')]) }}
        </div>

        {{-- التوقيع — بيبان في الطباعة --}}
        <div class="doc-sign" style="display:flex;justify-content:space-between;margin-top:30px;font-size:12px">
            <div>{{ __('rpt.qt_sign_company') }}: ____________________</div>
            <div>{{ __('rpt.qt_sign_client') }}: ____________________</div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<style>
@media print{
    .sidebar, .topbar, .btn { display:none !important; }
    .main{padding:0 !important}
    #qtDoc{border:none;box-shadow:none;max-width:100%}
}
</style>
@endsection
