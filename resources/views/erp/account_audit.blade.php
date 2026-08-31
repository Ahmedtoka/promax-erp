@extends('layouts.system')

{{--
    مراجعة الحسابات — السلاسل / العملاء الفرادى  ·  ٢٨ أغسطس ٢٠٢٦

    ⚠️ **فورم واحد multipart** بنفس نمط شاشات الإعداد: كل خانة اسمها
    `rows[id][field]`، و«حفظ الكل» بيبعت الصفوف كلها، وزرار الصف
    بيبعت نفس الفورم مع `only=id` فالسيرفر يحفظ الصف ده بس.

    ⚠️ **«رصيدهم» مش رصيد السيستم.** العمود ده هو الرقم اللي العميل
    قايله، وجنبه رصيدنا المحسوب من القيود — والفرق بينهم ملوّن،
    لأن ده بالظبط اللي الشاشة اتعملت عشانه.
--}}

@php
    $isChains = $mode === 'chains';
    $fmt = fn ($n) => number_format((float) $n, 2);
    $saveUrl = route('erp.audit.save', $isChains ? 'chains' : 'clients');
@endphp

@section('title', $isChains ? __('audit.chains_title') : __('audit.clients_title'))

@section('actions')
    <a class="btn {{ $isChains ? 'gold' : '' }}" href="{{ route('erp.audit.chains') }}">🔗 {{ __('audit.chains_title') }}</a>
    <a class="btn {{ $isChains ? '' : 'gold' }}" href="{{ route('erp.audit.clients') }}">👤 {{ __('audit.clients_title') }}</a>
    <a class="btn" href="{{ route('erp.setup.chains') }}">⚙️ {{ __('client.setup_chains') }}</a>
    <a class="btn" href="{{ route('erp.setup.clients') }}">⚙️ {{ __('client.setup_clients') }}</a>
@endsection

@section('content')

{{-- ═══ السامري — من الملف كله، مش من نتيجة الفلتر ═══ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ $isChains ? __('audit.k_chains') : __('audit.k_clients') }}</div>
        <div class="val mid">{{ number_format($summary['total']) }}</div>
        <div class="sub2">{{ __('audit.k_total_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">✅ {{ __('audit.k_has_account') }}</div>
        <div class="val pos">{{ number_format($summary['has_account']) }}</div>
        <div class="sub2">❌ {{ __('audit.k_no_account') }}: {{ number_format($summary['no_account']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">📄 {{ __('audit.k_has_statement') }}</div>
        <div class="val pos">{{ number_format($summary['has_statement']) }}</div>
        <div class="sub2">🚫 {{ __('audit.k_no_statement') }}: {{ number_format($summary['no_statement']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">⏳ {{ __('audit.k_pending') }}</div>
        <div class="val {{ $summary['pending'] > 0 ? 'mid' : '' }}">{{ number_format($summary['pending']) }}</div>
        <div class="sub2">{{ __('audit.k_pending_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">⚖️ {{ __('audit.k_gap') }}</div>
        <div class="val {{ abs($summary['gap']) >= 0.01 ? 'neg' : 'pos' }}">{{ $fmt($summary['gap']) }}</div>
        <div class="sub2">{{ __('audit.k_gap_hint', ['ours' => $fmt($summary['ours'])]) }}</div>
    </div>
</div>

{{-- ═══ الفلاتر ═══ --}}
<form class="searchbar" method="GET" style="margin-bottom:12px">
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
        'gap' => '⚖️ '.__('audit.f_gap'),
        'done' => '✅ '.__('audit.f_done'),
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
                <th style="min-width:200px">{{ $isChains ? __('audit.c_chain') : __('audit.c_client') }}</th>
                <th style="width:150px">{{ __('audit.c_has_account') }}</th>
                <th class="num" style="width:120px">{{ __('audit.c_their') }}</th>
                <th class="num" style="width:120px" data-nosum>{{ __('audit.c_ours') }}</th>
                <th class="num" style="width:120px" data-nosum>{{ __('audit.c_gap') }}</th>
                <th style="width:150px">{{ __('audit.c_has_statement') }}</th>
                <th style="width:190px">{{ __('audit.c_file') }}</th>
                <th style="min-width:150px">{{ __('audit.c_note') }}</th>
                <th style="width:60px"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                @php
                    $a = $r['audit'];
                    $hasAcc = $a?->has_account;
                    $hasStm = $a?->has_statement;
                    $their = $a?->their_balance;
                    $gap = $their === null ? null : (float) $their - $r['ours'];
                @endphp
                <tr @class(['aud-row', 'aud-pending' => $hasAcc === null])>
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

                    {{-- حساب العميل موجود؟ --}}
                    <td>
                        <div class="aud-seg">
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_account]" value="1"
                                          @checked($hasAcc === true)><span>{{ __('audit.yes') }}</span></label>
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_account]" value="0"
                                          @checked($hasAcc === false)><span>{{ __('audit.no') }}</span></label>
                        </div>
                    </td>

                    {{-- رصيدهم --}}
                    <td class="num">
                        <input type="number" step="0.01" name="rows[{{ $r['id'] }}][their_balance]"
                               value="{{ $their !== null ? (float) $their : '' }}"
                               style="width:110px;text-align:center" placeholder="—">
                    </td>

                    {{-- رصيدنا — قراءة بس، من القيود --}}
                    <td class="num"><b>{{ $fmt($r['ours']) }}</b></td>

                    {{-- الفرق --}}
                    <td class="num">
                        @if ($gap === null)
                            <span style="color:var(--muted)">—</span>
                        @else
                            <b style="color:{{ abs($gap) < 0.01 ? '#16A34A' : '#DC2626' }}">{{ $fmt($gap) }}</b>
                        @endif
                    </td>

                    {{-- كشف الحساب موجود؟ --}}
                    <td>
                        <div class="aud-seg">
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_statement]" value="1"
                                          @checked($hasStm === true)><span>{{ __('audit.yes') }}</span></label>
                            <label><input type="radio" name="rows[{{ $r['id'] }}][has_statement]" value="0"
                                          @checked($hasStm === false)><span>{{ __('audit.no') }}</span></label>
                        </div>
                    </td>

                    {{-- الملف: رفع أو عرض/مسح --}}
                    <td>
                        @if ($a?->statement_path)
                            <a href="{{ $a->statementUrl() }}" target="_blank" rel="noopener"
                               style="font-size:11px;font-weight:700">📎 {{ Str::limit($a->statement_name, 22) }}</a>
                            <button class="btn sm" type="submit" formmethod="POST" formnovalidate
                                    formaction="{{ route('erp.audit.statement.delete', $a) }}"
                                    onclick="return confirm(@js(__('audit.remove_confirm')))"
                                    style="margin-inline-start:4px">🗑</button>
                        @else
                            <input type="file" name="files[{{ $r['id'] }}]"
                                   accept=".xlsx,.xls,.csv,.pdf,image/*" style="width:100%;font-size:11px">
                        @endif
                    </td>

                    <td>
                        <input type="text" name="rows[{{ $r['id'] }}][note]" maxlength="300"
                               value="{{ $a?->note }}" style="width:100%" placeholder="—">
                    </td>

                    {{-- زرار الصف — نفس الفورم مع only=id --}}
                    <td><button class="btn sm" type="submit" name="only" value="{{ $r['id'] }}">💾</button></td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('audit.empty') }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="display:flex;gap:8px;align-items:center;margin-top:12px">
        <button class="btn gold" type="submit">💾 {{ __('audit.save_all') }}</button>
        <span style="font-size:11.5px;color:var(--muted)">{{ __('audit.save_hint') }}</span>
    </div>
</form>

<style>
  /* أزرار «أه/لا» — راديو متلبّس شكل سِجمنت */
  .aud-seg { display:inline-flex; border:1px solid var(--border); border-radius:9px; overflow:hidden }
  .aud-seg label { display:inline-flex; cursor:pointer; margin:0 }
  .aud-seg input { position:absolute; opacity:0; pointer-events:none }
  .aud-seg span {
    display:inline-block; padding:5px 14px; font-size:12px; font-weight:800;
    color:var(--muted); background:#fff; transition:.12s;
  }
  .aud-seg label + label span { border-inline-start:1px solid var(--border) }
  .aud-seg input:checked + span { color:#fff }
  .aud-seg label:first-child input:checked + span { background:#16A34A }
  .aud-seg label:last-child  input:checked + span { background:#DC2626 }
  /* الصف اللي لسه ماتراجعش — شريط أصفر خفيف على الجنب */
  .aud-pending td:first-child { box-shadow: inset 3px 0 0 #F59E0B }
</style>

@endsection
