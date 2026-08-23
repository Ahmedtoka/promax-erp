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
                {{-- التليفون والإيميل اتشالوا من فوق (٢٣/٨) — مكانهم
                     الفوتر تحت مع العنوان --}}
                <div>
                    <div style="font-size:15px;font-weight:900">{{ $co['name'] ?: 'PROMAX' }}</div>
                    @if ($co['tax_id'])
                        <div style="font-size:10.5px;color:var(--muted)">{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></div>
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
            {{-- ⚠️ اسم القايمة الداخلي («سعر المستهلك») مالوش مكان قدام
                 العميل (٢٣/٨) — الكلمة العامة «قائمة الأسعار» بس --}}
            <div style="text-align:center;align-self:center">
                <div style="font-size:15px;font-weight:900;letter-spacing:.5px">{{ __('rpt.qt_list_title') }}</div>
            </div>
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

            // ⚠️ السعر المعروض = **بعد الخصمين على طول** (قرار المالك
            // ٢٣/٨ الأخير: من غير شطب قبل/بعد) — الخصمين تسلسليين.
            $dp = (1 - ($discountPct ?? 0) / 100) * (1 - ($extraPct ?? 0) / 100);

            // خلية سعر: السعر النهائي + «12 قطعة» جمب العلبة/الكرتونة
            $pieceWord = __('stock.unit_piece');
            $priceCell = function (?float $v, ?int $factor = null) use ($dp, $pieceWord) {
                if ($v === null) {
                    return '—';
                }

                $fac = $factor && $factor > 1
                    ? ' <span class="qi-fac">'.$factor.' '.e($pieceWord).'</span>' : '';

                return '<b>'.number_format($v * $dp, 2).'</b>'.$fac;
            };

            // ⚠️ الوزن بيتفصل من آخر الاسم لعموده (٢٣/٨) — «بروتين بار
            // 70 جم» ← الاسم «بروتين بار» والوزن «70 جم». الأسماء
            // المجمّدة ماتتغيرش في الداتابيز — فصل عرض بس.
            $splitWeight = function (string $name): array {
                if (preg_match('/^(.*?)[\s\-·،]*(\d+(?:[.,]\d+)?\s*(?:جم|جرام|كجم|كيلو ?جرام|كيلو|مل|لتر|g|gm|gr|kg|ml|l))\s*\.?$/iu', trim($name), $m)) {
                    $base = trim($m[1], " \t-·،");

                    if ($base !== '') {
                        return [$base, $m[2]];
                    }
                }

                return [$name, null];
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
                <th style="width:70px">{{ __('rpt.qt_weight') }}</th>
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
                    @php [$qiName, $qiWeight] = $splitWeight((string) $l['name']); @endphp
                    <td dir="ltr" style="font-weight:700">{{ $l['code'] ?? '—' }}</td>
                    <td style="text-align:start;font-weight:800">{{ $qiName }}</td>
                    <td style="font-weight:700;white-space:nowrap">{{ $qiWeight ?? '—' }}</td>
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

        {{-- ═══ بدل الإجماليات (قرار المالك ٢٣/٨): تاجين للخصمين +
             جملة «شاملة/غير شاملة الضريبة» — الأسعار في الجدول
             أصلاً بعد الخصم، فمفيش حسابات مكررة تحت ═══ --}}
        @php $fmtPct = fn ($p) => rtrim(rtrim(number_format((float) $p, 1), '0'), '.'); @endphp
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px">
            @if (($discountPct ?? 0) > 0)
                <span class="qi-tag">🏷️ {{ __('rpt.qt_tag_disc', ['p' => $fmtPct($discountPct)]) }}</span>
            @endif
            @if (($extraPct ?? 0) > 0)
                <span class="qi-tag qi-tag2">➕ {{ __('rpt.qt_tag_extra', ['p' => $fmtPct($extraPct)]) }}</span>
            @endif
            <span style="margin-inline-start:auto;font-size:12.5px;font-weight:900">
                {{ ($taxInclusive ?? true) ? __('rpt.qt_incl') : __('rpt.qt_excl') }}
            </span>
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

        {{-- ═══ فوتر الشركة (بدل توقيعات عن الشركة/العميل — ٢٣/٨):
             العنوان والتليفون والإيميل اللي اتشالوا من فوق ═══ --}}
        <div style="margin-top:24px;border-top:2px solid var(--royal-blue,#12399B);padding-top:10px;
                    display:flex;gap:8px 26px;flex-wrap:wrap;justify-content:center;
                    font-size:11px;font-weight:700;color:var(--ink,#0A0A0F)">
            @if ($co['address'])<span>📍 {{ $co['address'] }}</span>@endif
            @if ($co['phone'])<span dir="ltr">📞 {{ $co['phone'] }}</span>@endif
            @if ($co['email'])<span dir="ltr">✉️ {{ $co['email'] }}</span>@endif
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
.qi-thumb{width:66px;height:66px;object-fit:contain;border-radius:8px;
  border:1px solid var(--border);background:#fff;display:block;margin:0 auto}
.qi-nothumb{font-size:24px;color:var(--muted)}
.qi-fac{font-size:9.5px;color:var(--muted);font-weight:700;white-space:nowrap}
/* اللوجو فوق صغير — كان بياكل الترويسة */
.qt-head .doc-logo{height:36px}
/* ═══ تاجات الخصم — بدل جدول الإجماليات والختم ═══ */
.qi-tag{display:inline-block;border:2px solid var(--red,#DC2626);color:var(--red,#DC2626);
  background:rgba(220,38,38,.05);border-radius:999px;padding:5px 16px;
  font-size:13px;font-weight:900;white-space:nowrap}
.qi-tag2{border-color:var(--royal-blue,#12399B);color:var(--royal-blue,#12399B);
  background:var(--blue-050,#E8F1FF)}

@media print{
    .sidebar, .topbar, .btn { display:none !important; }
    .main{padding:0 !important}
    #qtDoc{border:none;box-shadow:none;max-width:100%}
    .qi-table thead tr, .qi-tag, .qi-tag2{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .qi-table{font-size:10.5px}
    .qi-thumb{width:58px;height:58px}
}
</style>
@endsection
