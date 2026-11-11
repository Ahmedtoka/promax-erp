{{--
    ═══ تابات الموديول (١١/١١/٢٠٢٦) — نفس الأربع تابات في المحتملين والحاليين ═══
    ✨ المحفظة / 🧭 راسم خط السير / 📅 جدولة الأسبوع / 👁 متابعة الأسبوع
    `module`: leads | clients. التاب بيظهر بس لو المستخدم يقدر يفتحه.
--}}
@php
    $mtTabs = ($module ?? 'leads') === 'clients'
        ? [['ops.portfolio', '✨', 'nav.leads_portfolio'], ['ops.geo', '🧭', 'nav.leads_route'],
           ['ops.journeys', '📅', 'nav.leads_planner'], ['ops.journeys.week', '👁', 'nav.leads_week']]
        : [['erp.leads', '✨', 'nav.leads_portfolio'], ['erp.leads.route', '🧭', 'nav.leads_route'],
           ['erp.leads.planner', '📅', 'nav.leads_planner'], ['erp.leads.week', '👁', 'nav.leads_week']];
    $mtUser = auth()->user();
@endphp
<nav class="mod-tabs" data-noprint>
    @foreach ($mtTabs as [$mtRoute, $mtIcon, $mtLabel])
        @if (\App\Support\Access::allows($mtUser, $mtRoute))
            <a href="{{ route($mtRoute) }}" @class(['on' => request()->routeIs($mtRoute)])>{{ $mtIcon }} {{ __($mtLabel) }}</a>
        @endif
    @endforeach
</nav>
@once
<style>
.mod-tabs{display:flex;gap:4px;flex-wrap:wrap;margin:0 0 12px;padding:4px;background:var(--card);
    border:1px solid var(--border);border-radius:12px;width:max-content;max-width:100%}
.mod-tabs a{padding:6px 14px;border-radius:9px;font-size:12.5px;font-weight:800;color:var(--muted);text-decoration:none}
.mod-tabs a:hover{color:var(--royal-blue,#12399B);background:var(--blue-050,#E8F1FF)}
.mod-tabs a.on{background:var(--royal-blue,#12399B);color:#fff}
</style>
@endonce
