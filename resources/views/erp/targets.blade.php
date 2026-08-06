@extends('layouts.system')

{{-- التارجتات الشهرية — أربع قيم لكل مندوب لوحده (2026-08-06). --}}

@php $fmt = fn ($n) => number_format((float) $n); @endphp

@section('title', __('incent.targets_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.performance', ['month' => $month->format('Y-m')]) }}">🏆 {{ __('nav.performance') }}</a>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

<div class="card">
    <h3>🎯 {{ __('incent.targets_title') }}
        <span class="side">{{ __('incent.targets_hint') }}</span></h3>

    <div class="searchbar" style="margin-bottom:12px">
        <form method="GET" style="display:flex;gap:8px;align-items:center">
            <label class="f" style="margin:0">{{ __('incent.month') }}</label>
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
        </form>
        <form method="POST" action="{{ route('erp.targets.copy') }}" style="margin-inline-start:auto">
            @csrf
            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
            <button class="btn" type="submit">📋 {{ __('incent.copy_prev') }}</button>
        </form>
    </div>

    <form method="POST" action="{{ route('erp.targets.save') }}">
        @csrf
        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
        <div class="tablewrap tg-tbl">
            <table>
                <tr>
                    <th style="text-align:start">{{ __('settle.rep') }}</th>
                    <th style="width:170px">💰 {{ __('incent.money_target') }}</th>
                    <th style="width:130px">🏪 {{ __('incent.clients_target') }}</th>
                    <th style="width:130px">📍 {{ __('incent.visits_target') }}</th>
                    <th style="width:130px">📦 {{ __('incent.pieces_target') }}</th>
                </tr>
                @foreach ($reps as $rep)
                    @php $t = $targets->get($rep->id); @endphp
                    <tr>
                        <td style="text-align:start">
                            <b>{{ $rep->displayName() }}</b>
                            <div style="font-size:10px;color:var(--muted)">{{ $rep->code }} · {{ __('enums.role.'.$rep->role) }}</div>
                        </td>
                        <td><input type="number" name="rows[{{ $rep->id }}][money]" min="0" step="100" dir="ltr"
                                   value="{{ (float) ($t?->money_target ?? 0) ?: '' }}" style="width:100%;text-align:center;font-weight:800"></td>
                        <td><input type="number" name="rows[{{ $rep->id }}][clients]" min="0" step="1" dir="ltr"
                                   value="{{ (int) ($t?->new_clients_target ?? 0) ?: '' }}" style="width:100%;text-align:center"></td>
                        <td><input type="number" name="rows[{{ $rep->id }}][visits]" min="0" step="1" dir="ltr"
                                   value="{{ (int) ($t?->visits_target ?? 0) ?: '' }}" style="width:100%;text-align:center"></td>
                        <td><input type="number" name="rows[{{ $rep->id }}][pieces]" min="0" step="10" dir="ltr"
                                   value="{{ (int) ($t?->pieces_target ?? 0) ?: '' }}" style="width:100%;text-align:center"></td>
                    </tr>
                @endforeach
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn gold" type="submit">💾 {{ __('common.save') }}</button>
        </div>
    </form>
</div>

@endsection

@section('scripts')
<style>.tg-tbl th, .tg-tbl td { text-align: center; vertical-align: middle; }</style>
@endsection
