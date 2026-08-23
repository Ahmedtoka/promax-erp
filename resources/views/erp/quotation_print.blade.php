@extends('layouts.system')

{{--
    ورقة الكوتيشن A4 (إعادة بناء ٢٣ أغسطس ٢٠٢٦ — بالبراندينج):

    • ترويسة بالتدرج الرسمي + اللوجو (نفس مرجعية الفاتورة).
    • كل صنف كارت: صورة كبيرة ← الكود ← الاسم ← أسعار الوحدات
      (القطعة بكذا · العلبة بكذا · الكرتونة بكذا لو موجودة).
    • خصم خاص > 0 = ختم مايل على التجميعة.
    • العروض القديمة (قبل الترقية، من غير كود/وحدات) بتترندر
      بنفس الكروت بس من غير صورة ولا أسعار وحدات — مفيش كسر.
--}}

@section('title', __('rpt.quotation').' '.$number)

@section('actions')
    <a class="btn" href="{{ route('erp.reports.quotations') }}">← {{ __('rpt.qts_title') }}</a>
    <a class="btn" href="{{ route('erp.reports.quotation') }}">➕ {{ __('rpt.qts_new') }}</a>
    {{-- المتصفح بيطبع → «حفظ PDF» — نفس مسار كل مستندات A4 --}}
    <button class="btn gold" type="button" onclick="window.print()">🖨️ {{ __('rpt.qt_pdf') }}</button>
@endsection

@section('content')

@include('partials._doc_style')

<div class="doc has-bolt" id="qtDoc">
    {!! file_exists(public_path('brand/bolt-watermark.svg'))
        ? '<img src="'.asset('brand/bolt-watermark.svg').'" class="bolt-mark lg" alt="">' : '' !!}

    {{-- ═══ الترويسة — اللوجو والتدرج الرسمي ═══ --}}
    <div class="doc-head" style="background:var(--brand-gradient, linear-gradient(135deg,#12399B,#602D90));color:#fff;padding:20px 26px;position:relative;z-index:1">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:16px">
                <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
                <div>
                    <div style="font-size:17px;font-weight:900">{{ $co['name'] ?: 'PROMAX' }}</div>
                    @if ($co['tax_id'])
                        <div style="font-size:10.5px;opacity:.85">{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></div>
                    @endif
                    @if ($co['phone'])
                        <div style="font-size:10.5px;opacity:.85">{{ $co['phone'] }}@if ($co['email']) · {{ $co['email'] }}@endif</div>
                    @endif
                </div>
            </div>
            <div style="text-align:center">
                <div style="font-size:12px;opacity:.85;letter-spacing:1.5px">{{ __('rpt.qt_doc_title') }}</div>
                <div style="font-size:25px;font-weight:900" dir="ltr">{{ $number }}</div>
                <div style="font-size:11px;opacity:.85">{{ ($quotation->created_at ?? today())->format('Y-m-d') }}</div>
            </div>
        </div>
    </div>

    <div style="padding:20px 26px;position:relative;z-index:1">
        {{-- ═══ لمين + القايمة + الصلاحية ═══ --}}
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;
                    background:var(--blue-050,#E8F1FF);border:1px solid var(--royal-blue,#12399B);
                    border-radius:12px;padding:12px 15px;margin-bottom:16px">
            <div>
                <div style="font-size:10.5px;color:var(--muted)">{{ __('rpt.qt_to') }}</div>
                <div style="font-size:15px;font-weight:900">{{ $clientName }}</div>
            </div>
            @if ($priceListName ?? null)
                <div style="text-align:center">
                    <div style="font-size:10.5px;color:var(--muted)">{{ __('rpt.qt_list') }}</div>
                    <div style="font-size:13px;font-weight:800">{{ $priceListName }}</div>
                </div>
            @endif
            <div style="text-align:end">
                <div style="font-size:10.5px;color:var(--muted)">{{ __('rpt.qt_valid_until') }}</div>
                <div style="font-size:13.5px;font-weight:800">{{ $validUntil->format('Y-m-d') }}</div>
            </div>
        </div>

        {{-- ═══ كروت الأصناف — صورة كبيرة + كود + اسم + أسعار الوحدات ═══ --}}
        <div class="qi-grid">
            @foreach ($lines as $l)
                <div class="qi-card">
                    <div class="qi-imgwrap">
                        @if ($l['image'] ?? null)
                            <img src="{{ $l['image'] }}" alt="" class="qi-img">
                        @else
                            <div class="qi-noimg">📦</div>
                        @endif
                    </div>
                    @if ($l['code'] ?? null)
                        <div class="qi-code" dir="ltr">{{ $l['code'] }}</div>
                    @endif
                    <div class="qi-name">{{ $l['name'] }}</div>

                    {{-- أسعار الوحدات المجمّدة وقت الإصدار --}}
                    <div class="qi-units">
                        @if (is_array($l['units'] ?? null) && count($l['units']))
                            @foreach ($l['units'] as $u)
                                <div class="qi-unit">
                                    <span>{{ __('stock.unit_'.$u['key']) }}@if (($u['factor'] ?? 1) > 1) ({{ $u['factor'] }})@endif</span>
                                    <b dir="ltr">{{ number_format($u['price'], 2) }}</b>
                                </div>
                            @endforeach
                        @else
                            <div class="qi-unit">
                                <span>{{ __('stock.unit_piece') }}</span>
                                <b dir="ltr">{{ number_format($l['price'], 2) }}</b>
                            </div>
                        @endif
                    </div>

                    <div class="qi-foot">
                        <span>{{ number_format($l['qty']) }} × {{ number_format($l['price'], 2) }}</span>
                        <b dir="ltr">{{ number_format($l['total'], 2) }}</b>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ═══ التجميعة + ختم الخصم ═══ --}}
        <div style="display:flex;justify-content:flex-end;margin-top:16px;position:relative">
            @if ($discount > 0)
                {{-- الختم المايل — بيبان لما يكون فيه خصم خاص بس --}}
                <div class="qi-stamp">
                    {{ __('rpt.qt_disc_stamp', ['p' => rtrim(rtrim(number_format($discountPct, 1), '0'), '.')]) }}
                </div>
            @endif
            <table style="min-width:290px;font-size:12.5px;border-collapse:collapse">
                <tr>
                    <td style="padding:4px 10px;color:var(--muted)">{{ __('rpt.qt_subtotal') }}</td>
                    <td style="padding:4px 10px;text-align:end;font-weight:700" dir="ltr">{{ number_format($subtotal, 2) }}</td>
                </tr>
                @if ($discount > 0)
                    <tr>
                        <td style="padding:4px 10px;color:var(--muted)">{{ __('rpt.qt_disc') }} ({{ rtrim(rtrim(number_format($discountPct, 1), '0'), '.') }}%)</td>
                        <td style="padding:4px 10px;text-align:end;font-weight:700;color:var(--red,#DC2626)" dir="ltr">-{{ number_format($discount, 2) }}</td>
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
/* ═══ كروت الأصناف — عمودين على A4 ═══ */
.qi-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.qi-card{border:1px solid var(--border);border-radius:14px;overflow:hidden;background:#fff;
  page-break-inside:avoid;break-inside:avoid;display:flex;flex-direction:column}
/* الصورة الكبيرة — طلب المالك ~300px */
.qi-imgwrap{background:#fff;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;height:300px;padding:12px}
.qi-img{max-width:100%;max-height:100%;object-fit:contain}
.qi-noimg{font-size:56px;color:var(--muted)}
.qi-code{margin:10px 14px 0;display:inline-block;align-self:flex-start;
  background:var(--blue-050,#E8F1FF);color:var(--royal-blue,#12399B);
  font-weight:800;font-size:11px;border-radius:999px;padding:2px 11px}
.qi-name{font-size:14.5px;font-weight:900;margin:6px 14px 0}
.qi-units{margin:8px 14px;display:flex;flex-direction:column;gap:3px}
.qi-unit{display:flex;justify-content:space-between;font-size:12px;
  border-bottom:1px dashed var(--border);padding:3px 0}
.qi-unit b{color:var(--royal-blue,#12399B)}
.qi-foot{margin-top:auto;display:flex;justify-content:space-between;align-items:center;
  background:var(--card2,#F1F1F4);padding:8px 14px;font-size:12px;font-weight:800}
/* ═══ ختم الخصم المايل ═══ */
.qi-stamp{position:absolute;inset-inline-start:8%;top:6px;transform:rotate(-10deg);
  border:3px solid var(--red,#DC2626);color:var(--red,#DC2626);border-radius:10px;
  padding:7px 20px;font-size:17px;font-weight:900;letter-spacing:.5px;opacity:.85;
  background:rgba(220,38,38,.045);pointer-events:none;white-space:nowrap}

@media print{
    .sidebar, .topbar, .btn { display:none !important; }
    .main{padding:0 !important}
    #qtDoc{border:none;box-shadow:none;max-width:100%}
    .qi-grid{gap:10px}
    .qi-imgwrap{height:260px}
    .qi-stamp{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
@endsection
