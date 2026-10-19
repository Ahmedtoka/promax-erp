@extends('layouts.system')

@section('title', __('mfg.qc_title'))

@section('content')
@php
    $badge = ['quarantine' => 'b-orange', 'released' => 'b-green', 'rejected' => 'b-red'];
    $canDecide = \App\Support\Access::action(auth()->user(), 'act.mfg.qc_decide');
    $canOrder = \App\Support\Access::allows(auth()->user(), 'mfg.orders.show');
@endphp
<div class="card">
    <h3>🔬 {{ __('mfg.qc_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $orders->total()]) }}</span>
    </h3>

    {{-- فلتر «من — إلى» على `produced_on` — تاريخ إنتاج الباتش --}}
    <form class="searchbar" method="GET" data-noprint>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="qc_status">
            <option value="">{{ __('mfg.all_statuses') }}</option>
            @foreach (\App\Models\ProductionOrder::QC as $st)
                <option value="{{ $st }}" @selected(($filters['qc_status'] ?? '') === $st)>{{ __('mfg.qc_status_'.$st) }}</option>
            @endforeach
        </select></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.qc') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('mfg.col_number') }}</th>
                <th>{{ __('mfg.col_product') }}</th>
                <th data-nosum>{{ __('mfg.col_batch_no') }}</th>
                <th data-nosum>{{ __('mfg.col_produced_on') }}</th>
                <th data-nosum>{{ __('mfg.col_expires_on') }}</th>
                <th class="num">{{ __('mfg.col_output_qty') }}</th>
                <th>{{ __('mfg.col_qc_status') }}</th>
                <th data-nosum>{{ __('mfg.col_qc_at') }}</th>
                <th data-nosum>{{ __('mfg.f_qc_note') }}</th>
                <th data-nosum data-noprint></th>
            </tr>
            @forelse ($orders as $o)
                <tr>
                    <td class="num"><b>@if ($canOrder)<a href="{{ route('mfg.orders.show', $o) }}">{{ $o->number }}</a>@else{{ $o->number }}@endif</b></td>
                    <td>{{ $o->product?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $o->batch_no }}</td>
                    <td class="num s">{{ $o->produced_on?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num s">{{ $o->expires_on?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num">{{ $o->output_qty !== null ? number_format($o->output_qty) : '—' }}</td>
                    <td><span class="badge {{ $badge[$o->qc_status] ?? 'b-gray' }}">{{ $o->qcLabel() }}</span></td>
                    <td class="num s">{{ $o->qc_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="s">{{ $o->qc_note }}</td>
                    <td data-noprint>
                        @if ($canDecide && $o->qc_status === 'quarantine')
                            <button class="btn sm gold" type="button" onclick="openDlg('dlgQc{{ $o->id }}')">🔬 {{ __('mfg.btn_qc_decide') }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_qc') }}</td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $orders])
</div>

{{-- قرار الجودة: دايالوج لكل باتش في الحجر — بنود حدود المواصفة المعتمدة للمنتج --}}
@if ($canDecide)
    @foreach ($orders->where('qc_status', 'quarantine') as $o)
        <dialog id="dlgQc{{ $o->id }}">
            <form class="dlg" method="POST" action="{{ route('mfg.qc.decide', $o) }}">
                @csrf
                <h4>🔬 {{ __('mfg.qc_decide_title', ['batch' => $o->batch_no]) }}</h4>
                <div class="s" style="color:var(--muted);margin-bottom:8px">
                    {{ $o->number }} — {{ $o->product?->displayName() }} — {{ number_format((int) $o->output_qty) }} {{ $o->product?->unitLabel() }}
                </div>
                <label class="f">{{ __('mfg.f_qc_decision') }} *</label>
                <div style="display:flex;gap:16px;margin-bottom:12px">
                    @foreach (\App\Services\Mfg\Qc::DECISIONS as $d)
                        <label><input type="radio" name="decision" value="{{ $d }}" required> {{ __('mfg.qc_decision_'.$d) }}</label>
                    @endforeach
                </div>
                @php($specLimits = $limits[$o->product_id] ?? [])
                @if ($specLimits !== [])
                    <label class="f">{{ __('mfg.qc_results_title') }}</label>
                    <div class="tablewrap">
                        <table>
                            <tr>
                                <th>{{ __('mfg.qc_param') }}</th>
                                <th class="num" data-nosum>{{ __('mfg.qc_range') }}</th>
                                <th data-nosum>{{ __('mfg.qc_measured') }}</th>
                            </tr>
                            @foreach ($specLimits as $i => $l)
                                <tr>
                                    <td>{{ $l['param'] }}</td>
                                    <td class="num">{{ $l['min'] ?? '—' }} – {{ $l['max'] ?? '—' }} {{ $l['unit'] }}</td>
                                    <td><input type="number" step="any" name="results[{{ $i }}]" style="max-width:140px"></td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif
                <div class="frow" style="margin-top:12px">
                    <div>
                        <label class="f">{{ __('mfg.f_qc_note') }} *</label>
                        <textarea name="note" rows="3" maxlength="2000" required></textarea>
                    </div>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
                    <button class="btn" type="button" onclick="closeDlg('dlgQc{{ $o->id }}')">{{ __('common.cancel') }}</button>
                    <button class="btn green" type="submit">{{ __('mfg.btn_qc_save') }}</button>
                </div>
            </form>
        </dialog>
    @endforeach
@endif
@endsection
