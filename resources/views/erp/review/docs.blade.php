@extends('layouts.system')

@section('title', __('review.docs_title'))

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $u = auth()->user();
    $statuses = ['pending', 'ok', 'issue', 'changed', 'all'];
    $stIcon = ['pending' => '○', 'ok' => '✓', 'issue' => '✗', 'changed' => '⚠', 'all' => ''];
    $qs = fn (array $over) => request()->fullUrlWithQuery($over + ['doc' => null, 'page' => null]);
    $pct = fn ($a, $b) => \App\Services\Review\DocQueue::pct($a, $b);
    $active = collect(['type', 'mother', 'group', 'client', 'rep', 'from', 'to', 'flag', 'q'])->filter(fn ($k) => $f[$k] !== '')->count();
@endphp

@section('content')

@include('erp.review._tabs')

{{-- ═══ الإنجاز الكلي — سطر واحد ═══ --}}
<section class="dq-top card" aria-label="{{ __('review.tab_docs') }}">
    <div class="dq-pct" dir="ltr"><b data-o="pct">{{ $overall['pct'] }}</b><small>%</small></div>
    <div class="dq-top-mid">
        <div class="dq-top-line">
            <span data-o="label">{{ __('review.docs_progress', ['ok' => $overall['ok'], 'total' => $overall['total']]) }}</span>
            <span class="rv-sub" data-o="inv">{{ __('review.docs_inv_progress', ['ok' => $overall['ok_inv'], 'n' => $overall['invoices']]) }}</span>
            <span class="rv-sub" data-o="po">{{ __('review.docs_po_progress', ['ok' => $overall['ok_po'], 'n' => $overall['pos']]) }}</span>
            <span class="dq-bad" data-o="issue" title="{{ __('review.st_issue') }}">✗ {{ $overall['issue'] }}</span>
            <span class="dq-warn" data-o="changed" title="{{ __('review.st_changed') }}">⚠ {{ $overall['changed'] }}</span>
        </div>
        <div class="dq-pace" title="{{ collect($pace['people'])->map(fn ($p) => $p['user'].': '.$p['today'].' / '.$p['avg'])->implode(' · ') }}">
            {{ __('review.pace_today', ['n' => $pace['today'], 't' => $pace['target']]) }}
            <span class="dq-pace-bar"><i style="width:{{ min(100, $pace['target'] ? $pace['today'] * 100 / $pace['target'] : 0) }}%"></i></span>
            · {{ __('review.pace_avg', ['n' => $pace['avg']]) }}
            · {{ $pace['eta_days'] !== null ? __('review.pace_eta', ['n' => number_format($pace['remaining']), 'd' => $pace['eta_days']]) : __('review.pace_left', ['n' => number_format($pace['remaining'])]) }}
        </div>
        <div class="rv-bar"><b data-o="bissue" style="width:{{ $overall['total'] ? round($overall['issue'] * 100 / $overall['total'], 2) : 0 }}%"></b><i data-o="bok" style="width:{{ $overall['total'] ? round($overall['ok'] * 100 / $overall['total'], 2) : 0 }}%"></i></div>
    </div>
    <button type="button" class="dq-keys" onclick="document.getElementById('dqKeys').showModal()" title="{{ __('review.keys_title') }}" aria-label="{{ __('review.keys_title') }}">⌨</button>
</section>

{{-- ═══ السلسلة/العميل المختار ← رصيده ومراجعته، ورجوع للحسابات ═══ --}}
@if ($context)
    <section class="dq-ctx" aria-label="{{ $context['label'] }}">
        <div class="dq-ctx-name"><span class="rv-sub">{{ __('review.ctx_'.$context['kind']) }}</span><b>{{ $context['label'] }}</b></div>
        <div><span class="rv-sub">{{ __('review.m_balance') }}</span><b dir="ltr">{{ $money($context['balance']) }}</b></div>
        <div class="dq-ctx-bar">
            <span class="rv-sub">{{ __('review.m_docs_reviewed') }} — {{ __('review.m_docs_n', ['ok' => $context['ok'], 'n' => $context['docs']]) }}</span>
            <b>{{ $context['pct'] }}% <small dir="ltr">{{ $money($context['ok_grand']) }} / {{ $money($context['grand']) }}</small></b>
            <div class="rv-bar thin"><i style="width:{{ $context['pct'] }}%"></i></div>
        </div>
        @if ($context['money_url'])<a class="btn sm" href="{{ $context['money_url'] }}">↩ {{ __('review.ctx_back_money') }}</a>@endif
    </section>
@endif

{{-- ═══ الفلاتر — كل تغيير بيطبّق على طول ═══ --}}
<form class="dq-filters card" method="GET" action="{{ route('erp.review.docs') }}" id="dqForm">
    <input type="hidden" name="status" value="{{ $f['status'] }}">
    <input type="hidden" name="per" value="{{ $per }}">
    <div class="dq-f-row">
        <label class="dq-search">
            <span aria-hidden="true">🔍</span>
            <input name="q" id="dqSearch" type="search" value="{{ $f['q'] }}" placeholder="{{ __('review.docs_search') }}" autocomplete="off" aria-label="{{ __('review.f_search') }}">
            <kbd>/</kbd>
        </label>
        <div class="dq-seg" role="group" aria-label="{{ __('review.f_type') }}">
            @foreach (['' => __('review.f_type_all'), 'invoice' => __('review.type_invoice'), 'po' => __('review.type_po')] as $t => $lbl)
                <a href="{{ $qs(['type' => $t ?: null]) }}" class="{{ $f['type'] === $t ? 'on' : '' }}">{{ $lbl }}</a>
            @endforeach
        </div>
        @if ($active > 0)
            <a class="dq-clear" href="{{ route('erp.review.docs', ['status' => $f['status'], 'per' => $per]) }}">✕ {{ __('review.f_reset') }} <span>{{ $active }}</span></a>
        @endif
    </div>
    {{-- (١٢/١١) التنبيهات الأوتوماتيك — العدد = اللي لسه ماتراجعش بالتنبيه ده --}}
    <div class="dq-f-row dq-flags" role="group" aria-label="{{ __('review.f_flag') }}">
        <span class="rv-sub">{{ __('review.f_flag') }}</span>
        <a href="{{ $qs(['flag' => $f['flag'] === 'any' ? null : 'any']) }}" class="fchip {{ $f['flag'] === 'any' ? 'on' : '' }}">{{ __('review.flag_any') }}</a>
        @foreach (\App\Services\Review\DocQueue::FLAGS as $fk)
            <a href="{{ $qs(['flag' => $f['flag'] === $fk ? null : $fk]) }}" class="fchip f-{{ $fk }} {{ $f['flag'] === $fk ? 'on' : '' }} {{ $flagCounts[$fk] ? '' : 'zero' }}"
               title="{{ __('review.flag_'.$fk.'_hint') }}"><i aria-hidden="true">{{ __('review.flag_'.$fk.'_ico') }}</i>{{ __('review.flag_'.$fk) }} <b>{{ $flagCounts[$fk] }}</b></a>
        @endforeach
    </div>
    <div class="dq-f-row dq-f-sel">
        <label class="fl"><span>{{ __('review.f_mother') }}</span>
            <select name="mother" data-auto><option value="">{{ __('review.f_mother_all') }}</option>
                @foreach ($mothers as $m)<option value="{{ $m->id }}" @selected((int) $f['mother'] === $m->id)>{{ $m->displayName() }}</option>@endforeach
            </select></label>
        <label class="fl"><span>{{ __('review.f_chain') }}</span>
            <select name="group" data-auto><option value="">{{ __('review.f_chain_all') }}</option>
                <option value="solo" @selected($f['group'] === 'solo')>{{ __('review.f_solo') }}</option>
                @foreach ($chains as $g)<option value="{{ $g->id }}" @selected((int) $f['group'] === $g->id)>{{ $g->displayName() }}</option>@endforeach
            </select></label>
        <label class="fl"><span>{{ __('review.f_client') }}</span>
            <select name="client" data-auto><option value="">{{ __('review.f_client_all') }}</option>
                @foreach ($clientOpts as $id => $label)<option value="{{ $id }}" @selected((int) $f['client'] === (int) $id)>{{ $label }}</option>@endforeach
            </select></label>
        <label class="fl"><span>{{ __('review.f_rep') }}</span>
            <select name="rep" data-auto><option value="">{{ __('review.f_rep_all') }}</option>
                @foreach ($repOpts as $id => $label)<option value="{{ $id }}" @selected((int) $f['rep'] === (int) $id)>{{ $label }}</option>@endforeach
            </select></label>
        @php
            $curMonth = $f['from'] !== '' && $f['to'] !== '' && substr($f['from'], 0, 7) === substr($f['to'], 0, 7)
                && $f['from'] === substr($f['from'], 0, 7).'-01' && $f['to'] === \Illuminate\Support\Carbon::parse($f['from'])->endOfMonth()->toDateString()
                ? substr($f['from'], 0, 7) : '';
        @endphp
        <label class="fl dq-month"><span>{{ __('review.f_month') }}</span>
            <select id="dqMonth"><option value="">{{ __('review.f_month_any') }}</option>
                @foreach ($monthOpts as $m)<option value="{{ $m }}" @selected($curMonth === $m)>{{ $m }}</option>@endforeach
            </select></label>
        @include('partials._range', ['from' => $f['from'], 'to' => $f['to'], 'auto' => true, 'shortcuts' => false])
        @if ($f['type'] !== '')<input type="hidden" name="type" value="{{ $f['type'] }}">@endif
        @if ($f['flag'] !== '')<input type="hidden" name="flag" value="{{ $f['flag'] }}">@endif
        @foreach (['by', 'day', 'sample'] as $hk)@if ($f[$hk] !== '')<input type="hidden" name="{{ $hk }}" value="{{ $f[$hk] }}">@endif @endforeach
    </div>
</form>

@if ($f['sample'] !== '' || $f['by'] !== '')
    <div class="dq-sample">🔎 {{ __('review.sample_banner', ['n' => $count, 'who' => $sampleBy ?? '—', 'day' => $f['day'] ?: '—']) }}
        <a href="{{ route('erp.review.docs') }}">✕</a></div>
@endif

{{-- ═══ الحالة — العدادات بتتحرك مع كل ✓ ═══ --}}
<div class="dq-status">
    <div class="dq-seg dq-st" role="group" aria-label="{{ __('review.f_status') }}">
        @foreach ($statuses as $s)
            <a href="{{ $qs(['status' => $s]) }}" class="st-{{ $s }} {{ $f['status'] === $s ? 'on' : '' }}" data-st="{{ $s }}">
                @if ($stIcon[$s])<i aria-hidden="true">{{ $stIcon[$s] }}</i>@endif{{ __('review.st_'.$s) }} <b>{{ $counts[$s] }}</b></a>
        @endforeach
    </div>
    <a class="dq-export" href="{{ route('erp.review.docs.export', request()->except(['doc', 'page', 'per'])) }}" title="{{ __('review.x_export_hint') }}">⬇ {{ __('review.x_export') }}</a>
    <span class="rv-sub dq-scoped"><b dir="ltr">{{ $money($scoped['grand']) }}</b> · {{ __('review.g_stats', ['inv' => $scoped['invoices'], 'po' => $scoped['pos'], 'pcs' => number_format($scoped['pcs']), 'products' => number_format($scoped['products'])]) }}</span>
</div>

<div class="dq">
    {{-- ═══ الطابور ═══ --}}
    <div class="dq-list card">
        @if ($groups === [])
            <div class="dq-none">
                <b>{{ __('review.none_found') }}</b>
                @if ($f['status'] === 'pending' && $counts['all'] > 0)
                    <span class="rv-sub">{{ __('review.none_pending') }}</span>
                    <a class="btn sm" href="{{ $qs(['status' => 'all']) }}">{{ __('review.st_all') }} ({{ $counts['all'] }})</a>
                @elseif ($active > 0)
                    <a class="btn sm" href="{{ route('erp.review.docs', ['status' => $f['status']]) }}">✕ {{ __('review.f_reset') }}</a>
                @endif
            </div>
        @else
        <div class="tablewrap dq-wrap">
            {{-- data-plain: بره أدوات الجداول العامة (ترقيم/إجمالي/إكسيل لكل جروب) — الطابور ليه ترقيمه --}}
            <table class="dq-t" data-plain>
                <thead><tr>
                    <th class="c-dot" aria-label="{{ __('review.f_status') }}"></th>
                    <th>#</th>
                    <th title="{{ __('review.serial') }}">{{ __('review.col_serial') }}</th>
                    <th>{{ __('review.doc_date') }}</th>
                    <th>{{ __('review.doc_client') }}</th>
                    <th class="num">{{ __('review.col_incl') }}</th>
                </tr></thead>
                @foreach ($groups as $g)
                    @php $s = $g['stats']; @endphp
                    <tbody class="dq-g" data-g="{{ $g['key'] }}" data-total="{{ $s['total'] }}" data-ok="{{ $s['ok'] }}" data-issue="{{ $s['issue'] }}" data-changed="{{ $s['changed'] }}" data-pending="{{ $s['pending'] }}">
                        <tr class="dq-gh">
                            <td colspan="6">
                                <div class="dq-gh-in">
                                    <span class="dq-gh-name"><b>{{ $g['label'] }}</b>
                                        <span class="rv-sub">{{ $g['solo'] ? __('review.solo_group') : ($g['mother'] ?? '') }}</span></span>
                                    <span class="dq-gh-bar"><span class="rv-bar thin"><b class="g-bi" style="width:{{ $s['total'] ? round($s['issue'] * 100 / $s['total'], 2) : 0 }}%"></b><i class="g-bo" style="width:{{ $s['total'] ? round($s['ok'] * 100 / $s['total'], 2) : 0 }}%"></i></span></span>
                                    <span class="dq-gh-n"><span dir="ltr"><b class="g-ok">{{ $s['ok'] }}</b>/{{ $s['total'] }}</span><span class="g-iss" @if (! $s['issue']) hidden @endif> · ✗ <b>{{ $s['issue'] }}</b></span></span>
                                    <span class="rv-sub dq-gh-m" dir="ltr">{{ $money($s['grand']) }}</span>
                                </div>
                            </td>
                        </tr>
                        @foreach ($g['rows'] as $r)
                            <tr data-key="{{ $r['key'] }}" data-type="{{ $r['type'] }}" data-id="{{ $r['id'] }}" data-state="{{ $r['state'] }}" class="dq-row st-{{ $r['state'] }}" tabindex="-1">
                                <td class="c-dot"><span class="dot" title="{{ __('review.st_'.$r['state']) }}"></span></td>
                                <td dir="ltr" class="c-num"><span class="tg {{ $r['type'] }}">{{ $r['type'] === 'invoice' ? __('review.tag_invoice') : __('review.tag_po') }}</span><b>{{ $r['number'] }}</b>@foreach (array_intersect($r['flags'], ['odd_date', 'rep_off', 'repriced']) as $fx)<i class="fx f-{{ $fx }}" title="{{ __('review.flag_'.$fx) }}">{{ __('review.flag_'.$fx.'_ico') }}</i>@endforeach</td>
                                <td dir="ltr" class="c-serial">@if ($r['paper_ref']){{ $r['paper_ref'] }}@if ($r['dup']) <span class="flag" title="{{ __('review.f_flag_dup') }}">⚠</span>@endif @else<span class="miss">{{ __('review.serial_missing') }}</span>@endif</td>
                                <td dir="ltr" class="c-date" title="{{ $r['date'] }}">{{ substr($r['date'], 5) }}</td>
                                <td class="c-client" title="{{ $r['client_code'] }} · {{ $r['client'] }}{{ $r['rep'] ? ' — '.$r['rep'] : '' }}">{{ $r['client'] }} <span class="cc">{{ $r['client_code'] }}</span></td>
                                <td dir="ltr" class="c-grand num">{{ $money($r['grand']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
        @include('erp.review._pager', ['p' => $page, 'per' => $per])
        @endif
    </div>

    {{-- ═══ المستند ═══ --}}
    <aside class="dq-pane card" id="dqPane" aria-live="polite">
        <div class="rv-sub dq-empty">{{ __('review.pick_doc') }}</div>
    </aside>
</div>

<div class="dq-toast" id="dqToast" role="status" hidden></div>

<dialog id="dqKeys" class="card">
    <h3>⌨ {{ __('review.keys_title') }}</h3>
    <dl class="dq-keylist">
        @foreach (__('review.keys_list') as $k => $what)
            <dt><kbd>{{ $k }}</kbd></dt><dd>{{ $what }}</dd>
        @endforeach
    </dl>
    <form method="dialog"><button class="btn">OK</button></form>
</dialog>

@php
    // ⚠️ لينكات الـJS — كل واحد محروس بصلاحيته (فحص PermissionInventoryTest)
    $urls = ['pane' => route('erp.review.docs.pane', ['type' => '__T', 'id' => 0])];
    if (\App\Support\Access::allows($u, 'erp.review.docs.mark')) { $urls['mark'] = route('erp.review.docs.mark', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.reopen')) { $urls['reopen'] = route('erp.review.docs.reopen', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.serial')) { $urls['serial'] = route('erp.review.docs.serial', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.rep')) { $urls['rep'] = route('erp.review.docs.rep', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.date')) { $urls['date'] = route('erp.review.docs.date', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.preview')) { $urls['preview'] = route('erp.review.docs.preview', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.lines')) { $urls['lines'] = route('erp.review.docs.lines', ['type' => '__T', 'id' => 0]); }
@endphp

<style>
/* ═══ الإنجاز ═══ */
.dq-top{display:flex;gap:16px;align-items:center;padding:10px 14px}
.dq-pct{display:flex;align-items:baseline;gap:1px;color:var(--green);font-variant-numeric:tabular-nums;min-width:74px}
.dq-pct b{font-size:30px;font-weight:900;line-height:1}.dq-pct small{font-size:15px;font-weight:900}
.dq-top-mid{flex:1;min-width:0}
.dq-top-line{display:flex;gap:12px;flex-wrap:wrap;align-items:baseline;font-size:13px;font-weight:700}
.dq-top .rv-bar{margin:7px 0 0}
.dq-bad{color:var(--red);font-weight:800;font-size:12px}.dq-warn{color:#B45309;font-weight:800;font-size:12px}
.dq-keys{border:1px solid var(--border);background:var(--card);border-radius:8px;width:32px;height:30px;cursor:pointer;font-size:15px;color:var(--muted)}
.dq-keys:hover{color:var(--royal-blue);border-color:var(--royal-blue)}

/* ═══ السلسلة المختارة ═══ */
.dq-ctx{display:flex;gap:22px;align-items:center;flex-wrap:wrap;padding:9px 14px;margin-bottom:12px;border-radius:var(--r-md);
        background:var(--blue-050);border:1px solid #C7D9FF}
.dq-ctx>div{display:flex;flex-direction:column;gap:1px}
.dq-ctx b{font-size:15px;font-variant-numeric:tabular-nums}
.dq-ctx b small{font-size:11px;color:var(--muted);font-weight:700}
.dq-ctx-name b{color:var(--royal-blue)}
.dq-ctx-bar{flex:1;min-width:200px}
.dq-ctx .btn{margin-inline-start:auto}

/* ═══ الفلاتر ═══ */
.dq-filters{padding:10px 12px}
.dq-f-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.dq-f-sel{margin-top:8px;align-items:flex-end}
.dq-f-sel>.fl{flex:1 1 140px;max-width:210px}
.dq-f-sel>.dq-month{flex:0 1 120px}
.dq-f-sel .rng{display:flex;gap:8px;flex-wrap:nowrap}.dq-f-sel .rng .fl{min-width:0;width:132px}
.dq-search{flex:1 1 300px;display:flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:9px;padding:0 9px;background:var(--card)}
.dq-search:focus-within{border-color:var(--royal-blue);box-shadow:0 0 0 3px rgba(18,57,155,.12)}
.dq-search input{border:0!important;box-shadow:none!important;padding:7px 2px!important;flex:1;min-width:0;font-size:13px!important;background:transparent}
.dq-seg{display:inline-flex;padding:2px;gap:2px;background:var(--card2);border:1px solid var(--border);border-radius:9px}
.dq-seg a{padding:4px 10px;border-radius:7px;font-size:12px;font-weight:800;color:var(--muted);text-decoration:none;white-space:nowrap;display:inline-flex;gap:5px;align-items:center}
.dq-seg a:hover{color:var(--text)}
.dq-seg a.on{background:var(--card);color:var(--royal-blue);box-shadow:0 0 0 1px var(--border),0 1px 2px rgba(10,10,15,.06)}
.dq-clear{font-size:12px;font-weight:800;color:var(--red);text-decoration:none;white-space:nowrap}
.dq-clear span{display:inline-block;min-width:17px;padding:0 4px;border-radius:9px;background:var(--red);color:#fff;font-size:10.5px;text-align:center}

/* ═══ الحالة ═══ */
.dq-status{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 10px}
.dq-st a b{font-variant-numeric:tabular-nums;color:var(--text)}
.dq-st a i{font-style:normal;font-weight:900}
.dq-st a.st-ok i{color:var(--green)}.dq-st a.st-issue i{color:var(--red)}.dq-st a.st-changed i{color:#D97706}
.dq-st a.on b{color:var(--royal-blue)}
.dq-scoped b{color:var(--text)}
.dq-export{font-size:12px;font-weight:800;color:var(--green);text-decoration:none;margin-inline-start:auto;order:3}
.dq-pace{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:11.5px;color:var(--muted);margin-top:5px}
.dq-pace-bar{display:inline-block;width:60px;height:5px;border-radius:9px;background:var(--border);overflow:hidden}
.dq-pace-bar i{display:block;height:100%;background:var(--royal-blue)}
.dq-flags{margin-top:8px;gap:5px}
.fchip{display:inline-flex;gap:4px;align-items:center;padding:3px 9px;border-radius:999px;border:1px solid var(--border);background:var(--card);font-size:11.5px;font-weight:800;color:var(--text);text-decoration:none;white-space:nowrap}
.fchip i{font-style:normal}.fchip b{color:#B45309;font-variant-numeric:tabular-nums}
.fchip.zero{opacity:.55}.fchip.zero b{color:var(--muted)}
.fchip:hover{border-color:var(--royal-blue)}
.fchip.on{background:#FFF7ED;border-color:#F59E0B;color:#9A3412}
.dq-sample{padding:8px 12px;margin-bottom:10px;border-radius:10px;background:#F5F3FF;border:1px solid #C4B5FD;color:#4C1D95;font-size:12.5px;font-weight:700;display:flex;gap:10px;align-items:center}
.dq-sample a{margin-inline-start:auto;font-weight:900;color:#4C1D95;text-decoration:none}
.fx{font-style:normal;font-size:10.5px;margin-inline-start:4px}
.dp-checks{display:flex;gap:5px;flex-wrap:wrap;margin:0 0 10px}
.dp-checks span{display:inline-flex;gap:4px;align-items:center;padding:3px 8px;border-radius:7px;font-size:11.5px;font-weight:800;border:1px solid}
.dp-checks .good{background:#F0FDF4;border-color:#BBF7D0;color:#166534}
.dp-checks .bad{background:#FEF2F2;border-color:#FCA5A5;color:#991B1B}
.dp-checks .info{background:#F1F5F9;border-color:#E2E8F0;color:#475569}
.dp-checks.armed .bad{animation:dqpulse .6s ease-in-out 2}
@keyframes dqpulse{50%{box-shadow:0 0 0 3px rgba(220,38,38,.25)}}

/* ═══ الطابور + اللوحة ═══ */
.dq{display:grid;grid-template-columns:minmax(0,1fr) minmax(390px,42%);gap:12px;align-items:start}
.dq-list{padding:0;overflow:hidden}
.dq-wrap{max-height:calc(100vh - 170px);overflow:auto;border-radius:var(--r-md) var(--r-md) 0 0}
.dq-list .rv-pager{margin:0;padding:8px 12px;border-top:1px solid var(--border)}
.dq-t{table-layout:fixed}
.dq-t thead th{position:sticky;top:0;z-index:3}
.dq-t th.c-dot,.dq-t td.c-dot{width:26px;padding-inline:0;text-align:center}
.dq-t th:nth-child(2){width:122px}.dq-t th:nth-child(3){width:76px}.dq-t th:nth-child(4){width:50px}.dq-t th:nth-child(6){width:88px}
.dq-t td.c-client{unicode-bidi:plaintext}
[dir=rtl] .dq-t td.c-client{text-align:right}
.dq-t td{overflow:hidden;text-overflow:ellipsis;max-width:none}
.dq-t tbody tr:nth-child(even){background:none}
.dq-gh td{background:#F6F7FB!important;padding:6px 10px;border-bottom:1px solid var(--border)}
.dq-gh-in{display:flex;gap:10px;align-items:center;min-width:0}
.dq-gh-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dq-gh-name b{font-size:12.5px}
.dq-gh-bar{width:90px;flex:none}.dq-gh-bar .rv-bar{margin:0}
.dq-gh-n{font-size:11.5px;font-variant-numeric:tabular-nums;white-space:nowrap}.dq-gh-n .g-ok{color:var(--green)}.dq-gh-n .g-iss b{color:var(--red)}
.dq-gh-m{white-space:nowrap}
.rv-bar.thin{height:5px}
.dq-row{cursor:pointer}
.dq-row td{transition:background-color .25s}
.dq-row:hover td{background:#F8FAFC}
.dq-row .dot{display:inline-block;width:9px;height:9px;border-radius:50%;border:2px solid #CBD5E1;background:var(--card)}
/* حالة الصف — لون واضح يتشاف من بعيد (طلب أحمد: «يغيّر لونها») */
.dq-row.st-ok td{background:#F0FDF4;color:#3F6B4E}
.dq-row.st-ok .dot{background:var(--green);border-color:var(--green)}
.dq-row.st-ok .c-num b{color:#15803D}
.dq-row.st-issue td{background:#FEF2F2}
.dq-row.st-issue .dot{background:var(--red);border-color:var(--red)}
.dq-row.st-changed td{background:#FFFBEB}
.dq-row.st-changed .dot{background:#F59E0B;border-color:#F59E0B}
.dq-row.cur td{background:#E8F1FF!important;color:var(--text)}
.dq-row.cur td:first-child{box-shadow:inset 3px 0 0 var(--royal-blue)}
[dir=rtl] .dq-row.cur td:first-child{box-shadow:inset -3px 0 0 var(--royal-blue)}
.tg{display:inline-block;font:900 9.5px/1 inherit;padding:3px 4px;border-radius:4px;margin-inline-end:5px;background:#E0E7FF;color:#3730A3;vertical-align:1px}
.tg.po{background:#CFFAFE;color:#155E75}
.c-client .cc{color:var(--muted);font-size:11px}
.miss{color:#B45309;font-weight:800;font-size:11px}
.flag{color:#B45309}
.dq-none{padding:40px 16px;text-align:center;display:flex;flex-direction:column;gap:8px;align-items:center}
.dq-pane{position:sticky;top:10px;max-height:calc(100vh - 20px);overflow:auto;padding:0}
.dq-empty{padding:40px 10px;text-align:center}
.dq-toast{position:fixed;bottom:18px;inset-inline-start:50%;transform:translateX(-50%);background:#0F172A;color:#fff;padding:9px 16px;border-radius:10px;font-weight:800;font-size:13px;z-index:60;max-width:90vw;box-shadow:var(--shadow-lift)}
[dir=rtl] .dq-toast{transform:translateX(50%)}
.dq-toast.bad{background:#B91C1C}.dq-toast.warn{background:#B45309}
kbd{font:700 10px/1 ui-monospace,Consolas,monospace;border:1px solid var(--border);border-bottom-width:2px;border-radius:4px;padding:2px 4px;color:var(--muted);background:var(--card)}
.dq-keylist{display:grid;grid-template-columns:auto 1fr;gap:6px 12px;margin:10px 0 14px;font-size:13px}
.dq-keylist dt{text-align:end}.dq-keylist dd{margin:0}

/* ═══ اللوحة ═══ */
.dp{padding:12px 14px 14px}
.dp-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.dp-num{font-size:18px;margin-inline:4px}
.dp-type{font-size:10.5px;font-weight:900;padding:3px 7px;border-radius:5px;background:#E0E7FF;color:#3730A3}
.dp-type.po{background:#CFFAFE;color:#155E75}
.dp-open{font-size:12px;font-weight:800}
.chip{display:inline-flex;gap:4px;align-items:center;padding:2px 9px;border-radius:999px;border:1px solid var(--border);background:var(--card);font-size:11px;font-weight:800;color:var(--text)}
.chip.st-ok{background:#F0FDF4;border-color:#86EFAC;color:#166534}
.chip.st-issue{background:#FEF2F2;border-color:#FCA5A5;color:#991B1B}
.chip.st-changed{background:#FFFBEB;border-color:#FCD34D;color:#92400E}
.chip.st-lock{background:#F1F5F9;color:#475569}
.dp-client{margin:6px 0 8px;display:flex;flex-direction:column;gap:1px;font-size:13px}
/* زرار ✓ فوق — مايحتاجش سكرول عشان يتلاقي */
.dp-bar{position:sticky;top:0;z-index:4;display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 -14px 10px;padding:8px 14px;background:var(--card);border-bottom:1px solid var(--border)}
.dp-bar .who{font-size:11.5px;color:var(--muted);margin-inline-start:auto}
.btn.ok{background:var(--green);border-color:var(--green);color:#fff;font-weight:800}
.btn.ok:hover{filter:brightness(1.07);color:#fff;border-color:var(--green)}
.btn.bad{background:var(--card);border:1px solid #FCA5A5;color:#B91C1C}
.btn.bad:hover{border-color:var(--red);color:var(--red)}
.btn.ok kbd{color:#DCFCE7;background:transparent;border-color:rgba(255,255,255,.45)}
.btn kbd{margin-inline-start:4px}
.dp-lock,.dp-warn,.dp-note{padding:7px 10px;border-radius:8px;font-size:12px;margin-bottom:8px}
.dp-lock{background:#F1F5F9;color:#334155}.dp-lock a{margin-inline-start:6px}
.dp-warn{background:#FFFBEB;color:#92400E;border:1px solid #FCD34D}
.dp-note{background:#EFF6FF;color:#1E3A8A}
.dp-sbs{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
/* الورقة: سطور دفتر خفيفة وحافة مخرّمة — الحاجة الوحيدة اللي بتشبه الورق في الشاشة */
.slip{padding:9px 11px 10px;border-radius:4px;border:1px solid #E2E8F0;border-inline-start:3px dashed #94A3B8;
      background:#fff repeating-linear-gradient(180deg,transparent 0 25px,#E7EEFF 25px 26px)}
.slip h4,.sys h4{margin:0 0 6px;font-size:12px;color:var(--muted)}
.slip .fl{display:flex;margin-bottom:7px}.slip input{width:100%;background:#fff}
.slip input.dp-serial{font-size:15px!important;font-weight:800;letter-spacing:.5px}
.slip input.dp-serial:placeholder-shown{border-color:#F59E0B;background:#FFFBEB}
.dp-dup{font-size:11.5px;color:#B45309;margin:-3px 0 7px}.dp-dup a{margin-inline-start:6px;font-weight:800}
.dp-pdiff{font-size:12px}.dp-pdiff.bad b{color:var(--red)}.dp-pdiff.good b{color:var(--green)}
.sys{padding:9px 11px;border-radius:8px;background:#F8FAFC;border:1px solid var(--border)}
.sys-row{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:3px 0;border-bottom:1px dashed #E2E8F0;font-size:12.5px;min-height:30px}
.sys-row:last-child{border-bottom:0}.sys-row.big b{font-size:16px}
.sys-val{display:flex;gap:6px;align-items:center;min-width:0}
.sys-val select,.sys-val input{max-width:150px;font-size:12px!important;padding:3px 6px!important}
.dp-lines .tablewrap{border:1px solid var(--border)}
.dp-lines table{table-layout:auto}
.dp-lines table td,.dp-lines table th{padding:5px 7px;font-size:11.5px}
.dp-lines table td:first-child{white-space:normal;min-width:150px}
.dp-lines tr.off td{background:#FFF7ED}
.dp-lines tr.off td.c-list{color:#C2410C;font-weight:800}
.dp-edit-lines{margin-top:8px}
.dp-lines-form{margin-top:10px;padding:10px;border:1px solid #93C5FD;border-radius:8px;background:#F8FBFF}
.dp-edit{width:100%}.dp-edit td,.dp-edit th{padding:4px 6px;font-size:12px}.dp-edit input{width:80px}
.dp-edit .x{border:0;background:none;color:var(--red);cursor:pointer;font-weight:900}
.dp-add{margin:8px 0}.dp-add select{width:100%}
.dp-preview{padding:7px 10px;border-radius:8px;background:#fff;border:1px solid var(--border);margin-bottom:8px;font-size:12.5px}
.dp-preview .d-up{color:#B91C1C}.dp-preview .d-down{color:#15803D}
.dp-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.dp-hist{margin-top:12px}
.dp-hist summary{cursor:pointer;font-weight:800;font-size:12.5px;margin-bottom:6px}
.ev{display:flex;gap:8px;padding:5px 0;border-bottom:1px dashed #E2E8F0;font-size:12px}
.ev-i{width:16px;text-align:center;font-weight:900}.ev-money{margin-inline-start:6px;font-weight:800}
.dp.busy{opacity:.55;pointer-events:none}

@media (max-width:1100px){.dq{grid-template-columns:1fr}.dq-pane{position:static;max-height:none}.dq-wrap{max-height:60vh}}
@media (max-width:640px){
    .dq-top{flex-wrap:wrap;gap:8px}.dq-pct b{font-size:24px}
    .dq-f-sel>.fl{flex:1 1 calc(50% - 4px);max-width:calc(50% - 4px);min-width:0}.dq-f-sel>.dq-month{flex-basis:100%;max-width:none}
    .dq-f-sel .rng{flex:1 1 100%;width:100%}.dq-f-sel .rng .fl{flex:1 1 0;width:auto;min-width:0}.dq-f-sel .rng input{width:100%;min-width:0}
    .dq-f-sel .ssel,.dq-f-sel .ssel-btn{min-width:0;max-width:100%}
    .dq-status{flex-direction:column;align-items:stretch}.dq-st{overflow-x:auto;max-width:100%}
    .tg{display:none}
    .dp-sbs{grid-template-columns:1fr}
    .dq-t th:nth-child(3),.dq-t td.c-serial,.dq-t th:nth-child(4),.dq-t td.c-date,.dq-t th:nth-child(5),.dq-t td.c-client{display:none}
    .dq-gh-bar,.dq-gh-m{display:none}
    .dq-ctx{gap:10px}
}
@media (prefers-reduced-motion:no-preference){.dq-row.flash td{animation:dqf .9s ease-out}@keyframes dqf{from{background:#BBF7D0}}}
</style>

<script>
(() => {
    const URLS = @js($urls);
    const CSRF = @js(csrf_token());
    const PAGE = { next: @js($page->hasMorePages() ? $page->nextPageUrl() : null), prev: @js($page->currentPage() > 1 ? $page->previousPageUrl() : null) };
    const T = {
        issue: @js(__('review.issue_prompt')), reopen: @js(__('review.reopen_prompt')),
        net: @js(__('review.tot_net')), vat: @js(__('review.tot_vat')), incl: @js(__('review.tot_incl')),
        delta: @js(__('review.preview_delta')), after: @js(__('review.preview_title')),
        prog: @js(__('review.docs_progress', ['ok' => '__OK', 'total' => '__TOTAL'])),
        inv: @js(__('review.docs_inv_progress', ['ok' => '__OK', 'n' => '__N'])),
        po: @js(__('review.docs_po_progress', ['ok' => '__OK', 'n' => '__N'])),
        st: @js(collect(['pending', 'ok', 'issue', 'changed'])->mapWithKeys(fn ($s) => [$s => __('review.st_'.$s)])),
        missing: @js(__('review.serial_missing')), allDone: @js(__('review.page_done')), confirmRed: @js(__('review.confirm_red')),
    };
    const pane = document.getElementById('dqPane');
    const rows = () => [...document.querySelectorAll('.dq-row')];
    const url = (k, type, id) => URLS[k].replace('__T', type).replace(/\/0(\/|$)/, '/' + id + '$1');
    const fmt = n => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const pctOf = (a, b) => { if (!b) return 0; const p = a * 100 / b; return p >= 10 ? Math.floor(p) : Math.floor(p * 10) / 10; };
    let cur = null, openedAt = 0, loading = 0;

    function toast(msg, kind) {
        const t = document.getElementById('dqToast');
        t.textContent = msg; t.className = 'dq-toast ' + (kind || ''); t.hidden = false;
        clearTimeout(t._h); t._h = setTimeout(() => t.hidden = true, kind === 'warn' ? 6000 : 2400);
    }

    function setParam(u, k, v) { const x = new URL(u, location.href); v ? x.searchParams.set(k, v) : x.searchParams.delete(k); return x.toString(); }

    async function open(key, scroll = true) {
        const tr = document.querySelector('.dq-row[data-key="' + key + '"]');
        const [type, id] = tr ? [tr.dataset.type, tr.dataset.id] : [key.startsWith('inv-') ? 'invoice' : 'po', key.split('-')[1]];
        rows().forEach(r => r.classList.toggle('cur', r === tr));
        if (tr && scroll) tr.scrollIntoView({ block: 'nearest' });
        cur = key;
        history.replaceState(null, '', setParam(location.href, 'doc', key));
        const ticket = ++loading;
        pane.querySelector('.dp')?.classList.add('busy');
        const res = await fetch(url('pane', type, id), { headers: { 'Accept': 'text/html' } });
        if (ticket !== loading) return;      // اتفتح مستند تاني في النص — سيب الأحدث
        if (!res.ok) { toast('⚠️ ' + res.status, 'bad'); return; }
        pane.innerHTML = await res.text();
        pane.scrollTop = 0;
        openedAt = performance.now();
        bindPane();
    }

    // اللي بعده/قبله — ولو آخر الصفحة يروح للصفحة اللي بعدها
    function step(dir) {
        const list = rows();
        if (!list.length) return;
        const i = list.findIndex(r => r.dataset.key === cur);
        const j = i + dir;
        if (j >= list.length && PAGE.next) { location.href = setParam(PAGE.next, 'go', 'first'); return; }
        if (j < 0 && PAGE.prev) { location.href = setParam(PAGE.prev, 'go', 'last'); return; }
        const n = list[Math.min(list.length - 1, Math.max(0, j))];
        if (n && n.dataset.key !== cur) open(n.dataset.key);
    }

    // بعد ✓: أول مستند «لسه» بعد ده — المراجع مش محتاج يعدّي على اللي خلص
    function nextPending() {
        const list = rows();
        const i = list.findIndex(r => r.dataset.key === cur);
        const n = list.slice(i + 1).find(r => r.dataset.state === 'pending') || list.slice(0, Math.max(0, i)).find(r => r.dataset.state === 'pending');
        if (n) { open(n.dataset.key); return; }
        if (PAGE.next) { location.href = setParam(PAGE.next, 'go', 'pending'); return; }
        toast(T.allDone);
    }

    async function post(kind, body) {
        const dp = pane.querySelector('.dp');
        if (!dp || !URLS[kind]) return null;
        const fd = body instanceof FormData ? body : new FormData();
        if (!(body instanceof FormData)) Object.entries(body || {}).forEach(([k, v]) => v !== null && v !== undefined && fd.append(k, v));
        fd.append('_token', CSRF);
        if (kind !== 'preview') dp.classList.add('busy');
        try {
            const res = await fetch(url(kind, dp.dataset.type, dp.dataset.id), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
            const data = await res.json().catch(() => ({ ok: false, message: '⚠️ ' + res.status }));
            if (kind === 'preview') return data;
            if (!data.ok) { toast(data.message || ('⚠️ ' + res.status), 'bad'); return null; }
            if (data.row) paintRow(data.row);
            if (data.overall) paintOverall(data.overall);
            if (data.pane && dp.dataset.key === cur) { pane.innerHTML = data.pane; bindPane(); }
            toast(data.message);
            if (data.warn) setTimeout(() => toast(data.warn, 'warn'), 600);
            return data;
        } finally { pane.querySelector('.dp')?.classList.remove('busy'); }
    }

    // ═══ لون الصف + عدّادات السلسلة والحالة — بفرق الحالة القديمة والجديدة ═══
    function paintRow(r) {
        const tr = document.querySelector('.dq-row[data-key="' + r.key + '"]');
        if (!tr) return;
        const was = tr.dataset.state;
        tr.dataset.state = r.state;
        tr.className = 'dq-row st-' + r.state + (r.key === cur ? ' cur' : '');
        tr.querySelector('.dot').title = T.st[r.state] || '';
        tr.querySelector('.c-serial').innerHTML = r.paper_ref ? escapeHtml(r.paper_ref) + (r.dup ? ' <span class="flag">⚠</span>' : '') : '<span class="miss">' + escapeHtml(T.missing) + '</span>';
        tr.querySelector('.c-date').textContent = String(r.date).slice(5); tr.querySelector('.c-date').title = r.date;
        tr.querySelector('.c-grand').textContent = fmt(r.grand);
        tr.classList.add('flash'); setTimeout(() => tr.classList.remove('flash'), 900);
        if (was !== r.state) { bumpGroup(tr.closest('.dq-g'), was, r.state); bumpChips(was, r.state); }
    }

    function bumpGroup(g, was, now) {
        if (!g) return;
        g.dataset[was] = (+g.dataset[was] || 0) - 1;
        g.dataset[now] = (+g.dataset[now] || 0) + 1;
        const tot = +g.dataset.total || 0, ok = +g.dataset.ok, iss = +g.dataset.issue;
        g.querySelector('.g-ok').textContent = ok;
        g.querySelector('.g-bo').style.width = (tot ? ok * 100 / tot : 0) + '%';
        g.querySelector('.g-bi').style.width = (tot ? iss * 100 / tot : 0) + '%';
        const gi = g.querySelector('.g-iss'); gi.hidden = !iss; gi.querySelector('b').textContent = iss;
    }

    function bumpChips(was, now) {
        [[was, -1], [now, 1]].forEach(([s, d]) => {
            const b = document.querySelector('.dq-st a[data-st="' + s + '"] b');
            if (b) b.textContent = Math.max(0, (+b.textContent || 0) + d);
        });
    }

    function paintOverall(o) {
        const q = k => document.querySelector('[data-o="' + k + '"]');
        q('pct').textContent = pctOf(o.ok, o.total);
        q('label').textContent = T.prog.replace('__OK', o.ok).replace('__TOTAL', o.total);
        q('inv').textContent = T.inv.replace('__OK', o.ok_inv).replace('__N', o.invoices);
        q('po').textContent = T.po.replace('__OK', o.ok_po).replace('__N', o.pos);
        q('issue').textContent = '✗ ' + o.issue; q('changed').textContent = '⚠ ' + o.changed;
        q('bok').style.width = (o.total ? o.ok * 100 / o.total : 0) + '%';
        q('bissue').style.width = (o.total ? o.issue * 100 / o.total : 0) + '%';
    }

    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

    async function markOk() {
        if (!pane.querySelector('.dp-ok')) return;
        // فيه تنبيه أحمر؟ أول Enter بيوريه، التاني (خلال ٥ ثواني) بيأكد ✓
        const dp0 = pane.querySelector('.dp'), bad = pane.querySelectorAll('.dp-checks .bad').length;
        if (bad && !(dp0._armed && Date.now() - dp0._armed < 5000)) {
            dp0._armed = Date.now();
            const box = pane.querySelector('.dp-checks'); box.classList.remove('armed'); void box.offsetWidth; box.classList.add('armed');
            toast(T.confirmRed.replace(':n', bad), 'warn');
            return;
        }
        const key = cur;
        const d = await post('mark', { status: 'ok', open_ms: Math.round(performance.now() - openedAt) });
        if (d && key === cur) nextPending();
    }
    async function markIssue() {
        if (!pane.querySelector('.dp-issue')) return;
        const note = prompt(T.issue, '');
        if (note === null || !note.trim()) return;
        await post('mark', { status: 'issue', note, open_ms: Math.round(performance.now() - openedAt) });
    }

    function bindPane() {
        const dp = pane.querySelector('.dp');
        if (!dp) return;
        dp.querySelector('.dp-ok')?.addEventListener('click', markOk);
        dp.querySelector('.dp-issue')?.addEventListener('click', markIssue);
        dp.querySelector('.dp-reopen')?.addEventListener('click', () => { const n = prompt(T.reopen, ''); if (n !== null) post('reopen', { note: n }); });
        dp.querySelectorAll('[data-goto]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); open(a.dataset.goto); }));

        // السيريال: Enter يحفظ ويطلع من الخانة — فـEnter التانية = ✓
        const serial = dp.querySelector('.dp-serial');
        serial?.addEventListener('keydown', async e => {
            if (e.key !== 'Enter') return;
            e.preventDefault(); e.stopPropagation();
            if (serial.readOnly) return;
            if (serial.value.trim() === (serial.defaultValue || '').trim()) { serial.blur(); return; }
            await post('serial', { paper_ref: serial.value });
            document.activeElement?.blur();
        });

        const pt = dp.querySelector('.dp-ptotal'), pd = dp.querySelector('.dp-pdiff');
        pt?.addEventListener('input', () => {
            const v = parseFloat(pt.value.replace(/,/g, ''));
            if (isNaN(v)) { pd.hidden = true; return; }
            const diff = Math.round((Number(pt.dataset.grand) - v) * 100) / 100;
            pd.hidden = false; pd.querySelector('b').textContent = fmt(diff);
            pd.className = 'dp-pdiff ' + (Math.abs(diff) < 0.005 ? 'good' : 'bad');
        });
        pt?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); pt.blur(); } });

        dp.querySelector('.dp-date')?.addEventListener('change', e => post('date', { date: e.target.value }));
        dp.querySelector('.dp-rep')?.addEventListener('change', e => e.target.value && post('rep', { rep_id: e.target.value }));

        const form = dp.querySelector('.dp-lines-form');
        if (!form) return;
        const openEdit = () => { form.hidden = false; form.querySelector('input[type=number]')?.focus(); form.scrollIntoView({ block: 'nearest' }); preview(); };
        dp.querySelector('.dp-edit-lines')?.addEventListener('click', openEdit);
        dp._openEdit = openEdit;
        dp.querySelector('.dp-cancel').addEventListener('click', () => { open(cur, false); });
        form.addEventListener('input', debounce(preview, 300));
        form.addEventListener('click', e => { if (e.target.matches('.x')) { e.target.closest('tr').querySelector('input').value = 0; preview(); } });
        form.querySelector('.dp-add-sel').addEventListener('change', e => {
            const o = e.target.selectedOptions[0];
            if (!o.value) return;
            const tb = form.querySelector('.dp-edit tbody');
            let tr = tb.querySelector('tr[data-pid="' + o.value + '"]');
            if (!tr) {
                tr = document.createElement('tr'); tr.dataset.pid = o.value;
                tr.innerHTML = '<td>' + escapeHtml(o.dataset.label) + '</td><td><input type="number" min="0" step="1" name="qty[' + o.value + ']" value="1" dir="ltr"></td><td dir="ltr">' + escapeHtml(o.dataset.price) + '</td><td><button type="button" class="x">✕</button></td>';
                tb.appendChild(tr);
            }
            tr.querySelector('input').focus(); tr.querySelector('input').select();
            e.target.value = ''; preview();
        });
        form.addEventListener('submit', e => { e.preventDefault(); post('lines', new FormData(form)); });

        async function preview() {
            if (!URLS.preview) return;
            const fd = new FormData(form); fd.delete('note');
            const d = await post('preview', fd);
            const box = form.querySelector('.dp-preview');
            if (!d) return;
            box.hidden = false;
            if (d.not_priced) { box.textContent = '⚠ ' + d.not_priced; return; }
            if (d.message && d.ok === false) { box.textContent = '⚠ ' + d.message; return; }
            const delta = Math.round((d.grand - d.before.grand) * 100) / 100;
            box.innerHTML = '<b>' + escapeHtml(T.after) + '</b> — ' + escapeHtml(T.net) + ' <b dir="ltr">' + fmt(d.net) + '</b> · ' + escapeHtml(T.vat) + ' <b dir="ltr">' + fmt(d.tax) + '</b> · ' + escapeHtml(T.incl) + ' <b dir="ltr">' + fmt(d.grand) + '</b><br>' + escapeHtml(T.delta) + ': <b dir="ltr" class="' + (delta > 0 ? 'd-up' : 'd-down') + '">' + (delta > 0 ? '+' : '') + fmt(delta) + '</b> <span class="rv-sub">(' + fmt(d.before.grand) + ' → ' + fmt(d.grand) + ')</span>';
        }
    }

    function debounce(fn, ms) { let h; return (...a) => { clearTimeout(h); h = setTimeout(() => fn(...a), ms); }; }

    document.querySelector('.dq-list').addEventListener('click', e => { const tr = e.target.closest('.dq-row'); if (tr) open(tr.dataset.key, false); });

    // الفلاتر بتتطبق أول ما تتغير — من غير زرار «اعرض»
    const form = document.getElementById('dqForm');
    form.querySelectorAll('select[data-auto]').forEach(s => s.addEventListener('change', () => form.submit()));
    // الشهر = من أول يوم لآخر يوم — بيملا خانتين الفترة ويبعت
    document.getElementById('dqMonth')?.addEventListener('change', e => {
        const m = e.target.value, from = form.querySelector('[name=from]'), to = form.querySelector('[name=to]');
        if (!m) { from.value = ''; to.value = ''; }
        else { const [y, mo] = m.split('-').map(Number); from.value = m + '-01'; to.value = m + '-' + String(new Date(y, mo, 0).getDate()).padStart(2, '0'); }
        form.submit();
    });

    document.addEventListener('keydown', e => {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        const typing = e.target.closest('input, select, textarea, [contenteditable]');
        if (e.key === 'Escape') {
            if (pane.querySelector('.dp-lines-form:not([hidden])')) { e.preventDefault(); open(cur, false); }
            else if (typing) e.target.blur();
            return;
        }
        if (typing || document.querySelector('dialog[open]')) return;
        const dp = pane.querySelector('.dp');
        switch (e.key) {
            case '/': e.preventDefault(); document.getElementById('dqSearch').focus(); document.getElementById('dqSearch').select(); break;
            case 'j': case 'J': case 'ArrowDown': e.preventDefault(); step(1); break;
            case 'k': case 'K': case 'ArrowUp': e.preventDefault(); step(-1); break;
            case 'Enter': if (dp) { e.preventDefault(); markOk(); } break;
            case 'x': case 'X': if (dp) { e.preventDefault(); markIssue(); } break;
            case 's': case 'S': if (dp) { e.preventDefault(); dp.querySelector('.dp-serial')?.focus(); dp.querySelector('.dp-serial')?.select(); } break;
            case 'd': case 'D': if (dp) { e.preventDefault(); dp.querySelector('.dp-date')?.focus(); } break;
            case 'r': case 'R': if (dp) { e.preventDefault(); dp.querySelector('.dp-rep')?.focus(); } break;
            case 'e': case 'E': if (dp?._openEdit) { e.preventDefault(); dp._openEdit(); } break;
            case 'p': case 'P': if (dp) { e.preventDefault(); dp.querySelector('.dp-ptotal')?.focus(); } break;
            case '?': document.getElementById('dqKeys').showModal(); break;
        }
    });

    const go = new URL(location.href).searchParams.get('go');
    const list = rows();
    const start = @js($open) || (go === 'last' ? list[list.length - 1]?.dataset.key
        : (go === 'first' ? list[0]?.dataset.key : (list.find(r => r.dataset.state === 'pending') || list[0])?.dataset.key));
    if (go) history.replaceState(null, '', setParam(location.href, 'go', null));
    if (start) open(start);
})();
</script>
@endsection
