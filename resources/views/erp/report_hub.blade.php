@extends('layouts.system')

@section('title', __('rpt.hub_title'))

@section('actions')
    <a class="btn gold" href="{{ route('erp.reports.quotations') }}">📄 {{ __('rpt.qts_title') }}</a>
@endsection

@section('content')

{{-- ═══ فهرس الأقسام + بحث (٢٢/٩) — 26 تقرير في قايمة واحدة كانوا بيتدوّر فيهم بالعين ═══ --}}
<div class="card" style="padding:12px 14px">
    <div class="rpt-top">
        <label class="fl wide grow"><span>{{ __('uib2.hub_find') }}</span>
            <input type="search" id="rptFind" dir="auto" placeholder="{{ __('uib2.hub_find_ph') }}" oninput="rptFilter(this.value)"></label>
        <div class="rpt-jump">
            @foreach ($groups as $g => $grp)
                <a class="btn sm" href="#grp-{{ $g }}">{{ $grp['icon'] }} {{ __('uib2.grp_'.$g) }}
                    <span class="num">({{ count($grp['reports']) }})</span></a>
            @endforeach
        </div>
    </div>
    <div class="sub2" style="margin-top:6px;color:var(--muted);font-size:12px">{{ __('rpt.hub_sub') }}</div>
</div>

@foreach ($groups as $g => $grp)
    <div class="card rpt-group" id="grp-{{ $g }}">
        <h3>{{ $grp['icon'] }} {{ __('uib2.grp_'.$g) }} <span class="side">{{ __('uib2.grp_'.$g.'_sub') }}</span></h3>

        <div class="rpt-grid">
            @foreach ($grp['reports'] as $key => $icon)
                <a class="rpt-card" href="{{ route('erp.reports.show', $key) }}">
                    <span class="ic">{{ $icon }}</span>
                    <b>{{ __('rpt.'.$key) }}</b>
                    <span class="s">{{ __('rpt.'.$key.'_sub') }}</span>
                </a>
            @endforeach

            {{-- عروض الأسعار مع المبيعات — الكارت بيفتح السجل، والإضافة من جواه --}}
            @if ($g === 'sales')
                <a class="rpt-card rpt-gold" href="{{ route('erp.reports.quotations') }}">
                    <span class="ic">📄</span>
                    <b>{{ __('rpt.qts_title') }}</b>
                    <span class="s">{{ __('rpt.quotation_sub') }}</span>
                </a>
            @endif
        </div>
    </div>
@endforeach

<div class="card" id="rptNone" style="display:none;text-align:center;color:var(--muted);padding:22px">{{ __('uib2.hub_none') }}</div>

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
<script>
// بحث في أسماء التقارير ووصفها — القسم اللي مافضلش فيه كروت بيختفي (٢٢/٩)
function rptFilter(v) {
    const s = v.trim().toLowerCase();
    let any = false;
    document.querySelectorAll('.rpt-group').forEach(g => {
        let shown = 0;
        g.querySelectorAll('.rpt-card').forEach(c => {
            const hit = s === '' || c.textContent.toLowerCase().includes(s);
            c.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        g.style.display = shown ? '' : 'none';
        any = any || shown > 0;
    });
    document.getElementById('rptNone').style.display = any ? 'none' : '';
}
</script>
<style>
.rpt-top{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.rpt-jump{display:flex;gap:6px;flex-wrap:wrap}
.rpt-group{scroll-margin-top:12px}
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
