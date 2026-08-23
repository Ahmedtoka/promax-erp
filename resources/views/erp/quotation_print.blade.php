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
    <a class="btn" href="{{ route('erp.reports.quotations.edit', $quotation) }}">✏️ {{ __('rpt.qt_edit') }}</a>
    {{-- المتصفح بيطبع → «حفظ PDF» — نفس مسار كل مستندات A4 --}}
    <button class="btn gold" type="button" onclick="window.print()">🖨️ {{ __('rpt.qt_pdf') }}</button>
@endsection

@section('content')

@include('partials._doc_style')

<div class="doc has-bolt" id="qtDoc">
    {!! file_exists(public_path('brand/bolt-watermark.svg'))
        ? '<img src="'.asset('brand/bolt-watermark.svg').'" class="bolt-mark lg" alt="">' : '' !!}

    {{-- ═══ الترويسة — أبيض عشان اللوجو والكلام أسود (قرار المالك ٢٣/٨):
         اللوجو والشركة يمين · «عرض سعر» كبيرة في النص · التاريخ
         والوقت وكود العرض شمال — وخط التدرج الرسمي رفيع تحتها ═══ --}}
    <div class="doc-head qt-head" style="background:#fff;color:var(--ink,#0A0A0F);padding:20px 26px;position:relative;z-index:1">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:14px">
                {{-- اللوجو الأزرق — الأبيض مايبانش على خلفية بيضا --}}
                <img src="{{ file_exists(public_path('brand/logo/logo-h-blue.svg'))
                    ? asset('brand/logo/logo-h-blue.svg') : asset('img/promax-logo.png') }}"
                     alt="PROMAX" class="doc-logo">
                <div>
                    <div style="font-size:15px;font-weight:900">{{ $co['name'] ?: 'PROMAX' }}</div>
                    @if ($co['tax_id'])
                        <div style="font-size:10.5px;color:var(--muted)">{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></div>
                    @endif
                    @if ($co['phone'])
                        <div style="font-size:10.5px;color:var(--muted)">{{ $co['phone'] }}@if ($co['email']) · {{ $co['email'] }}@endif</div>
                    @endif
                </div>
            </div>
            <div style="text-align:center;flex:1">
                <div style="font-size:30px;font-weight:900;letter-spacing:.5px">{{ __('rpt.qt_doc_title') }}</div>
            </div>
            <div style="text-align:end">
                <div style="font-size:16px;font-weight:900;color:var(--royal-blue,#12399B)" dir="ltr">{{ $number }}</div>
                <div style="font-size:11.5px;color:var(--muted)" dir="ltr">
                    {{ ($quotation->created_at ?? now())->format('Y-m-d H:i') }}</div>
            </div>
        </div>
    </div>
    {{-- خط البراند الرفيع — لمسة الهوية من غير ما ياكل من اللوجو --}}
    <div style="height:4px;background:var(--brand-gradient, linear-gradient(135deg,#12399B,#602D90));position:relative;z-index:1"></div>

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

        {{-- ═══ جدول الأصناف الرسمي (قرار المالك ٢٣/٨: ليست في جدول
             مش كروت — عرض رسمي ومحافظ على A4) — صورة صغيرة + كود +
             اسم + أعمدة أسعار الوحدات المجمّدة وقت الإصدار ═══ --}}
        @php
            // فيه ولا صنف له علبة/كرتونة؟ — العمود مايظهرش فاضي للكل
            $unitPrice = function ($l, $key) {
                foreach ((array) ($l['units'] ?? []) as $u) {
                    if (($u['key'] ?? '') === $key) {
                        return $u;
                    }
                }

                return null;
            };
            $hasBox = $lines->contains(fn ($l) => $unitPrice($l, 'box') !== null);
            $hasCase = $lines->contains(fn ($l) => $unitPrice($l, 'case') !== null);

            // ⚠️ الخصم بيبان على مستوى السعر نفسه (قرار المالك ٢٣/٨):
            // السعر الأصلي بخط خفيف وسعر الخصم تحته — مش رقم في التجميعة بس
            $dp = $discount > 0 ? (1 - $discountPct / 100) : 1;

            // خلية سعر: عادية، أو مشطوبة وتحتها سعر الخصم
            $priceCell = function (?float $v, ?int $factor = null) use ($discount, $dp) {
                if ($v === null) {
                    return '—';
                }

                $fac = $factor && $factor > 1
                    ? ' <span class="qi-fac">×'.$factor.'</span>' : '';

                if ($discount <= 0) {
                    return '<b>'.number_format($v, 2).'</b>'.$fac;
                }

                return '<s class="qi-old">'.number_format($v, 2).'</s>'
                    .'<span class="qi-newp">'.number_format($v * $dp, 2).'</span>'.$fac;
            };
        @endphp
        {{-- ⚠️ من غير كمية ولا إجمالي (قرار المالك ٢٣/٨) — ده عرض
             أسعار مش أوردر، والمساحة للأصناف وأسعار وحداتها --}}
        <table class="qi-table">
            <thead>
            <tr>
                <th style="width:26px">#</th>
                <th style="width:56px"></th>
                <th style="width:80px">{{ __('common.code') }}</th>
                <th style="text-align:start">{{ __('rpt.c_product') }}</th>
                <th style="width:110px">{{ __('stock.unit_piece') }}</th>
                @if ($hasBox)<th style="width:110px">{{ __('stock.unit_box') }}</th>@endif
                @if ($hasCase)<th style="width:110px">{{ __('stock.unit_case') }}</th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach ($lines as $i => $l)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>
                        @if ($l['image'] ?? null)
                            <img src="{{ $l['image'] }}" alt="" class="qi-thumb">
                        @else
                            <span class="qi-nothumb">📦</span>
                        @endif
                    </td>
                    <td dir="ltr" style="font-weight:700">{{ $l['code'] ?? '—' }}</td>
                    <td style="text-align:start;font-weight:800">{{ $l['name'] }}</td>
                    <td dir="ltr">{!! $priceCell((float) $l['price']) !!}</td>
                    @if ($hasBox)
                        @php $ub = $unitPrice($l, 'box'); @endphp
                        <td dir="ltr">{!! $priceCell($ub ? (float) $ub['price'] : null, $ub['factor'] ?? null) !!}</td>
                    @endif
                    @if ($hasCase)
                        @php $uc = $unitPrice($l, 'case'); @endphp
                        <td dir="ltr">{!! $priceCell($uc ? (float) $uc['price'] : null, $uc['factor'] ?? null) !!}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>

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
/* ═══ جدول الأصناف الرسمي — مضبوط على A4 ═══ */
.qi-table{width:100%;border-collapse:collapse;font-size:11.5px;
  font-variant-numeric:tabular-nums}
.qi-table thead tr{background:var(--royal-blue,#12399B);color:#fff}
.qi-table th{padding:7px 8px;text-align:center;font-size:11px;white-space:nowrap}
.qi-table td{padding:5px 8px;text-align:center;border-bottom:1px solid var(--border)}
.qi-table tbody tr{page-break-inside:avoid;break-inside:avoid}
.qi-table tbody tr:nth-child(even){background:var(--card2,#F7F7FA)}
.qi-thumb{width:48px;height:48px;object-fit:contain;border-radius:7px;
  border:1px solid var(--border);background:#fff;display:block;margin:0 auto}
.qi-nothumb{font-size:20px;color:var(--muted)}
.qi-fac{font-size:9.5px;color:var(--muted);font-weight:700}
/* الخصم على مستوى السعر: الأصلي مشطوب خفيف وسعر الخصم تحته */
.qi-old{display:block;color:var(--muted);font-size:10px;font-weight:600;
  text-decoration:line-through;text-decoration-thickness:1px;opacity:.75}
.qi-newp{display:block;font-weight:900;color:var(--royal-blue,#12399B);font-size:12.5px}
/* ═══ ختم الخصم المايل ═══ */
.qi-stamp{position:absolute;inset-inline-start:8%;top:6px;transform:rotate(-10deg);
  border:3px solid var(--red,#DC2626);color:var(--red,#DC2626);border-radius:10px;
  padding:7px 20px;font-size:17px;font-weight:900;letter-spacing:.5px;opacity:.85;
  background:rgba(220,38,38,.045);pointer-events:none;white-space:nowrap}

@media print{
    .sidebar, .topbar, .btn { display:none !important; }
    .main{padding:0 !important}
    #qtDoc{border:none;box-shadow:none;max-width:100%}
    .qi-table thead tr, .qi-stamp{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .qi-table{font-size:10.5px}
    .qi-thumb{width:42px;height:42px}
}
</style>
@endsection
