@extends('layouts.system')

@section('title', __('agent.runs_title'))

@section('content')

    {{-- ═══ الكروت — من نفس الكويري المفلترة بتاعة الجدول ═══ --}}
    <div class="kpis">
        <div class="kpi">
            <div class="lbl">{{ __('agent.r_total') }}</div>
            <div class="val">{{ number_format($stats->n) }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">{{ __('agent.r_tokens') }}</div>
            <div class="val">{{ number_format($stats->tin + $stats->tout) }}</div>
            <div class="sub2">{{ number_format($stats->tin) }} ⬇ · {{ number_format($stats->tout) }} ⬆</div>
        </div>
        <div class="kpi">
            <div class="lbl">{{ __('agent.r_cost') }}</div>
            <div class="val">${{ number_format($cost, 2) }}</div>
            <div class="sub2">{{ __('agent.r_cost_note') }}</div>
        </div>
        <div class="kpi {{ $stats->refused > 0 ? 'mid' : '' }}">
            <div class="lbl">{{ __('agent.r_refused') }}</div>
            <div class="val">{{ number_format($stats->refused) }}</div>
            <div class="sub2">{{ __('agent.r_refused_note') }}</div>
        </div>
        <div class="kpi {{ $stats->failed > 0 ? 'neg' : '' }}">
            <div class="lbl">{{ __('agent.r_failed') }}</div>
            <div class="val">{{ number_format($stats->failed) }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">{{ __('agent.r_avg') }}</div>
            <div class="val">{{ number_format($stats->avg_ms / 1000, 1) }}s</div>
        </div>
    </div>

    <div class="card">
        <h3>{{ __('agent.runs_title') }}
            <span class="side">{{ __('agent.runs_hint') }}</span></h3>

        <form class="searchbar" method="GET">
            <select name="status">
                <option value="">{{ __('common.all') }} — {{ __('common.status') }}</option>
                @foreach (['ok', 'refused', 'failed'] as $st)
                    <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>
                        {{ __('agent.st_'.$st) }}</option>
                @endforeach
            </select>
            <select name="domain">
                <option value="">{{ __('common.all') }} — {{ __('agent.r_domain') }}</option>
                @foreach ($domains as $d)
                    <option value="{{ $d }}" @selected(($filters['domain'] ?? '') === $d)>{{ $d }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}">
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}">
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

        {{ $rows->links() }}
    </div>

@endsection
