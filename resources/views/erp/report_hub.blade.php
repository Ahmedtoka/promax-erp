@extends('layouts.system')

@section('title', __('rpt.hub_title'))

@section('actions')
    <a class="btn gold" href="{{ route('erp.reports.quotation') }}">📄 {{ __('rpt.quotation') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>📑 {{ __('rpt.hub_title') }} <span class="side">{{ __('rpt.hub_sub') }}</span></h3>

    <div class="rpt-grid">
        @foreach ($reports as $key => $icon)
            <a class="rpt-card" href="{{ route('erp.reports.show', $key) }}">
                <span class="ic">{{ $icon }}</span>
                <b>{{ __('rpt.'.$key) }}</b>
                <span class="s">{{ __('rpt.'.$key.'_sub') }}</span>
            </a>
        @endforeach

        {{-- الكوتيشن — مش تقرير بس مكانه هنا (طلب المالك) --}}
        <a class="rpt-card rpt-gold" href="{{ route('erp.reports.quotation') }}">
            <span class="ic">📄</span>
            <b>{{ __('rpt.quotation') }}</b>
            <span class="s">{{ __('rpt.quotation_sub') }}</span>
        </a>
    </div>
</div>

{{-- ═══ التقارير المالية القديمة — زي ما هي، بلينكات من هنا ═══ --}}
<div class="card">
    <h3>💼 {{ __('rpt.legacy_title') }} <span class="side">{{ __('rpt.legacy_sub') }}</span></h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @foreach ([
            'aging' => '⏳ '.__('report.aging'),
            'returns' => '↩️ '.__('report.returns'),
            'rebates' => '🏷️ '.__('report.discounts_settlements'),
            'ck' => '🏪 '.__('report.network_of', ['name' => 'Circle K']),
            'risk' => '⚠️ '.__('report.risk'),
            'credit' => '🔵 '.__('report.credit_balances'),
        ] as $k => $lbl)
            <a class="btn" href="{{ route('erp.reports', ['tab' => $k]) }}">{{ $lbl }}</a>
        @endforeach
    </div>
</div>

@endsection

@section('scripts')
<style>
.rpt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.rpt-card{
  display:flex;flex-direction:column;gap:4px;
  border:1px solid var(--border);border-radius:14px;padding:14px 15px;
  background:var(--card);color:var(--ink);text-decoration:none;
  transition:box-shadow .12s, border-color .12s;
}
.rpt-card:hover{border-color:var(--royal-blue);box-shadow:0 4px 14px rgba(18,57,155,.10)}
.rpt-card .ic{font-size:22px}
.rpt-card b{font-size:13.5px}
.rpt-card .s{font-size:11px;color:var(--muted);line-height:1.6}
.rpt-gold{border-color:var(--brand-yellow);background:#FFFDF0}
</style>
@endsection
