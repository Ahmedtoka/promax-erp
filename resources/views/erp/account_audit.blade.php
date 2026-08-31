@extends('layouts.system')

{{--
    مراجعة الحسابات — السلاسل / العملاء الفرادى  ·  ٢٨ أغسطس ٢٠٢٦

    الصفحة بتمشي **سلسلة سؤال** زي ما المالك وصفها بالحرف:
      حسابه موجود؟ → كام الحساب → معاك كشف الحساب؟ → ارفعه →
      معاك إذون استلام الكشف؟ → اتعملت فاتورة ضريبية؟

    ⚠️ **السلسلة بتقف عند أول «لا»**: «مالوش حساب» بتقفل الأعمدة
    اللي بعدها (رمادي)، عشان محدش يسجّل «معاه كشف» لعميل مالوش حساب.

    ⚠️ **«رصيده عندنا» ريفرنس بس** (قرار المالك ٢٨/٨): مش بيتجمع ولا
    بيتطرح ولا بيتقارن — موجود عشان اللي بيراجع يبص عليه وهو بيسأل،
    وخلاص. الشاشة دي بتعدّ **حالات** مش بتحسب فلوس.

    ⚠️ **فورم واحد multipart**: كل خانة `rows[id][field]`، «حفظ الكل»
    بيبعت الصفوف كلها، وزرار الصف بيبعت `only=id`.
--}}

@php
    $isChains = $mode === 'chains';
    $fmt0 = fn ($n) => number_format((float) $n);
    $fmt2 = fn ($n) => number_format((float) $n, 2);
    $saveUrl = route('erp.audit.save', $isChains ? 'chains' : 'clients');

    // مربع سامري: رقم فوق + عنوان + **وصف بيقول السؤال اللي بيجاوبه**
    $box = function (string $title, $val, string $hint, string $cls = '') use ($fmt0) {
        return ['t' => $title, 'v' => $fmt0($val), 'h' => $hint, 'c' => $cls];
    };

    $boxes = [
        $box($isChains ? __('audit.k_chains') : __('audit.k_clients'), $summary['total'], __('audit.k_total_hint'), 'mid'),
        $box('⏳ '.__('audit.k_pending'), $summary['pending'], __('audit.k_pending_hint'), $summary['pending'] > 0 ? 'neg' : ''),
        $box('✅ '.__('audit.k_has_account'), $summary['has_account'], __('audit.k_has_account_hint'), 'pos'),
        $box('❌ '.__('audit.k_no_account'), $summary['no_account'], __('audit.k_no_account_hint'), ''),
        $box('📄 '.__('audit.k_has_statement'), $summary['has_statement'], __('audit.k_has_statement_hint'), 'pos'),
        $box('🚫 '.__('audit.k_no_statement'), $summary['no_statement'], __('audit.k_no_statement_hint'), 'neg'),
        $box('🧾 '.__('audit.k_has_receipt'), $summary['has_receipt'], __('audit.k_has_receipt_hint'), 'pos'),
        $box('📭 '.__('audit.k_no_receipt'), $summary['no_receipt'], __('audit.k_no_receipt_hint'), 'neg'),
        $box('🏆 '.__('audit.k_full'), $summary['full'], __('audit.k_full_hint'), 'pos'),
        $box('💠 '.__('audit.k_billed'), $summary['billed'], __('audit.k_billed_hint'), 'pos'),
        $box('⭕ '.__('audit.k_unbilled'), $summary['unbilled'], __('audit.k_unbilled_hint'), 'neg'),
        $box('🎯 '.__('audit.k_ready'), $summary['ready_to_bill'], __('audit.k_ready_hint'), 'mid'),
    ];
@endphp

@section('title', $isChains ? __('audit.chains_title') : __('audit.clients_title'))

@section('actions')
    <a class="btn {{ $isChains ? 'gold' : '' }}" href="{{ route('erp.audit.chains') }}">🔗 {{ __('audit.chains_title') }}</a>
    <a class="btn {{ $isChains ? '' : 'gold' }}" href="{{ route('erp.audit.clients') }}">👤 {{ __('audit.clients_title') }}</a>
    <a class="btn" href="{{ route('erp.audit.report') }}">📊 {{ __('audit.report_title') }}</a>
@endsection

@section('content')

{{-- ═══ السامري — من الملف كله مش من نتيجة الفلتر، وتحت كل رقم
     السؤال اللي بيجاوب عليه ═══ --}}
<div class="kpis">
    @foreach ($boxes as $b)
        <div class="kpi">
            <div class="lbl">{{ $b['t'] }}</div>
            <div class="val {{ $b['c'] }}">{{ $b['v'] }}</div>
            <div class="sub2">{{ $b['h'] }}</div>
        </div>
    @endforeach
</div>

{{-- ═══ الفلاتر ═══ --}}
<form class="searchbar" method="GET" style="margin-bottom:10px">
    <input type="text" name="q" value="{{ $q }}" style="flex:1;min-width:200px"
           placeholder="🔍 {{ __('audit.search_ph') }}">
    <input type="hidden" name="show" value="{{ $show }}">
    <button class="btn gold" type="submit">{{ __('common.search') }}</button>
</form>

<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
    @foreach ([
        'all' => __('audit.f_all'),
        'pending' => '⏳ '.__('audit.f_pending'),
        'no_account' => '❌ '.__('audit.f_no_account'),
        'no_statement' => '🚫 '.__('audit.f_no_statement'),
        'no_receipt' => '📭 '.__('audit.f_no_receipt'),
        'full' => '🏆 '.__('audit.f_full'),
        'ready_to_bill' => '🎯 '.__('audit.f_ready'),
        'unbilled' => '⭕ '.__('audit.f_unbilled'),
    ] as $key => $label)
        <a class="btn sm {{ $show === $key ? 'gold' : '' }}"
           href="{{ route($isChains ? 'erp.audit.chains' : 'erp.audit.clients', array_filter(['show' => $key, 'q' => $q])) }}">
            {{ $label }}
        </a>
    @endforeach
</div>

<form method="POST" action="{{ $saveUrl }}" enctype="multipart/form-data" id="auditForm">
    @csrf

    <div class="card" style="padding:0;overflow:auto">
        <table class="tbl">
            <thead>
            <tr>
                <th style="width:62px" data-nosum title="{{ __('audit.sort_hint') }}">↕ {{ __('audit.c_sort') }}</th>
                <th style="min-width:190px">{{ $isChains ? __('audit.c_chain') : __('audit.c_client') }}</th>
                <th style="width:132px">1️⃣ {{ __('audit.c_has_account') }}</th>
                <th class="num" style="width:118px" data-nosum>2️⃣ {{ __('audit.c_their') }}</th>
                <th style="width:132px">3️⃣ {{ __('audit.c_has_statement') }}</th>
                <th style="width:180px">4️⃣ {{ __('audit.c_file') }}</th>
                <th style="width:132px">5️⃣ {{ __('audit.c_has_receipt') }}</th>
                <th style="width:140px">6️⃣ {{ __('audit.c_tax') }}</th>
                <th class="num" style="width:110px" data-nosum>{{ __('audit.c_ours') }}</th>
                <th style="width:96px" data-nosum>{{ __('audit.c_confirm') }}</th>
                <th style="width:52px" data-nosum></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $i => $r)
                @php
                    $a = $r['audit'];
                    $hasAcc = $a?->has_account;
                    $hasStm = $a?->has_statement;
                    $hasRcp = $a?->has_receipt;
                    $tax = $a?->tax_invoice;
                    $their = $a?->their_balance;
                    $state = $a?->state() ?? 'pending';

                    // السلسلة بتقف عند أول «لا» — الأعمدة اللي بعدها تتقفل
                    $lockAfterAcc = $hasAcc === false;
                    $lockAfterStm = $lockAfterAcc || $hasStm !== true;
                @endphp
                <tr @class(['aud-row', 'aud-pending' => $state === 'pending', 'aud-full' => $state === 'full'])>
                    {{-- ترتيب يدوي: اكتب ١ ٢ ٣ ودوس حفظ الكل تحت.
                         الفاضي = سيبه في مكانه الافتراضي --}}
                    <td class="num">
                        <input type="number" min="1" max="99999" step="1" class="aud-sort"
                               name="rows[{{ $r['id'] }}][sort]" value="{{ $a?->sort }}"
                               placeholder="{{ $i + 1 }}"
                               style="width:52px;text-align:center;font-weight:800">
                    </td>

                    <td>
                        <b>{{ $r['title'] }}</b>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $r['sub'] }}</span>
                        @if ($a?->reviewed_at)
                            <br><span style="font-size:9.5px;color:var(--muted)">
                                ✓ {{ $a->reviewed_at->format('Y-m-d') }}
                                @if ($a->reviewer) · {{ $a->reviewer->displayName() }} @endif
                            </span>
                        @endif
                    </td>

                    {{-- ١) حسابه موجود؟ --}}
                    <td>
                        <div class="aud-seg">
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_account]" value="1"
                                          @checked($hasAcc === true)><span>{{ __('audit.yes') }}</span></label>
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_account]" value="0"
                                          @checked($hasAcc === false)><span>{{ __('audit.no') }}</span></label>
                        </div>
                    </td>

                    {{-- ٢) كام الحساب --}}
                    <td class="num">
                        <input type="number" step="0.01" name="rows[{{ $r['id'] }}][their_balance]"
                               value="{{ $their !== null ? (float) $their : '' }}"
                               @disabled($lockAfterAcc)
                               style="width:108px;text-align:center" placeholder="—">
                    </td>

                    {{-- ٣) معاك كشف الحساب؟ --}}
                    <td>
                        <div @class(['aud-seg', 'aud-off' => $lockAfterAcc])>
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_statement]" value="1"
                                          @checked($hasStm === true) @disabled($lockAfterAcc)><span>{{ __('audit.yes') }}</span></label>
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_statement]" value="0"
                                          @checked($hasStm === false) @disabled($lockAfterAcc)><span>{{ __('audit.no') }}</span></label>
                        </div>
                    </td>

                    {{-- ٣ب) الملف --}}
                    <td>
                        @if ($a?->statement_path)
                            <a href="{{ $a->statementUrl() }}" target="_blank" rel="noopener"
                               style="font-size:11px;font-weight:700">📎 {{ Str::limit($a->statement_name, 20) }}</a>
                            <button class="btn sm" type="submit" formnovalidate
                                    formaction="{{ route('erp.audit.statement.delete', $a) }}"
                                    onclick="return confirm(@js(__('audit.remove_confirm')))"
                                    style="margin-inline-start:4px">🗑</button>
                        @else
                            <input type="file" name="files[{{ $r['id'] }}]" @disabled($lockAfterAcc)
                                   accept=".xlsx,.xls,.csv,.pdf,image/*" style="width:100%;font-size:11px">
                        @endif
                    </td>

                    {{-- ٥) معاك إذن استلام الكشف؟ --}}
                    <td>
                        <div @class(['aud-seg', 'aud-off' => $lockAfterStm])>
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_receipt]" value="1"
                                          @checked($hasRcp === true) @disabled($lockAfterStm)><span>{{ __('audit.yes') }}</span></label>
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_receipt]" value="0"
                                          @checked($hasRcp === false) @disabled($lockAfterStm)><span>{{ __('audit.no') }}</span></label>
                        </div>
                    </td>

                    {{-- ٦) فاتورة ضريبية — محور مستقل، مابيتقفلش --}}
                    <td>
                        <div class="aud-seg aud-bill">
                            <label><input type="radio" name="rows[{{ $r['id'] }}][tax_invoice]" value="1"
                                          @checked($tax === true)><span>{{ __('audit.billed') }}</span></label>
                            <label><input type="radio" name="rows[{{ $r['id'] }}][tax_invoice]" value="0"
                                          @checked($tax === false)><span>{{ __('audit.unbilled') }}</span></label>
                        </div>
                    </td>

                    {{-- رصيدنا — ريفرنس بس --}}
                    <td class="num" style="color:var(--muted)">{{ $fmt2($r['ours']) }}</td>

                    {{-- تأكيد مدير القناة من عند العميل --}}
                    <td>
                        <button class="btn sm {{ $a?->confirmed_at ? 'gold' : '' }}" type="submit" formnovalidate
                                @disabled($a === null)
                                formaction="{{ $a ? route('erp.audit.confirm', $a) : '#' }}"
                                title="{{ $a?->confirmed_at ? $a->confirmed_at->format('Y-m-d') : __('audit.confirm_hint') }}">
                            {{ $a?->confirmed_at ? '✅' : '☐' }}
                        </button>
                    </td>

                    <td><button class="btn sm" type="submit" name="only" value="{{ $r['id'] }}">💾</button></td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('audit.empty') }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap">
        <button class="btn gold" type="submit">💾 {{ __('audit.save_all') }}</button>
        <span style="font-size:11.5px;color:var(--muted)">{{ __('audit.save_hint') }}</span>
    </div>
    <div class="alert info" style="margin-top:10px">
        <span>↕</span><span>{{ __('audit.sort_hint') }}</span>
    </div>
</form>

<style>
  /* أزرار «أه/لا» — راديو متلبّس شكل سِجمنت */
  .aud-seg { display:inline-flex; border:1px solid var(--border); border-radius:9px; overflow:hidden }
  .aud-seg label { display:inline-flex; cursor:pointer; margin:0 }
  .aud-seg input { position:absolute; opacity:0; pointer-events:none }
  .aud-seg span {
    display:inline-block; padding:5px 12px; font-size:11.5px; font-weight:800;
    color:var(--muted); background:#fff; transition:.12s; white-space:nowrap;
  }
  .aud-seg label + label span { border-inline-start:1px solid var(--border) }
  .aud-seg input:checked + span { color:#fff }
  .aud-seg label:first-child input:checked + span { background:#16A34A }
  .aud-seg label:last-child  input:checked + span { background:#DC2626 }
  /* الفاتورة الضريبية محور تاني — لون مختلف عن أه/لا */
  .aud-bill label:first-child input:checked + span { background:#12399B }
  .aud-bill label:last-child  input:checked + span { background:#B86E00 }
  /* السؤال المقفول — السلسلة وقفت عند «لا» قبله */
  .aud-off { opacity:.4; pointer-events:none }
  .aud-pending td:first-child { box-shadow: inset 3px 0 0 #F59E0B }
  .aud-full td:first-child { box-shadow: inset 3px 0 0 #16A34A }
</style>

@endsection
