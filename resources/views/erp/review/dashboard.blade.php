@extends('layouts.system')

@section('title', __('review.title'))

@php
    $pct = fn ($a) => $a['total'] > 0 ? round($a['done'] / $a['total'] * 100) : 0;
    $ipct = fn ($a) => $a['total'] > 0 ? round($a['issue'] / $a['total'] * 100) : 0;
    $isAdmin = auth()->user()->role === 'admin';
    $phases = [
        ['erp.review.data', '🧾', __('review.phase_data'), __('review.phase_data_sub'), $data],
        ['erp.review.money', '💰', __('review.phase_money'), __('review.phase_money_sub'), $money],
    ];
@endphp

@section('content')

@include('erp.review._tabs')

<div class="alert info" style="margin-bottom:14px"><span>🧭</span><span>{{ __('review.intro') }}</span></div>

<div class="rv-phases">
    @foreach ($phases as $i => [$route, $icon, $title, $sub, $a])
        <a class="card rv-phase" href="{{ route($route) }}">
            <div class="rv-ph-head"><span class="rv-num">{{ $i + 1 }}</span> {{ $icon }} <b>{{ $title }}</b></div>
            <div class="rv-sub">{{ $sub }}</div>
            <div class="rv-big">{{ $pct($a) }}%</div>
            <div class="rv-bar"><b style="width:{{ $ipct($a) }}%"></b><i style="width:{{ $pct($a) }}%"></i></div>
            <div class="rv-sub">{{ __('review.done_of', ['done' => $a['done'], 'total' => $a['total']]) }}
                · <span style="color:#DC2626">{{ __('review.issues_n', ['n' => $a['issue']]) }}</span>
                · {{ __('review.started_n', ['n' => $a['started']]) }}
                · {{ __('review.not_started_n', ['n' => $a['total'] - $a['done'] - $a['issue'] - $a['started']]) }}</div>
        </a>
    @endforeach
    <a class="card rv-phase" href="{{ route('erp.review.stock') }}">
        <div class="rv-ph-head"><span class="rv-num">3</span> 📦 <b>{{ __('review.phase_stock') }}</b></div>
        <div class="rv-sub">{{ __('review.phase_stock_sub') }}</div>
        @foreach ([[__('review.warehouses'), $wh], [__('review.reps'), $rp]] as [$lbl, $a])
            <div style="display:flex;justify-content:space-between;margin-top:10px"><b>{{ $lbl }}</b><b>{{ $pct($a) }}%</b></div>
            <div class="rv-bar"><b style="width:{{ $ipct($a) }}%"></b><i style="width:{{ $pct($a) }}%"></i></div>
            <div class="rv-sub">{{ __('review.done_of', ['done' => $a['done'], 'total' => $a['total']]) }}</div>
        @endforeach
    </a>
    @if ($docs)
        <a class="card rv-phase" href="{{ route('erp.review.docs') }}">
            <div class="rv-ph-head"><span class="rv-num">4</span> 🧾 <b>{{ __('review.dash_docs') }}</b></div>
            <div class="rv-sub">{{ __('review.dash_docs_sub') }}</div>
            <div class="rv-big">{{ $docs['pct'] }}%</div>
            <div class="rv-bar"><b style="width:{{ $docs['total'] ? round($docs['issue'] * 100 / $docs['total']) : 0 }}%"></b><i style="width:{{ $docs['pct'] }}%"></i></div>
            <div class="rv-sub">{{ __('review.docs_progress', ['ok' => $docs['ok'], 'total' => $docs['total']]) }}
                · {{ __('review.docs_inv_progress', ['ok' => $docs['ok_inv'], 'n' => $docs['invoices']]) }}
                · {{ __('review.docs_po_progress', ['ok' => $docs['ok_po'], 'n' => $docs['pos']]) }}
                · {{ __('review.docs_ret_progress', ['ok' => $docs['ok_ret'], 'n' => $docs['returns']]) }}
                @if ($docs['returns'])· <span dir="ltr">{{ __('review.dash_ret_value', ['v' => number_format($docs['ret_grand'], 2)]) }}</span>@endif
                · <span style="color:#DC2626">✗ {{ $docs['issue'] }}</span></div>
        </a>
    @endif
</div>

@if ($docs)
<div class="rv-two">
    <div class="card">
        <h3>❌ {{ __('review.dash_issues', ['n' => $docs['issue']]) }}</h3>
        @forelse ($docIssues as $r)
            <a class="dash-iss" href="{{ route('erp.review.docs', ['status' => 'issue', 'doc' => $r['key']]) }}">
                <b dir="ltr">{{ $r['number'] }}</b> <span>{{ $r['client'] }}</span>
                <span class="rv-sub">{{ $r['note'] }}</span>
                <span class="rv-sub dash-iss-by">{{ $r['by'] }} · {{ $r['at'] ? \Illuminate\Support\Carbon::parse($r['at'])->diffForHumans() : '' }}</span>
            </a>
        @empty
            <div class="rv-sub">{{ __('review.dash_no_issues') }}</div>
        @endforelse
    </div>
    <div class="card">
        <h3>⏱ {{ __('review.dash_pace') }}</h3>
        <div class="dash-pace">
            <div><span class="rv-sub">{{ __('review.pace_today_lbl') }}</span><b>{{ $docPace['today'] }} <small>/ {{ $docPace['target'] }}</small></b></div>
            <div><span class="rv-sub">{{ __('review.pace_avg_lbl') }}</span><b>{{ $docPace['avg'] }}</b></div>
            <div><span class="rv-sub">{{ __('review.pace_left_lbl') }}</span><b>{{ number_format($docPace['remaining']) }}</b></div>
            <div><span class="rv-sub">{{ __('review.pace_eta_lbl') }}</span><b>{{ $docPace['eta_days'] ?? '—' }}</b></div>
        </div>
        @foreach ($docPace['people'] as $p)
            <div class="dash-pp"><b>{{ $p['user'] }}</b><span class="rv-sub">{{ __('review.pace_person', ['t' => $p['today'], 'a' => $p['avg']]) }}</span></div>
        @endforeach
    </div>
</div>

{{-- (١٢/١١) إثبات استلام أوامر التوريد — فلوس من غير إثبات = فلوس في خطر --}}
@if ($docProof && $docProof['total']['delivered'] > 0)
    @php $pt = $docProof['total']; @endphp
    <div class="card dash-proof">
        <h3>📦 {{ __('review.dash_proof') }}</h3>
        <div class="rv-sub">{{ __('review.dash_proof_sub') }}</div>
        <div class="dash-pace">
            <div><span class="rv-sub">{{ __('review.pf_delivered') }}</span><b>{{ number_format($pt['delivered']) }}</b></div>
            <div><span class="rv-sub">{{ __('review.pf_proven') }} — {{ __('review.pf_split', ['r' => $pt['receipt'], 's' => $pt['signed']]) }}</span><b style="color:#15803D">{{ number_format($pt['proven']) }} <small>({{ $pt['pct'] }}%)</small></b></div>
            <div><span class="rv-sub">{{ __('review.pf_unproven') }}</span><b style="color:#B91C1C">{{ number_format($pt['unproven']) }}</b></div>
            <div><span class="rv-sub">{{ __('review.pf_risk') }}</span><b style="color:#B91C1C" dir="ltr">{{ number_format($pt['unproven_grand'], 2) }}</b></div>
        </div>
        <div class="tablewrap">
            <table>
                <thead><tr>
                    <th>{{ __('review.pf_chain') }}</th><th class="num">{{ __('review.pf_delivered') }}</th><th class="num">{{ __('review.pf_proven') }}</th>
                    <th class="num">{{ __('review.pf_unproven') }}</th><th class="num">{{ __('review.pf_pct') }}</th><th class="num">{{ __('review.pf_risk') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($docProof['groups'] as $g)
                    <tr>
                        <td><a href="{{ route('erp.review.docs', $g['filter'] + ['type' => 'sales', 'status' => 'all', 'flag' => $g['unproven'] ? 'noproof' : null]) }}"><b>{{ $g['label'] }}</b></a>
                            <span class="rv-sub">{{ $g['solo'] ? __('review.solo_group') : ($g['mother'] ?? '') }}</span></td>
                        <td class="num">{{ $g['delivered'] }}</td>
                        <td class="num">{{ $g['proven'] }}</td>
                        <td class="num" @if ($g['unproven']) style="color:#B91C1C;font-weight:800" @endif>{{ $g['unproven'] }}</td>
                        <td class="num">{{ $g['pct'] }}%</td>
                        <td class="num" dir="ltr" @if ($g['unproven_grand'] > 0) style="color:#B91C1C;font-weight:800" @endif>{{ number_format($g['unproven_grand'], 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($pt['unproven'])<a class="btn sm" href="{{ route('erp.review.docs', ['type' => 'sales', 'status' => 'all', 'flag' => 'noproof']) }}">📦 {{ __('review.pf_open') }} ({{ $pt['unproven'] }})</a>@endif
    </div>
@endif
@endif

<div class="rv-two">
    <div class="card">
        <h3>👥 {{ __('review.people') }}</h3>
        <div class="tablewrap">
            <table>
                <thead><tr>
                    <th>{{ __('review.col_person') }}</th><th>{{ __('review.col_today') }}</th>
                    <th>{{ __('review.col_total') }}</th><th>{{ __('review.col_issues') }}</th><th>{{ __('review.col_last') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($people as $p)
                    <tr>
                        <td>
                            <b>{{ $names[$p->user_id]?->displayName() ?? '#'.$p->user_id }}</b>
                            @if ($isAdmin)
                                <br><a class="rv-sub" href="{{ route('erp.activity.user', $p->user_id) }}" target="_blank" rel="noopener">{{ __('review.activity') }} ↗</a>
                            @endif
                        </td>
                        <td class="num"><b>{{ $p->today }}</b></td>
                        <td class="num">{{ $p->total }}</td>
                        <td class="num" style="color:#DC2626">{{ $p->issues }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($p->last_at)->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">{{ __('review.nobody_yet') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>🕘 {{ __('review.recent') }}</h3>
        <div class="tablewrap" style="max-height:440px;overflow-y:auto">
            <table data-noxl>
                <tbody>
                @forelse ($recent as $c)
                    <tr>
                        <td style="width:26px">{{ $c->status === 'ok' ? '✅' : '❌' }}</td>
                        <td><b>{{ $labels[$c->id] }}</b> — {{ \Illuminate\Support\Str::startsWith($c->item, 'm:') ? substr($c->item, 2) : __('review.item_'.$c->item) }}
                            @if ($c->note)<br><span class="rv-sub">{{ $c->note }}</span>@endif</td>
                        <td class="rv-sub">{{ $c->user?->displayName() }}<br>{{ $c->updated_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td style="text-align:center;color:var(--muted);padding:20px">{{ __('review.nobody_yet') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.rv-phases{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:12px}
.rv-phase{display:block;color:var(--text);transition:.15s;margin:0}
.rv-phase:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.08)}
.rv-ph-head{font-size:15px;display:flex;gap:8px;align-items:center}
.rv-num{display:inline-grid;place-items:center;width:26px;height:26px;border-radius:50%;background:var(--royal-blue);color:#fff;font-weight:900;font-size:13px}
.dash-iss{display:grid;grid-template-columns:auto 1fr;gap:2px 8px;padding:7px 0;border-bottom:1px dashed var(--border);color:var(--text);text-decoration:none;font-size:12.5px}
.dash-iss:hover b{color:var(--royal-blue)}
.dash-iss .rv-sub{grid-column:1/-1}
.dash-pace{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:8px}
.dash-pace>div{display:flex;flex-direction:column}.dash-pace b{font-size:20px}.dash-pace small{font-size:12px;color:var(--muted)}
.dash-pp{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed var(--border);font-size:12.5px}
.rv-big{font-size:30px;font-weight:900;margin-top:6px;color:var(--royal-blue)}
.rv-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px}
</style>

@endsection
