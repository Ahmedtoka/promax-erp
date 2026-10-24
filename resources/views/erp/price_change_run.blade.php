@extends('layouts.system')

{{--
    صفحة تعديل أسعار من السجل (Lane 13) — للقراءة بس.
    الأرقام من ملخص التعديل المتخزن وقت التطبيق، ومن صفوف مستنداته.
    ⚠️ مفيش كويري هنا — كل حاجة جاية من الكنترولر.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $sign = fn ($n) => (float) $n > 0.004 ? 'neg' : ((float) $n < -0.004 ? 'pos' : '');
    $statusBadge = match ($run->status) {
        'draft' => 'b-orange',
        'applied' => 'b-green',
        'master_only' => 'b-blue',
        default => 'b-gray',
    };
    $payLabel = fn ($p) => $p === null ? '—'
        : (\Illuminate\Support\Facades\Lang::has('enums.payment.'.$p) ? __('enums.payment.'.$p) : $p);
@endphp

@section('title', __('reprice.run_title').' — '.$run->number)

@section('actions')
    <a class="btn" href="{{ route('erp.price_changes') }}">← {{ __('reprice.log_title') }}</a>
    @if ($run->isDraft())
        <a class="btn gold" href="{{ route('erp.prices.change', $run) }}">{{ __('reprice.open_preview') }}</a>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🏷️ {{ $run->number }} <span class="badge {{ $statusBadge }}">{{ $run->statusLabel() }}</span>
        <span class="side">{{ $run->triggerLabel() }}@if ($target !== '—') — {{ $target }}@endif</span></h3>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <span class="badge b-gray">{{ __('reprice.meta_scope') }}: {{ __('reprice.scope_'.$run->scope) }}</span>
        <span class="badge b-gray">{{ __('reprice.meta_by') }}: {{ $run->user?->displayName() ?? '—' }}</span>
        <span class="badge b-gray">{{ __('reprice.meta_created') }}: {{ $run->created_at?->format('Y-m-d H:i') }}</span>
        @if ($run->applied_at)
            <span class="badge b-gray">{{ __('reprice.meta_applied') }}: {{ $run->applied_at->format('Y-m-d H:i') }}</span>
        @endif
        <span class="badge b-gray">{{ __('reprice.meta_since') }}: {{ $run->since?->toDateString() ?? __('reprice.all_dates') }}</span>
    </div>
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('reprice.k_clients') }}</div><div class="val">{{ (int) $run->clients_count }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_invoices') }}</div><div class="val">{{ (int) $run->invoices_changed }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_pos') }}</div><div class="val">{{ (int) $run->pos_changed }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_unchanged') }}</div><div class="val">{{ (int) $run->unchanged_count }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_skipped') }}</div><div class="val mid">{{ (int) $run->skipped_count }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('reprice.k_ledger') }}</div>
        <div class="val {{ $sign($run->ledger_diff) }}" dir="ltr">{{ $fmt($run->ledger_diff) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('reprice.ledger_hint') }}</div></div>
</div>

@include('erp._price_change_totals', ['totals' => $totals, 'items' => $items, 'trigger' => $run->trigger])

<div class="card">
    <h3>📄 {{ __('reprice.docs_changed_title') }} <span class="side">{{ $changedTotal }}</span></h3>
    <div class="tablewrap"><table>
        <thead><tr>
            <th>{{ __('reprice.col_number') }}</th><th>{{ __('reprice.col_type') }}</th><th>{{ __('reprice.col_client') }}</th>
            <th>{{ __('reprice.col_date') }}</th><th>{{ __('reprice.col_payment') }}</th>
            <th class="num">{{ __('reprice.before') }}</th><th class="num">{{ __('reprice.after') }}</th><th class="num">{{ __('reprice.diff') }}</th>
        </tr></thead>
        <tbody>
            @forelse ($changed as $d)
                @php $diff = (float) $d->after_grand - (float) $d->before_grand; @endphp
                <tr>
                    <td><a href="{{ route('erp.price_changes.doc', [$run, $d]) }}">{{ $d->number }}</a></td>
                    <td>{{ __('reprice.type_'.$d->doc_type) }}</td>
                    <td>{{ $names[$d->client_id] ?? '—' }}</td>
                    <td>{{ $d->doc_date?->toDateString() ?? '—' }}</td>
                    <td>{{ $payLabel($d->payment) }}</td>
                    <td class="num">{{ $fmt($d->before_grand) }}</td>
                    <td class="num">{{ $fmt($d->after_grand) }}</td>
                    <td class="num {{ $sign($diff) }}" dir="ltr">{{ $fmt($diff) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted)">{{ __('reprice.no_docs') }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
    @if ($changedTotal > $cap)
        <div class="sub2" style="margin-top:8px">{{ __('reprice.more_docs', ['n' => $changedTotal - $cap]) }}</div>
    @endif
</div>

<div class="card">
    <h3>⏭ {{ __('reprice.skipped_title') }} <span class="side">{{ $skippedTotal }}</span></h3>
    <div class="tablewrap"><table>
        <thead><tr>
            <th>{{ __('reprice.col_number') }}</th><th>{{ __('reprice.col_type') }}</th><th>{{ __('reprice.col_client') }}</th>
            <th>{{ __('reprice.col_date') }}</th><th class="num">{{ __('reprice.grand') }}</th><th>{{ __('reprice.col_reason') }}</th>
        </tr></thead>
        <tbody>
            @forelse ($skipped as $d)
                <tr>
                    <td><a href="{{ route('erp.price_changes.doc', [$run, $d]) }}">{{ $d->number }}</a></td>
                    <td>{{ __('reprice.type_'.$d->doc_type) }}</td>
                    <td>{{ $names[$d->client_id] ?? '—' }}</td>
                    <td>{{ $d->doc_date?->toDateString() ?? '—' }}</td>
                    <td class="num">{{ $fmt($d->before_grand) }}</td>
                    <td><span class="badge b-orange">{{ $d->reasonLabel() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted)">{{ __('reprice.no_skipped') }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
    @if ($skippedTotal > $cap)
        <div class="sub2" style="margin-top:8px">{{ __('reprice.more_docs', ['n' => $skippedTotal - $cap]) }}</div>
    @endif
</div>

@endsection
