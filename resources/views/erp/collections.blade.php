@extends('layouts.system')

{{--
    تحصيلات الميدان (2026-08-09) — كل قيود collection بطرقها
    وصور إثباتها. المحاسب بيطابق الشيك على صورته والتحويل على مرجعه.
--}}

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@php
    // ⭐ ٩/٩ — نفس الفيو لشاشتين: «تحصيلات الميدان» (كل المصادر بفلتر)
    // و«التحصيلات المباشرة» (مقفولة على المباشر + عمود الضرايب المخصومة)
    $direct = ($mode ?? 'all') === 'direct';
    $titleKey = $direct ? 'nav.collections_direct' : 'nav.collections';
@endphp
@section('title', __($titleKey))

@section('actions')
    {{-- التصدير من نفس الفلتر — الصفحة بتتشال، الملف مرآة الشاشة --}}
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    <button class="btn" type="button" onclick="window.print()" title="{{ __('ops.pdf_hint') }}">📄 {{ __('ops.save_pdf') }}</button>
@endsection

@section('content')

{{-- ═══ إجماليات حسب الطريقة — للمطابقة السريعة ═══ --}}
{{-- (٢٢/٩) كل كارت فلتر على طريقته (دوسة تانية بتشيله)، و«الإجمالي» بيرجّع كل الطرق --}}
@php $kq = fn (?string $m) => request()->fullUrlWithQuery(['method' => $m, 'page' => null, 'export' => null]); @endphp
<div class="kpis">
    <a @class(['kpi', 'on' => $method === '']) style="grid-column:span 2" href="{{ $kq(null) }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">{{ __('common.total') }}</div>
        <div class="val pos">{{ $fmt($totals->sum('total')) }}</div>
        <div class="sub2">{{ number_format($totals->sum('cnt')) }} {{ __('ops.entries') }}</div>
        {{-- (٢٢/٩) الإجمالي مفرود مرتين: بوسيلة التحصيل وبالمصدر — الاتنين لازم يقفلوا على نفس الرقم --}}
        <div class="sub2">@include('erp._eq', ['total' => $totals->sum('total'), 'parts' => collect(\App\Models\Transaction::METHODS)->map(fn ($m) => [__('client.pay_method_'.$m), $totals[$m]->total ?? 0])->all()])</div>
        @unless ($direct)
            <div class="sub2">@include('erp._eq', ['total' => $totals->sum('total'), 'parts' => collect(['field', 'rep', 'direct'])->map(fn ($k) => [__('ops.source_'.$k), $bySource[$k] ?? 0])->push([__('uib.other'), $bySource['other'] ?? 0])->all()])</div>
        @endunless
    </a>
    @foreach (\App\Models\Transaction::METHODS as $m)
        <a @class(['kpi', 'on' => $method === $m]) href="{{ $kq($method === $m ? null : $m) }}" title="{{ __('ui.click_to_filter') }}">
            <div class="lbl">{{ __('client.pay_method_'.$m) }}</div>
            <div class="val">{{ $fmt($totals[$m]->total ?? 0) }}</div>
            <div class="sub2">{{ number_format($totals[$m]->cnt ?? 0) }} {{ __('ops.entries') }}</div>
        </a>
    @endforeach
    @if ($reconcile ?? false)
        {{-- (٢٢/٩) مطابقة الداشبورد: الجدول مافيهوش قيود كاش الفواتير الأوتوماتيك — الكارتين دول بيقفلوا الفرق --}}
        <a class="kpi" href="{{ route('erp.reports.show', array_filter(['key' => 'collections', 'source' => 'invoice', 'from' => $from ?: '2000-01-01', 'to' => $to ?: today()->toDateString()])) }}">
            <div class="lbl">{{ __('uib.invoice_cash') }}</div>
            <div class="val">{{ $fmt($invoiceCash) }}</div>
            <div class="sub2">{{ __('uib.invoice_cash_sub') }}</div>
        </a>
        <a class="kpi" style="grid-column:span 2" href="{{ route('erp.reports.show', array_filter(['key' => 'collections', 'from' => $from ?: '2000-01-01', 'to' => $to ?: today()->toDateString()])) }}">
            <div class="lbl">{{ __('uib.ledger_total') }}</div>
            <div class="val pos">{{ $fmt($totals->sum('total') + $invoiceCash) }}</div>
            <div class="sub2">@include('erp._eq', ['total' => $totals->sum('total') + $invoiceCash, 'parts' => [[__('uib.recorded_total'), $totals->sum('total')], [__('uib.invoice_cash'), $invoiceCash]], 'zeros' => true])</div>
            <div class="sub2">{{ __('uib.ledger_total_sub') }}</div>
        </a>
    @endif
    @if ($direct)
        {{-- الضرايب المخصومة تحت الحساب في نفس الفترة — قيود taxded المباشرة --}}
        <div class="kpi" data-explain onclick="openDlg('taxExplain')" title="{{ __('ui.click_to_explain') }}">
            <div class="lbl">{{ __('ops.tax_withheld_col') }}</div>
            <div class="val">{{ $fmt($taxTotal) }}</div>
            <div class="sub2">{{ __('ops.tax_withheld_kpi_sub') }}</div>
        </div>
    @endif
</div>

@if ($direct)
    <dialog id="taxExplain" style="max-width:560px">
        <h3>{{ __('ops.tax_withheld_col') }} <span class="side">{{ __('ui.explain') }}</span></h3>
        <div class="tablewrap">
            <table data-noxl>
                <thead><tr>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th>{{ __('ops.entries') }}</th>
                    <th>{{ __('common.total') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($taxBreak as $tb)
                    <tr>
                        <td style="text-align:start"><a href="{{ route('erp.clients.show', $tb->client_id) }}">{{ $tb->client?->fullName() ?? '—' }}</a></td>
                        <td class="num">{{ number_format($tb->cnt) }}</td>
                        <td class="num">{{ $fmt($tb->total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:18px">—</td></tr>
                @endforelse
                </tbody>
                <tfoot><tr><td style="text-align:start">{{ __('common.total') }}</td>
                    <td class="num">{{ number_format($taxBreak->sum('cnt')) }}</td>
                    <td class="num">{{ $fmt($taxTotal) }}</td></tr></tfoot>
            </table>
        </div>
        <div style="margin-top:12px;text-align:end">
            <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('common.close') }}</button>
        </div>
    </dialog>
@endif

<div class="card">
    <h3>{{ $direct ? '🏦' : '🧾' }} {{ __($titleKey) }}
        <span class="side">{{ __($direct ? 'ops.collections_direct_sub' : 'ops.collections_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        @unless ($direct)
            <label class="fl"><span>{{ __('ops.source') }}</span>
                <select name="source" onchange="this.form.submit()">
                    <option value="">{{ __('ui.all_of', ['x' => __('uib.sources')]) }}</option>
                    @foreach (\App\Http\Controllers\CollectionController::SOURCES as $src)
                        <option value="{{ $src }}" @selected($source === $src)>{{ __('ops.source_'.$src) }}</option>
                    @endforeach
                </select></label>
        @endunless
        <label class="fl"><span>{{ __('ui.l_method') }}</span>
            <select name="method" onchange="this.form.submit()">
                <option value="">{{ __('ui.all_of', ['x' => __('uib.methods')]) }}</option>
                @foreach (\App\Models\Transaction::METHODS as $m)
                    <option value="{{ $m }}" @selected($method === $m)>{{ __('client.pay_method_'.$m) }}</option>
                @endforeach
            </select></label>
        @unless ($direct)
            <label class="fl"><span>{{ __('ui.l_rep') }}</span>
                <select name="rep" onchange="this.form.submit()">
                    <option value="">{{ __('ops.all_reps') }}</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected($repId === $r->id)>{{ $r->name }}</option>
                    @endforeach
                </select></label>
        @endunless
        <label class="fl"><span>{{ __('ops.reference') }}</span>
            <input type="search" name="ref" value="{{ $ref }}" dir="ltr" placeholder="{{ __('uib.ref_ph') }}"></label>
        @include('partials._range', ['from' => $from, 'to' => $to, 'auto' => true])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ url()->current() }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th style="text-align:start">{{ __('client.client') }}</th>
                <th>{{ __('common.date') }}</th>
                <th>{{ __('ops.collected_by') }}</th>
                <th>{{ __('ops.method') }}</th>
                {{-- ⚠️ data-nosum — رقم التحويل مرجع مش مبلغ، مجموعه غلط (١١/٨) --}}
                <th data-nosum>{{ __('ops.reference') }}</th>
                <th>{{ __('settle.proof') }}</th>
                @if ($direct)<th>{{ __('ops.tax_withheld_col') }}</th>@endif
                <th>{{ __('common.total') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $t)
                @php
                    $visit = $t->source_type === \App\Models\Visit::class ? $repByVisit->get($t->source_id) : null;
                    // المستند اليدوي بينسب التحصيل للمندوب بـ source_type = User (انظر ٦/٩)
                    $manualRep = $t->source_type === \App\Models\User::class ? $repByUser->get($t->source_id) : null;
                @endphp
                <tr>
                    <td style="text-align:start">
                        <a href="{{ route('erp.clients.show', $t->client_id) }}"><b>{{ $t->client?->fullName() ?? '—' }}</b></a>
                        @if ($t->memo)
                            <div style="font-size:10.5px;color:var(--muted)">{{ $t->memo }}</div>
                        @endif
                    </td>
                    <td class="num" style="font-size:11px">{{ $t->created_at->format('m-d h:i A') }}</td>
                    <td>
                        @if ($visit?->user)
                            @php $vDay = ($visit->checked_in_at ?? $t->created_at)->toDateString(); @endphp
                            <a href="{{ route('ops.rep', $visit->user->id) }}">{{ $visit->user->name }}</a>
                            {{-- المصدر زيارة ← زيارات المندوب ده في اليوم ده --}}
                            <div style="font-size:10px;color:var(--muted)">{{ $visit->user->code }} ·
                                <a href="{{ route('ops.visits', ['from' => $vDay, 'to' => $vDay, 'user' => $visit->user->id]) }}">{{ __('uib.the_visit') }}</a></div>
                        @elseif ($manualRep)
                            <a href="{{ route('ops.rep', $manualRep->id) }}">{{ $manualRep->name }}</a>
                            <div style="font-size:10px;color:var(--muted)">
                                {{ $manualRep->code }} · {{ __('ops.office_entry') }}
                            </div>
                        @else
                            <span class="badge b-gray">{{ __('ops.office_entry') }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $t->method === 'cash' ? 'b-green' : 'b-blue' }}">{{ $t->methodLabel() ?? '—' }}</span>
                        @if ($t->method === 'cheque' && $t->cheque_due)
                            <div style="font-size:10px;color:var(--muted)">
                                {{ $t->cheque_bank }} · {{ __('client.cheque_due_short') }} {{ $t->cheque_due->format('Y-m-d') }}
                            </div>
                        @endif
                    </td>
                    <td class="num" style="font-size:11px">
                        @if ($t->reference)
                            <a href="{{ request()->fullUrlWithQuery(['ref' => $t->reference, 'page' => null]) }}">{{ $t->reference }}</a>
                        @else — @endif
                    </td>
                    <td>
                        @if ($t->proofUrl())
                            <a class="btn sm" href="{{ $t->proofUrl() }}" target="_blank">📷 {{ __('common.view') }}</a>
                        @else
                            <span style="color:var(--muted)">—</span>
                        @endif
                    </td>
                    @if ($direct)
                        @php $wt = (float) ($taxByKey->get(\App\Http\Controllers\CollectionController::taxKey($t)) ?? 0); @endphp
                        <td class="num">{{ $wt > 0 ? $fmt($wt) : '—' }}</td>
                    @endif
                    <td class="num pos"><b>{{ $fmt($t->credit) }}</b></td>
                </tr>
            @empty
                <tr><td colspan="{{ $direct ? 8 : 7 }}" style="text-align:center;color:var(--muted);padding:26px">{{ __('ops.no_collections') }}</td></tr>
            @endforelse
            </tbody>
            {{-- الجدول مقسم صفحات فمالوش جمع أوتوماتيك — الإجمالي من السيرفر على الفلتر كله = إجمالي ملف الإكسيل --}}
            @if ($rows->total() > 0)
                <tfoot><tr>
                    <td style="text-align:start" colspan="{{ $direct ? 7 : 6 }}">{{ __('common.total') }} · {{ __('ui.rows_n', ['n' => number_format($rows->total())]) }}</td>
                    <td class="num">{{ $fmt($grand) }}</td>
                </tr></tfoot>
            @endif
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

@endsection
