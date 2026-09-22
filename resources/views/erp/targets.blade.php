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
        <form method="GET" style="display:flex;gap:8px;align-items:flex-end">
            <label class="fl"><span>{{ __('ui.l_month') }}</span>
                <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()"></label>
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
            {{-- جدول إدخال مش داتا — من غير زرار إكسيل --}}
            <table data-noxl>
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
                            <a href="{{ route('ops.rep', $rep->id) }}"><b>{{ $rep->displayName() }}</b></a>
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
                {{-- (٢٢/٩) إجمالي التارجت وهو بيتكتب — اللي بيوزّع لازم يشوف مجموع الفريق قبل ما يحفظ --}}
                <tfoot><tr class="tg-sum">
                    <td style="text-align:start"><b>Σ {{ __('common.total') }}</b>
                        <div style="font-size:10px;color:var(--muted)">{{ __('uib.tgm_total_sub') }}</div></td>
                    @foreach (['money', 'clients', 'visits', 'pieces'] as $col)
                        <td class="num" dir="ltr"><b data-tg-sum="{{ $col }}">0</b></td>
                    @endforeach
                </tr></tfoot>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn gold" type="submit">💾 {{ __('common.save') }}</button>
        </div>
    </form>
</div>

@endsection

@section('scripts')
<style>.tg-tbl th, .tg-tbl td { text-align: center; vertical-align: middle; }
.tg-tbl .tg-sum td { border-top: 2px solid var(--royal-blue); background: var(--card2); }</style>
<script>
(function () {
    // مجموع كل عمود لايف من خانات الإدخال نفسها
    function sum() {
        ['money', 'clients', 'visits', 'pieces'].forEach(function (c) {
            var t = 0;
            document.querySelectorAll('.tg-tbl input[name$="[' + c + ']"]').forEach(function (i) { t += parseFloat(i.value) || 0; });
            var el = document.querySelector('[data-tg-sum="' + c + '"]');
            if (el) el.textContent = t.toLocaleString('en-US');
        });
    }
    document.querySelectorAll('.tg-tbl input').forEach(function (i) { i.addEventListener('input', sum); });
    sum();
})();
</script>
@endsection
