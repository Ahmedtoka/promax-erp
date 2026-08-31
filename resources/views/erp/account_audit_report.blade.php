@extends('layouts.system')

{{--
    تقرير مراجعة الحسابات — مربعات بس  ·  ٢٨ أغسطس ٢٠٢٦

    «تقرير فيه مربعات كلها سامريهات بالإجابات على الأسئلة دي».
    كل مربع = إجابة سؤال واحد، والسؤال نفسه مكتوب تحت الرقم.

    ⚠️ **مفيش فلوس هنا خالص** (قرار المالك ٢٨/٨): التقرير بيعدّ
    حالات — كام حسابه موجود، كام معاه كشف، كام معاه إذن، كام
    اتعملّه فاتورة. الأرصدة شغل كشف الحساب مش شغل المراجعة.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);

    // نسبة الإنجاز — من اللي اتراجعوا مش من الكل
    $pct = function (array $s, string $key) {
        return $s['total'] > 0 ? round($s[$key] / $s['total'] * 100) : 0;
    };

    // صفوف المربعات — نفس السؤال بيتسأل للسلاسل وللعملاء
    $groupsOf = fn (array $s) => [
        [
            'title' => __('audit.g_coverage'),
            'boxes' => [
                ['✅ '.__('audit.k_has_account'), $s['has_account'], __('audit.k_has_account_hint'), 'pos'],
                ['❌ '.__('audit.k_no_account'), $s['no_account'], __('audit.k_no_account_hint'), ''],
                ['⏳ '.__('audit.k_pending'), $s['pending'], __('audit.k_pending_hint'), $s['pending'] > 0 ? 'neg' : ''],
            ],
        ],
        [
            'title' => __('audit.g_papers'),
            'boxes' => [
                ['📄 '.__('audit.k_has_statement'), $s['has_statement'], __('audit.k_has_statement_hint'), 'pos'],
                ['🚫 '.__('audit.k_no_statement'), $s['no_statement'], __('audit.k_no_statement_hint'), 'neg'],
                ['🧾 '.__('audit.k_has_receipt'), $s['has_receipt'], __('audit.k_has_receipt_hint'), 'pos'],
                ['📭 '.__('audit.k_no_receipt'), $s['no_receipt'], __('audit.k_no_receipt_hint'), 'neg'],
                ['📎 '.__('audit.k_files'), $s['files'], __('audit.k_files_hint'), ''],
            ],
        ],
        [
            'title' => __('audit.g_tax'),
            'boxes' => [
                ['💠 '.__('audit.k_billed'), $s['billed'], __('audit.k_billed_hint'), 'pos'],
                ['⭕ '.__('audit.k_unbilled'), $s['unbilled'], __('audit.k_unbilled_hint'), 'neg'],
                ['❔ '.__('audit.k_billing_pending'), $s['billing_pending'], __('audit.k_billing_pending_hint'), ''],
                ['🎯 '.__('audit.k_ready'), $s['ready_to_bill'], __('audit.k_ready_hint'), 'mid'],
            ],
        ],
        [
            'title' => __('audit.g_done'),
            'boxes' => [
                ['🏆 '.__('audit.k_full'), $s['full'], __('audit.k_full_hint'), 'pos'],
                ['🤝 '.__('audit.k_confirmed'), $s['confirmed'], __('audit.k_confirmed_hint'), 'pos'],
            ],
        ],
    ];

    $sections = [
        ['key' => 'chains', 'label' => __('audit.chains_title'), 'icon' => '🔗',
            'sum' => $chains, 'link' => route('erp.audit.chains')],
        ['key' => 'clients', 'label' => __('audit.clients_title'), 'icon' => '👤',
            'sum' => $clients, 'link' => route('erp.audit.clients')],
    ];
@endphp

@section('title', __('audit.report_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.audit.chains') }}">🔗 {{ __('audit.chains_title') }}</a>
    <a class="btn" href="{{ route('erp.audit.clients') }}">👤 {{ __('audit.clients_title') }}</a>
@endsection

@section('content')

<div class="alert info" style="margin-bottom:14px">
    <span>ℹ️</span><span>{{ __('audit.report_hint') }}</span>
</div>

@foreach ($sections as $sec)
    @php $s = $sec['sum']; @endphp

    <div class="card" style="margin-bottom:16px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
            <h3 style="margin:0;font-size:16px">{{ $sec['icon'] }} {{ $sec['label'] }}</h3>
            <span class="badge b-blue">{{ __('audit.k_total_short') }}: {{ $fmt($s['total']) }}</span>
            <span class="badge {{ $s['pending'] > 0 ? 'b-orange' : 'b-green' }}">
                {{ __('audit.reviewed_n', ['n' => $fmt($s['reviewed']), 't' => $fmt($s['total'])]) }}
            </span>
            <a class="btn sm" href="{{ $sec['link'] }}" style="margin-inline-start:auto">
                {{ __('audit.open_list') }} ←
            </a>
        </div>

        {{-- شريط الإنجاز: المظبوط تماماً من الإجمالي --}}
        <div style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:4px">
                <span>{{ __('audit.progress_label') }}</span>
                <b>{{ $pct($s, 'full') }}%</b>
            </div>
            <div style="height:9px;background:#E5E7EB;border-radius:99px;overflow:hidden">
                <div style="height:100%;width:{{ $pct($s, 'full') }}%;background:#16A34A"></div>
            </div>
        </div>

        @foreach ($groupsOf($s) as $g)
            <div style="font-size:12.5px;font-weight:800;color:var(--muted);margin:10px 0 6px">
                {{ $g['title'] }}
            </div>
            <div class="kpis">
                @foreach ($g['boxes'] as [$t, $v, $h, $c])
                    <div class="kpi">
                        <div class="lbl">{{ $t }}</div>
                        <div class="val {{ $c }}">{{ $fmt($v) }}</div>
                        <div class="sub2">{{ $h }}</div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
@endforeach

@endsection
