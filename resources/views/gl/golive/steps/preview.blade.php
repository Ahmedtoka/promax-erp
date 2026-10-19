{{--
    ٨ — معاينة إعادة البناء (D8/D9): ملخص، جدول شهر × نوع مصدر (هيدر ثابت
    + CSV)، الفحوص، والموافقة. المعاينة مابتكتبش في الدفتر حاجة.
--}}
@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $sum = $run?->summary ?? [];
@endphp

@if ($canAdmin)
    <form method="POST" action="{{ route('gl.golive.preview') }}" style="margin-bottom:10px">
        @csrf
        <button class="btn gold" type="submit">🔍 {{ __('golive.s8_run') }}</button>
        <span style="font-size:12px;color:var(--muted)">{{ __('golive.s8_run_hint') }}</span>
    </form>
@endif

@if ($run === null)
    <div class="alert info"><span>ℹ️</span><span>{{ __('golive.s8_none') }}</span></div>
@else
    <div class="kpis">
        <div class="kpi"><div class="lbl">{{ __('golive.col_created') }}</div><div class="val num">{{ $sum['created'] ?? 0 }}</div>
            <div class="sub2 num">{{ $fmt($sum['created_amount'] ?? 0) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('golive.col_deleted') }}</div><div class="val num">{{ $sum['deleted'] ?? 0 }}</div>
            <div class="sub2 num">{{ $fmt($sum['deleted_amount'] ?? 0) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('golive.col_changed') }}</div><div class="val num">{{ $sum['changed'] ?? 0 }}</div>
            <div class="sub2 num">{{ $fmt($sum['changed_delta'] ?? 0) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('golive.s8_overrides') }}</div>
            <div class="val num">{{ $sum['overrides_kept'] ?? 0 }} / {{ $sum['overrides_dropped'] ?? 0 }}</div>
            <div class="sub2">{{ __('golive.s8_overrides_sub') }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('golive.s8_status') }}</div>
            <div class="val">{{ __('golive.s8_'.$run->status) }}</div>
            <div class="sub2 num">#{{ $run->id }} · {{ $run->created_at?->format('Y-m-d H:i') }}</div></div>
    </div>

    @if (! empty($sum['frozen']))
        <div class="alert info"><span>🧊</span><span>{{ __('golive.s8_frozen', ['list' => implode(' · ', $sum['frozen'])]) }}</span></div>
    @endif
    @if ($run->error)
        <div class="alert"><span>⛔</span><span>{{ $run->error }}</span></div>
    @endif

    <h4 style="margin:12px 0 6px">{{ __('golive.s8_rows') }}
        <a class="btn sm" href="{{ route('gl.golive.show', ['n' => 8, 'export' => 1]) }}">📊 {{ __('common.export_screen') }}</a></h4>
    <div class="tablewrap rpt-wrap" style="max-height:60vh;overflow:auto">
        <table>
            <thead><tr>
                <th data-nosum style="position:sticky;top:0">{{ __('golive.col_period') }}</th>
                <th data-nosum style="position:sticky;top:0">{{ __('golive.col_source') }}</th>
                <th style="position:sticky;top:0">{{ __('golive.col_created') }}</th>
                <th style="position:sticky;top:0">{{ __('golive.col_created_amount') }}</th>
                <th style="position:sticky;top:0">{{ __('golive.col_deleted') }}</th>
                <th style="position:sticky;top:0">{{ __('golive.col_deleted_amount') }}</th>
                <th style="position:sticky;top:0">{{ __('golive.col_changed') }}</th>
                <th style="position:sticky;top:0">{{ __('golive.col_changed_delta') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($run->rows ?? [] as $r)
                <tr><td class="num">{{ $r['period'] }}</td><td>{{ $r['source'] }}</td>
                    <td class="num">{{ $r['created'] }}</td><td class="num">{{ $fmt($r['created_amount']) }}</td>
                    <td class="num">{{ $r['deleted'] }}</td><td class="num">{{ $fmt($r['deleted_amount']) }}</td>
                    <td class="num">{{ $r['changed'] }}</td><td class="num">{{ $fmt($r['changed_delta']) }}</td></tr>
            @empty
                <tr><td colspan="8" style="color:var(--muted)">{{ __('golive.s8_no_diff') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h4 style="margin:12px 0 6px">{{ __('golive.s8_checks') }}</h4>
    <div class="tablewrap">
        <table>
            <tr><th data-nosum style="text-align:start">{{ __('golive.s8_check') }}</th><th data-nosum>{{ __('golive.s8_expected') }}</th>
                <th data-nosum>{{ __('golive.s8_actual') }}</th><th data-nosum></th></tr>
            @foreach ($run->checks ?? [] as $c)
                <tr><td style="text-align:start">{{ __($c['label_key']) }}</td>
                    <td class="num">{{ $fmt($c['expected']) }}</td><td class="num">{{ $fmt($c['actual']) }}</td>
                    <td><span class="badge {{ $c['ok'] ? 'b-green' : 'b-red' }}">{{ $c['ok'] ? '✓' : '✕' }}</span></td></tr>
            @endforeach
        </table>
    </div>

    @if ($run->approved_at)
        <div class="alert good" style="margin-top:10px"><span>✓</span><span>{{ __('golive.s8_approved_at', ['at' => $run->approved_at->format('Y-m-d H:i')]) }}</span></div>
    @elseif ($canAdmin)
        <form method="POST" action="{{ route('gl.golive.preview.approve', $run) }}" style="margin-top:10px">
            @csrf
            <button class="btn green" type="submit" @disabled(! $canApprove)>✓ {{ __('golive.s8_approve') }}</button>
            @unless ($canApprove)<span style="font-size:12px;color:var(--muted)">{{ __('golive.s8_approve_disabled') }}</span>@endunless
        </form>
    @endif
@endif
