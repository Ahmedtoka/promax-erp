{{--
    جسم مستند أمر التوريد — مشترك بين الطباعة الفردية (po_print)
    والمجمعة (po_print_batch). بياخد `$po` محمّل بعلاقاته.
    الستايل بتاعه في `ops/_po_doc_style` — لازم يتضمّن مرة في الصفحة.

    ⚠️ **المستند ده بيتسلّم لفرع وبيتمضى عليه.** أي رقم عليه لازم
    يكون متخزّن على الأمر نفسه، مش محسوب من حالة العميل دلوقتي —
    عشان كده الخصم بيتقرا من `list_price`/`discount_pct` على البند.
--}}

@php
    $fmtDoc = fn ($n) => number_format((float) $n, 2);
    $qtyTotalDoc = (int) $po->items->sum('qty');
    $co = \App\Models\Setting::docHeader();

    // إجمالي قبل الخصم وقيمة الخصم — من السطور نفسها
    $grossDoc = 0.0;
    $discDoc = 0.0;
    foreach ($po->items as $itDoc) {
        $grossDoc += $itDoc->listPrice() * (int) $itDoc->qty;
        $discDoc += $itDoc->discountValue();
    }

    // ⚠️ **النسبة المعروضة = نسبة الأصناف الخاضعة، والأساس معاها.**
    // لو الأمر فيه صنف معفى، `tax_total / total` بتطلع 9.4% وتتطبع
    // «ضريبة 9.4%» — رقم مالوش وجود. وطباعة «14%» لوحدها جنب صافي
    // فيه معفى بتخلّي الفرع يحسب 1000 × 14% = 140 ويلاقي 84 مكتوبة،
    // فالمستند بيناقض نفسه على ورقة بتتختم. الحل: النسبة **والأساس
    // اللي اتحسبت عليه** — والمعفى بيبان كسطر مستقل.
    $taxableBaseDoc = (float) $po->items->where('tax', '>', 0)->sum('total');
    $exemptBaseDoc = round((float) $po->total - $taxableBaseDoc, 2);

    $ratesDoc = $po->items->where('tax', '>', 0)->pluck('tax_rate')
        ->map(fn ($r) => round((float) $r * 100, 2))->unique()->values();

    $taxRateDoc = $ratesDoc->count() === 1
        ? $ratesDoc->first()
        : ($taxableBaseDoc > 0 ? round((float) $po->tax_total / $taxableBaseDoc * 100, 2) : 0);

    $rateLabelDoc = rtrim(rtrim(number_format((float) $taxRateDoc, 2), '0'), '.');

    // ⚠️ بيانات البنك بتتطبع بشرطين: مش ديمو، وفيها حاجة فعلاً.
    // مستند بيقول «حوّل على الحساب المدرج فقط» وفيه آيبان وهمي —
    // أو مالوش أي حساب أصلاً — أخطر من مستند من غير بوكس بنك.
    $showBankDoc = ! $co['bank_demo'] && array_filter($co['bank']) !== [];
@endphp

<div class="doc po-doc has-bolt">
    <img class="bolt-mark po-bolt" src="{{ asset('brand/bolt.svg') }}" alt="">

    <header class="doc-head">
        <div class="doc-brand">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="doc-corp">{{ $co['name'] ?: __('ops.corp_name') }}</div>

            {{-- البيانات القانونية تحت اللوجو مباشرة — أول حاجة الفرع
                 بيدوّر عليها لما يقيّد المستند عنده --}}
            <div class="doc-legal">
                @if ($co['tax_id'])
                    <span>{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></span>
                @endif
                @if ($co['cr'])
                    <span>{{ __('doc.cr') }}: <b>{{ $co['cr'] }}</b></span>
                @endif
            </div>
        </div>

        <div class="doc-id">
            <div class="doc-no">{{ $po->number }}</div>
            @if ($po->source)
                <div class="doc-date">{{ __('ops.po_source_no') }}: <b>{{ $po->source }}</b></div>
            @endif
            {{-- ⚠️ `?->` مش زيادة: `timestamps()` nullable في MySQL، وأي
                 صف اتحقن بسكربت إصلاح بيوقّع الطباعة المجمعة كلها --}}
            <div class="doc-date">{{ $po->created_at?->format('Y-m-d — H:i') ?? '—' }}</div>
        </div>
    </header>

    {{-- العنوان الكبير في نص الورقة --}}
    <div class="po-title">{{ __('ops.po_doc') }}</div>

    <div class="doc-body">
        <div class="doc-parties">
            <div>
                <div class="k">{{ __('ops.branch_client') }}</div>
                <div class="v">{{ $po->client?->fullName() ?? '—' }}</div>
                <div class="s">{{ $po->client?->address ?: '—' }}</div>
                @if ($po->client?->tax_id)
                    <div class="s">{{ __('doc.tax_id') }}: {{ $po->client->tax_id }}</div>
                @endif
            </div>
            <div>
                <div class="k">{{ __('ops.rep') }}</div>
                <div class="v">{{ $po->courier?->name ?? '—' }}</div>
                <div class="s">{{ __('ops.by') }}: {{ $po->creator?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="k">{{ __('stock.warehouse') }}</div>
                <div class="v">{{ $po->warehouse?->displayName() ?? '—' }}</div>
                <div class="s">{{ __('ops.due_at') }}:
                    {{ $po->due_at?->format('Y-m-d H:i') ?? ($po->due_date?->format('Y-m-d') ?? '—') }}</div>
            </div>
        </div>

        <div class="tablewrap">
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

                @foreach ($po->items as $i => $it)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td class="num bar">{{ $it->product?->barcode ?? '—' }}</td>
                        <td>
                            <b>{{ $it->product?->displayName() ?? '—' }}</b>
                        </td>
                        {{-- الكمية بالقطع هي الرقم المعتمد، والتفكيك
                             لكراتين وعلب تحتها عشان المخزن يعدّها --}}
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

                <tr class="sum">
                    <td colspan="3"><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ number_format($qtyTotalDoc) }}</b></td>
                    <td colspan="2"></td>
                    <td></td>
                    <td class="num"><b>{{ $fmtDoc($po->total) }}</b></td>
                </tr>
            </table>
        </div>

        {{-- ═══ التجميعة جنب بيانات البنك ═══ --}}
        <div class="po-summary">
            @if ($showBankDoc)
                <div class="po-bank">
                    <div class="bk-h">🏦 {{ __('doc.bank_details') }}</div>
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
                @if ($discDoc > 0)
                    <div class="row"><span>{{ __('doc.gross_before_discount') }}</span><span>{{ $fmtDoc($grossDoc) }}</span></div>
                    <div class="row disc"><span>{{ __('doc.discount_value') }}</span><span>− {{ $fmtDoc($discDoc) }}</span></div>
                @endif

                <div class="row net"><span>{{ __('doc.net_before_tax') }}</span><span>{{ $fmtDoc($po->total) }}</span></div>

                {{-- ⚠️ السطر بيبان **حتى لو الضريبة صفر** طول ما هي
                     مفعّلة في السيستم — الفرع لازم يشوفه عشان يعرف إن
                     الأمر معفى بقصد مش بالنسيان. --}}
                @if ((float) $po->tax_total > 0 || \App\Services\Tax::enabled())
                    @if ($exemptBaseDoc > 0)
                        <div class="row"><span>{{ __('doc.exempt_base') }}</span><span>{{ $fmtDoc($exemptBaseDoc) }}</span></div>
                    @endif
                    <div class="row tax">
                        {{-- الأساس جنب النسبة عشان الفرع يقدر يراجع
                             الضرب بنفسه: الأساس × النسبة = الرقم --}}
                        <span>{{ __('doc.vat_on', ['rate' => $rateLabelDoc, 'base' => $fmtDoc($taxableBaseDoc)]) }}</span>
                        <span>{{ $fmtDoc($po->tax_total) }}</span>
                    </div>
                @endif

                <div class="row grand">
                    <span>{{ __('doc.total_with_tax') }}</span>
                    <span>{{ $fmtDoc($po->payable()) }}</span>
                </div>
            </div>
        </div>

        {{-- خانات الختم — بتظهر في الطباعة بس (doc-sign) --}}
        <div class="doc-sign three">
            <div><span></span>{{ __('ops.stamp_accounting') }}</div>
            <div><span></span>{{ __('ops.stamp_warehouse') }}</div>
            <div><span></span>{{ __('ops.stamp_branch') }}</div>
        </div>
    </div>

    <footer class="doc-foot po-foot">
        <div class="ft-corp">{{ $co['name'] ?: 'PROMAX FOOD INDUSTRIES' }}</div>

        <div class="ft-lines">
            @if ($co['address'])
                <div>📍 {{ $co['address'] }}</div>
            @endif
            <div class="ft-inline">
                @if ($co['phone'])
                    <span>📞 {{ $co['phone'] }}</span>
                @endif
                @if ($co['email'])
                    <span>✉️ {{ $co['email'] }}</span>
                @endif
                @if ($co['tax_id'])
                    <span>{{ __('doc.tax_id') }}: {{ $co['tax_id'] }}</span>
                @endif
                @if ($co['cr'])
                    <span>{{ __('doc.cr') }}: {{ $co['cr'] }}</span>
                @endif
            </div>
        </div>

        <div class="ft-ref">{{ $po->number }}@if ($po->source) · {{ $po->source }}@endif</div>
    </footer>
</div>
