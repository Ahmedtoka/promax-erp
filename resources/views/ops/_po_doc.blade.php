{{--
    الفاتورة المؤقتة (أمر التوريد سابقاً) — مشتركة بين الطباعة
    الفردية (po_print) والمجمعة (po_print_batch). بتاخد `$po` محمّل
    بعلاقاته. الستايل في `ops/_po_doc_style`.

    ⚠️ **المستند ده بيتسلّم لفرع وبيتمضى عليه.** أي رقم عليه من
    السطور المخزّنة على الأمر نفسه، مش من حالة العميل دلوقتي.

    ⚠️ **التجميعة بتتجمع من سطور الجدول نفسها** (طلب المالك ٩/٨ —
    «الحسابات مش محسوبة صح»): الأعمدة المجمّعة القديمة على الأمر
    ممكن تكون من قبل تفعيل الضريبة أو قبل تخزين الخصم، فالورقة
    كانت بتطبع تجميعة مش بتساوي مجموع جدولها. الجمع من السطور
    بيضمن إن اللي بيراجع بالآلة الحاسبة قدام الفرع يطلع بنفس الرقم.

    ⚠️ **التقسيم على ورقات (طلب المالك ١٠/٨ — «الأصناف الكتير على
    صفحتين تلاتة»):** الأمر الكبير بيتقسم على أكتر من ورقة A4 — كل
    ورقة بتكرر الهيدر ورأس الجدول ومعاها «صفحة X من Y»، والتجميعة
    وبيانات البنك والتوقيعات والفوتر على **آخر ورقة بس**. التجميعة
    بتتحسب مرة واحدة من كل السطور — مش لكل ورقة. لو ١٥ صف أو أقل
    → ورقة واحدة مطابقة تماماً للشكل القديم.
--}}

@php
    $fmtDoc = fn ($n) => number_format((float) $n, 2);
    $co = \App\Models\Setting::docHeader();

    $qtyTotalDoc = (int) $po->items->sum('qty');

    // ═══ التجميعة كلها من السطور — مصدر واحد هو الجدول المطبوع ═══
    $netDoc = round((float) $po->items->sum(fn ($i) => (float) $i->total), 2);
    $taxDoc = round((float) $po->items->sum(fn ($i) => (float) $i->tax), 2);
    $grandDoc = round($netDoc + $taxDoc, 2);

    $grossDoc = 0.0;
    $discDoc = 0.0;
    foreach ($po->items as $itDoc) {
        $grossDoc += $itDoc->listPrice() * (int) $itDoc->qty;
        $discDoc += $itDoc->discountValue();
    }
    $grossDoc = round($grossDoc, 2);
    $discDoc = round($discDoc, 2);

    // النسبة والأساس الخاضع — من السطور، والمعفى سطر مستقل
    $taxableBaseDoc = round((float) $po->items->where('tax', '>', 0)->sum('total'), 2);
    $exemptBaseDoc = round($netDoc - $taxableBaseDoc, 2);

    $ratesDoc = $po->items->where('tax', '>', 0)->pluck('tax_rate')
        ->map(fn ($r) => round((float) $r * 100, 2))->unique()->values();

    $taxRateDoc = $ratesDoc->count() === 1
        ? $ratesDoc->first()
        : ($taxableBaseDoc > 0 ? round($taxDoc / $taxableBaseDoc * 100, 2) : 0);

    $rateLabelDoc = rtrim(rtrim(number_format((float) $taxRateDoc, 2), '0'), '.');

    // ⚠️ بوكس البنك بيتطبع طول ما فيه بيانات — قرار المالك ٩/٨:
    // «نحط بيانات ديمو وأغيرها من الداش بورد». التحذير من الديمو
    // مكانه شاشة الإعدادات مش الورقة.
    $bankDoc = array_filter($co['bank']);

    // ═══ تقسيم السطور على ورقات A4 (١٠/٨) ═══
    // آخر ورقة شايلة التجميعة والبنك والتوقيعات → ١٥ صف زي زمان.
    // ورقة التكملة جدول بس — المساحة اللي فضيت من التجميعة بتشيل
    // ٢٠ صف بأمان. واللوب بيسيب صف على الأقل لآخر ورقة عشان
    // التجميعة ماتطلعش على ورقة جدولها كله فاضي.
    $rowsLastDoc = 15;
    $rowsFullDoc = 20;
    $pagesDoc = [];
    $restDoc = $po->items->values();
    while ($restDoc->count() > $rowsLastDoc) {
        $takeDoc = min($rowsFullDoc, $restDoc->count() - 1);
        $pagesDoc[] = $restDoc->slice(0, $takeDoc)->values();
        $restDoc = $restDoc->slice($takeDoc)->values();
    }
    $pagesDoc[] = $restDoc;
    $pageCountDoc = count($pagesDoc);

    // ⚠️ ١٥ صف على الأقل في آخر ورقة — فاتورة نص صفحتها فاضي شكلها
    // ناقص، والصفوف الفاضية بتثبّت ارتفاع الجدول على الـA4.
    $padRows = max(0, 15 - $restDoc->count());

    // ترقيم السطور متواصل عبر الورقات — مش بيبدأ من ١ كل ورقة
    $rowStartDoc = 0;
@endphp

@foreach ($pagesDoc as $pageItemsDoc)
@php $isLastDoc = $loop->last; @endphp
<div class="doc po-doc has-bolt{{ $isLastDoc ? '' : ' po-cont' }}">
    <img class="bolt-mark po-bolt" src="{{ asset('brand/bolt.svg') }}" alt="">

    {{-- ═══ الهيدر المضغوط: اللوجو وجنبه البيانات · والناحية
         التانية الرقم والتاريخ والوقت بليبل — بيتكرر على كل ورقة ═══ --}}
    <header class="doc-head">
        <div class="po-brandrow">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="po-corp">
                <div class="po-corp-name">{{ $co['name'] ?: __('ops.corp_name') }}</div>
                @if ($co['tax_id'])
                    <div class="po-corp-line">{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></div>
                @endif
                @if ($co['cr'])
                    <div class="po-corp-line">{{ __('doc.cr') }}: <b>{{ $co['cr'] }}</b></div>
                @endif
            </div>
        </div>

        <div class="doc-id">
            <div class="doc-no">{{ $po->number }}</div>
            @if ($po->source)
                <div class="doc-date">{{ __('ops.po_source_no') }}: <b>{{ $po->source }}</b></div>
            @endif
            <div class="doc-date">{{ __('doc.date') }}:
                <b>{{ $po->created_at?->format('Y-m-d') ?? '—' }}</b></div>
            <div class="doc-date">{{ __('doc.time') }}:
                <b>{{ $po->created_at?->format('h:i A') ?? '—' }}</b></div>
            @if ($pageCountDoc > 1)
                <div class="doc-date po-pageno">{{ __('doc.page_of', ['p' => $loop->iteration, 't' => $pageCountDoc]) }}</div>
            @endif
        </div>
    </header>

    {{-- العنوان الكبير — «فاتورة مؤقتة» (قرار المالك ٩/٨) --}}
    <div class="po-title">{{ __('doc.proforma') }}</div>

    <div class="doc-body">
        {{-- ═══ سطر الأطراف: المندوب + بواسطة · المخزن + الميعاد ═══ --}}
        <div class="po-parties">
            <div class="po-party">
                <span>{{ __('doc.rep_name') }}: <b>{{ $po->courier?->name ?? '—' }}</b></span>
                <span class="sep">·</span>
                <span>{{ __('doc.made_by') }}: <b>{{ $po->creator?->name ?? '—' }}</b></span>
            </div>
            <div class="po-party">
                <span>{{ __('stock.warehouse') }}: <b>{{ $po->warehouse?->displayName() ?? '—' }}</b></span>
                <span class="sep">·</span>
                <span>{{ __('ops.due_at') }}:
                    <b>{{ $po->due_at?->format('Y-m-d h:i A') ?? ($po->due_date?->format('Y-m-d') ?? '—') }}</b></span>
            </div>
        </div>

        {{-- سطر كامل بالعرض — العميل وعنوانه بيطول (طلب المالك) --}}
        <div class="po-client-line">
            <span>{{ __('ops.branch_client') }}: <b>{{ $po->client?->fullName() ?? '—' }}</b></span>
            <span class="sep">·</span>
            <span>{{ __('doc.address') }}: {{ $po->client?->address ?: '—' }}</span>
            @if ($po->client?->tax_id)
                <span class="sep">·</span>
                <span>{{ __('doc.tax_id') }}: {{ $po->client->tax_id }}</span>
            @endif
        </div>

        {{-- ═══ الجدول — من غير سكرول، ورأسه بيتكرر على كل ورقة ═══ --}}
        <table class="doc-table po-table">
            <tr>
                <th class="c-no">{{ __('doc.line_no') }}</th>
                <th class="c-bar">{{ __('stock.barcode') }}</th>
                <th class="c-item">{{ __('stock.item') }}</th>
                <th class="num c-qty">{{ __('common.qty') }}</th>
                <th class="num">{{ __('doc.unit_price') }}</th>
                <th class="num c-disc">{{ __('doc.discount_pct') }}</th>
                <th class="num">{{ __('doc.price_after_discount') }}</th>
                <th class="num">{{ __('common.total') }}</th>
            </tr>

            @foreach ($pageItemsDoc as $iDoc => $it)
                <tr>
                    <td class="num">{{ $rowStartDoc + $iDoc + 1 }}</td>
                    <td class="num bar">{{ $it->product?->barcode ?? '—' }}</td>
                    <td><b>{{ $it->product?->displayName() ?? '—' }}</b></td>
                    <td class="num">
                        <b>{{ number_format($it->qty) }}</b>
                        <span class="u">{{ __('stock.unit_piece') }}</span>
                        @if ($bd = $it->product?->packBreakdown((int) $it->qty))
                            <div class="s">{{ $bd }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $fmtDoc($it->listPrice()) }}</td>
                    <td class="num">
                        @if ($it->discountPercent() > 0)
                            <b class="disc">{{ rtrim(rtrim(number_format($it->discountPercent(), 2), '0'), '.') }}%</b>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td class="num"><b>{{ $fmtDoc($it->price) }}</b></td>
                    <td class="num"><b>{{ $fmtDoc($it->total) }}</b></td>
                </tr>
            @endforeach

            @if ($isLastDoc)
                {{-- صفوف فاضية لحد ١٥ — بتثبّت الشكل وبتمنع الإضافة بالإيد --}}
                @for ($r = 0; $r < $padRows; $r++)
                    <tr class="pad"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                @endfor

                <tr class="sum">
                    <td colspan="3"><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ number_format($qtyTotalDoc) }}</b></td>
                    <td colspan="2"></td>
                    <td></td>
                    <td class="num"><b>{{ $fmtDoc($netDoc) }}</b></td>
                </tr>
            @endif
        </table>

        @if ($isLastDoc)
            {{-- ═══ التجميعة جنب بيانات البنك — آخر ورقة بس ═══ --}}
            <div class="po-summary">
                @if ($bankDoc !== [])
                    <div class="po-bank">
                        <div class="bk-h">{{ __('doc.bank_details') }}</div>
                        <div class="bk-warn">{{ __('doc.bank_note') }}</div>
                        <table class="bk-t">
                            @foreach ([
                                'doc.bank_name' => $co['bank']['name'],
                                'doc.bank_branch' => $co['bank']['branch'],
                                'doc.bank_account_name' => $co['bank']['account_name'],
                                'doc.bank_account_no' => $co['bank']['account_no'],
                                'doc.bank_iban' => $co['bank']['iban'],
                                'doc.bank_swift' => $co['bank']['swift'],
                            ] as $bkKey => $bkVal)
                                @if ($bkVal)
                                    <tr><td>{{ __($bkKey) }}</td><td><b>{{ $bkVal }}</b></td></tr>
                                @endif
                            @endforeach
                        </table>
                    </div>
                @endif

                <div class="doc-totals">
                    <div class="row"><span>{{ __('doc.gross_before_discount') }}</span><span>{{ $fmtDoc($grossDoc) }}</span></div>
                    <div class="row disc"><span>{{ __('doc.discount_value') }}</span><span>− {{ $fmtDoc($discDoc) }}</span></div>

                    <div class="row net"><span>{{ __('doc.net_before_tax') }}</span><span>{{ $fmtDoc($netDoc) }}</span></div>

                    {{-- السطر بيبان حتى لو صفر طول ما الضريبة مفعّلة —
                         الفرع يعرف إن الأمر معفى بقصد مش بالنسيان --}}
                    @if ($taxDoc > 0 || \App\Services\Tax::enabled())
                        @if ($exemptBaseDoc > 0 && $taxDoc > 0)
                            <div class="row"><span>{{ __('doc.exempt_base') }}</span><span>{{ $fmtDoc($exemptBaseDoc) }}</span></div>
                        @endif
                        <div class="row tax">
                            <span>{{ __('doc.vat_on', ['rate' => $rateLabelDoc, 'base' => $fmtDoc($taxableBaseDoc)]) }}</span>
                            <span>{{ $fmtDoc($taxDoc) }}</span>
                        </div>
                    @endif

                    <div class="row grand">
                        <span>{{ __('doc.total_with_tax') }}</span>
                        <span>{{ $fmtDoc($grandDoc) }}</span>
                    </div>
                </div>
            </div>

            {{-- خانات الختم — بتظهر في الطباعة بس --}}
            <div class="doc-sign three">
                <div><span></span>{{ __('ops.stamp_accounting') }}</div>
                <div><span></span>{{ __('ops.stamp_warehouse') }}</div>
                <div><span></span>{{ __('ops.stamp_branch') }}</div>
            </div>
        @endif
    </div>

    @if ($isLastDoc)
        {{-- ═══ الفوتر: العنوان والتليفون والإيميل بس (قرار المالك ٩/٨) ═══ --}}
        <footer class="doc-foot po-foot">
            <div class="ft-inline">
                @if ($co['address'])<span>{{ $co['address'] }}</span>@endif
                @if ($co['phone'])<span dir="ltr">{{ $co['phone'] }}</span>@endif
                @if ($co['email'])<span dir="ltr">{{ $co['email'] }}</span>@endif
            </div>
        </footer>
    @endif
</div>
@php $rowStartDoc += $pageItemsDoc->count(); @endphp
@endforeach
