@extends('layouts.system')

@section('title', __('agent.runs_title'))

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
@endsection

@section('content')

    {{-- ═══ الكروت — من نفس الكويري المفلترة بتاعة الجدول ═══ --}}
    {{-- (٢٢/٩) العدد/المرفوض/الفاشل فلاتر على الحالة؛ التوكنز والتكلفة والمتوسط بيتفسّروا بالمجال --}}
    @php
        $stq = fn (?string $s) => request()->fullUrlWithQuery(['status' => $s, 'page' => null, 'export' => null]);
        $curSt = $filters['status'] ?? '';
        $pIn = (float) config('agents.price_in'); $pOut = (float) config('agents.price_out');
    @endphp
    <div class="kpis">
        <a @class(['kpi', 'on' => $curSt === '']) href="{{ $stq(null) }}">
            <div class="lbl">{{ __('agent.r_total') }}</div>
            <div class="val">{{ number_format($stats->n) }}</div>
            {{-- (٢٢/٩) العدد مفرود بالحالة --}}
            <div class="sub2">@include('erp._eq', ['total' => $stats->n, 'dec' => 0, 'zeros' => true, 'parts' => [[__('uib.ag_ok'), $stats->n - $stats->refused - $stats->failed], [__('agent.r_refused'), $stats->refused], [__('agent.r_failed'), $stats->failed]]])</div>
        </a>
        <div class="kpi" data-explain onclick="openDlg('agExplain')" title="{{ __('ui.click_to_explain') }}">
            <div class="lbl">{{ __('agent.r_tokens') }}</div>
            <div class="val">{{ number_format($stats->tin + $stats->tout) }}</div>
            <div class="sub2">@include('erp._eq', ['total' => $stats->tin + $stats->tout, 'dec' => 0, 'zeros' => true, 'parts' => [[__('uib.ag_in'), $stats->tin], [__('uib.ag_out'), $stats->tout]]])</div>
        </div>
        <div class="kpi" data-explain onclick="openDlg('agExplain')" title="{{ __('ui.click_to_explain') }}">
            <div class="lbl">{{ __('agent.r_cost') }}</div>
            <div class="val">${{ number_format($cost, 2) }}</div>
            <div class="sub2">{{ __('agent.r_cost_note') }}</div>
        </div>
        <a @class(['kpi', 'mid' => $stats->refused > 0, 'on' => $curSt === 'refused']) href="{{ $stq($curSt === 'refused' ? null : 'refused') }}">
            <div class="lbl">{{ __('agent.r_refused') }}</div>
            <div class="val">{{ number_format($stats->refused) }}</div>
            <div class="sub2">{{ __('agent.r_refused_note') }}</div>
        </a>
        <a @class(['kpi', 'neg' => $stats->failed > 0, 'on' => $curSt === 'failed']) href="{{ $stq($curSt === 'failed' ? null : 'failed') }}">
            <div class="lbl">{{ __('agent.r_failed') }}</div>
            <div class="val">{{ number_format($stats->failed) }}</div>
            <div class="sub2">{{ __('uib.ag_failed_sub') }}</div>
        </a>
        <div class="kpi" data-explain onclick="openDlg('agExplain')" title="{{ __('ui.click_to_explain') }}">
            <div class="lbl">{{ __('agent.r_avg') }}</div>
            <div class="val">{{ number_format($stats->avg_ms / 1000, 1) }}s</div>
            <div class="sub2">{{ __('uib.ag_avg_sub') }}</div>
        </div>
    </div>

    <dialog id="agExplain" style="max-width:640px">
        <h3>{{ __('agent.r_tokens') }} · {{ __('agent.r_cost') }} <span class="side">{{ __('ui.explain') }}</span></h3>
        <div class="tablewrap">
            <table data-noxl>
                <thead><tr>
                    <th style="text-align:start">{{ __('agent.r_domain') }}</th>
                    <th>{{ __('agent.r_total') }}</th>
                    <th>{{ __('agent.r_tokens') }}</th>
                    <th>{{ __('agent.r_cost') }}</th>
                    <th>{{ __('agent.r_avg') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($byDomain as $bd)
                    <tr>
                        <td style="text-align:start"><a href="{{ request()->fullUrlWithQuery(['domain' => $bd->agent_name, 'page' => null]) }}">{{ $bd->agent_name }}</a></td>
                        <td class="num">{{ number_format($bd->n) }}</td>
                        <td class="num">{{ number_format($bd->tin + $bd->tout) }}</td>
                        <td class="num" dir="ltr">${{ number_format($bd->tin / 1000000 * $pIn + $bd->tout / 1000000 * $pOut, 2) }}</td>
                        <td class="num" dir="ltr">{{ number_format($bd->avg_ms / 1000, 1) }}s</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr>
                    <td style="text-align:start">{{ __('common.total') }}</td>
                    <td class="num">{{ number_format($stats->n) }}</td>
                    <td class="num">{{ number_format($stats->tin + $stats->tout) }}</td>
                    <td class="num" dir="ltr">${{ number_format($cost, 2) }}</td>
                    <td class="num" dir="ltr">{{ number_format($stats->avg_ms / 1000, 1) }}s</td>
                </tr></tfoot>
            </table>
        </div>
        <div style="text-align:end;margin-top:10px"><button class="btn" type="button" onclick="closeDlg('agExplain')">{{ __('common.close') }}</button></div>
    </dialog>

    <div class="card">
        <h3>{{ __('agent.runs_title') }}
            <span class="side">{{ __('agent.runs_hint') }}</span></h3>

        <form class="searchbar" method="GET">
            <label class="fl"><span>{{ __('ui.l_status') }}</span>
                <select name="status">
                    <option value="">{{ __('ui.all_of', ['x' => __('uib.statuses')]) }}</option>
                    @foreach (['ok', 'refused', 'failed'] as $st)
                        <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>
                            {{ __('agent.st_'.$st) }}</option>
                    @endforeach
                </select></label>
            <label class="fl"><span>{{ __('ui.l_domain') }}</span>
                <select name="domain">
                    <option value="">{{ __('ui.all_of', ['x' => __('uib.domains')]) }}</option>
                    @foreach ($domains as $d)
                        <option value="{{ $d }}" @selected(($filters['domain'] ?? '') === $d)>{{ $d }}</option>
                    @endforeach
                </select></label>
            @include('partials._range', ['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''])
            <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
            <a class="btn" href="{{ route('erp.agent.runs') }}">{{ __('common.clear') }}</a>
        </form>

        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('common.date') }}</th>
                    <th>{{ __('agent.r_user') }}</th>
                    <th style="text-align:start">{{ __('agent.r_message') }}</th>
                    <th data-nosum>{{ __('agent.r_domain') }}</th>
                    <th data-nosum>{{ __('agent.r_tools') }}</th>
                    <th>{{ __('agent.r_tokens') }}</th>
                    <th data-nosum>⏱</th>
                    <th data-nosum>{{ __('common.status') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $run)
                    <tr>
                        <td class="num" dir="ltr" style="font-size:11px">
                            {{ $run->created_at->format('m-d h:i A') }}</td>
                        <td>{{ $run->conversation?->user?->name ?? '—' }}</td>
                        <td style="text-align:start;max-width:340px">
                            <div style="font-weight:600">{{ \Illuminate\Support\Str::limit($run->user_message, 90) }}</div>
                            @if ($run->status === 'failed' && $run->error)
                                <div style="font-size:10px;color:var(--red,#DC2626)">
                                    {{ \Illuminate\Support\Str::limit($run->error, 80) }}</div>
                            @elseif (($run->response['text'] ?? '') !== '')
                                <div style="font-size:10.5px;color:var(--muted)">
                                    {{ \Illuminate\Support\Str::limit($run->response['text'], 100) }}</div>
                            @endif
                        </td>
                        <td><span class="badge b-purple">{{ $run->agent_name }}</span></td>
                        <td style="font-size:10px;color:var(--muted)">
                            {{ collect($run->tools_called ?? [])->pluck('name')->implode('، ') ?: '—' }}</td>
                        <td class="num">{{ number_format($run->tokens_in + $run->tokens_out) }}</td>
                        <td class="num" style="font-size:11px">{{ number_format($run->duration_ms / 1000, 1) }}s</td>
                        <td>
                            <span class="badge {{ ['ok' => 'b-green', 'refused' => 'b-orange', 'failed' => 'b-red'][$run->status] ?? 'b-gray' }}">
                                {{ __('agent.st_'.$run->status) }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">
                        {{ __('agent.runs_empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @include('partials._pagination', ['p' => $rows])
    </div>

@endsection
