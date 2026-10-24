@extends('layouts.system')

{{-- سجل تعديلات الأسعار (Lane 13) — هيكل مبدئي؛ الفلاتر والتصدير والتفاصيل في Task 5 --}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
@endphp

@section('title', __('reprice.log_title'))

@section('content')

<div class="card">
    <h3>🧾 {{ __('reprice.log_title') }} <span class="side">{{ $runs->total() }}</span></h3>
    <div class="tablewrap"><table>
        <thead><tr>
            <th>{{ __('reprice.col_run') }}</th><th>{{ __('reprice.col_date') }}</th><th>{{ __('reprice.col_user') }}</th>
            <th>{{ __('reprice.col_trigger') }}</th><th>{{ __('reprice.col_status') }}</th>
            <th class="num">{{ __('reprice.k_invoices') }}</th><th class="num">{{ __('reprice.k_pos') }}</th>
            <th class="num">{{ __('reprice.k_skipped') }}</th><th class="num">{{ __('reprice.k_ledger') }}</th>
        </tr></thead>
        <tbody>
            @forelse ($runs as $r)
                <tr>
                    <td><a href="{{ route('erp.price_changes.show', $r) }}">{{ $r->number }}</a></td>
                    <td>{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $r->user?->displayName() ?? '—' }}</td>
                    <td>{{ $r->triggerLabel() }}</td>
                    <td>{{ $r->statusLabel() }}</td>
                    <td class="num">{{ (int) $r->invoices_changed }}</td>
                    <td class="num">{{ (int) $r->pos_changed }}</td>
                    <td class="num">{{ (int) $r->skipped_count }}</td>
                    <td class="num" dir="ltr">{{ $fmt($r->ledger_diff) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted)">{{ __('reprice.log_empty') }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <div class="pag">{{ $runs->links('pagination::simple-default') }}</div>
</div>

@endsection
