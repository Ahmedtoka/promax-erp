@extends('layouts.system')

@section('title', __('count.count').' '.$count->number)

@php
    $fmt = fn ($n) => number_format((float) $n);
    $money = fn ($n) => number_format((float) $n, 2);
    $manager = auth()->user()->isManager();
    $open = $count->isOpen();
    $moved = $items->filter(fn ($i) => $i->moved())->count();
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.counts') }}">← {{ __('count.counts') }}</a>
    <button class="btn" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

@if (($openTransfers ?? 0) > 0)
    {{-- ⚠️ التحذير ده لازم يبقى فوق الجدول: اللي بيعدّ بيبص على
         الأرقام على طول، والسبب اللي هيخلّيه يشك في رقم لازم يكون
         قدام عينه قبل ما يكتب. --}}
    <div class="alert warn" style="margin-bottom:14px">
        <span>⚠️</span><span>{{ __('stock.count_open_transfers', ['count' => $openTransfers]) }}</span>
    </div>
@endif


<div class="card">
    <h3>📊 {{ $count->number }}
        <span class="side">{{ $count->warehouse->displayName() }} · {{ $count->count_date?->format('Y-m-d') }}</span>
        <span class="badge {{ $count->statusClass() }}">{{ $count->statusLabel() }}</span>
    </h3>

    @if (! $open)
        <div class="alert info">{{ __('count.approved_note') }}</div>
    @else
        @if ($pending > 0)
            <div class="alert warn">{{ __('count.pending_warning', ['count' => $pending]) }}</div>
        @endif
        @if ($moved > 0)
            <div class="alert warn">{{ __('count.moved_warning') }}</div>
        @endif
    @endif
</div>

{{-- ═══════════ الأرقام ═══════════ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('count.lines') }}</div>
        <div class="val">{{ $fmt($items->count()) }}</div>
        <div class="sub2">{{ $count->warehouse->displayName() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('count.pending_lines') }}</div>
        <div class="val {{ $pending > 0 ? 'mid' : 'pos' }}">{{ $fmt($pending) }}</div>
        <div class="sub2">{{ __('count.not_counted') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('count.diff_lines') }}</div>
        <div class="val {{ $diffs > 0 ? 'neg' : 'pos' }}">{{ $fmt($diffs) }}</div>
        <div class="sub2">{{ __('count.difference') }}</div>
    </div>
    @if (! $open)
        <div class="kpi">
            <div class="lbl">{{ __('count.qty_diff') }}</div>
            <div class="val num {{ $count->qty_diff < 0 ? 'neg' : ($count->qty_diff > 0 ? 'pos' : '') }}">
                {{ $count->qty_diff > 0 ? '+' : '' }}{{ $fmt($count->qty_diff) }}
            </div>
            <div class="sub2">{{ __('common.qty') }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">{{ __('count.value_diff') }}</div>
            <div class="val num {{ $count->value_diff < 0 ? 'neg' : ($count->value_diff > 0 ? 'pos' : '') }}">
                {{ $money($count->value_diff) }}
            </div>
            <div class="sub2">{{ __('common.currency') }}</div>
        </div>
    @endif
</div>

{{-- ═══════════ ورقة العد ═══════════ --}}
<form method="POST" action="{{ route('wh.count.record', $count) }}">
    @csrf
    <div class="card">
        <h3>📝 {{ __('count.sheet') }} <span class="side">{{ $items->count() }}</span></h3>

        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    <th>{{ __('stock.expiry') }}</th>
                    <th class="num">{{ __('count.expected') }}</th>
                    @if (! $open)
                        <th class="num">{{ __('count.system_now') }}</th>
                    @endif
                    <th class="num">{{ __('count.counted') }}</th>
                    <th class="num">{{ __('count.difference') }}</th>
                    <th>{{ __('count.reason') }}</th>
                    @if (! $open)
                        <th class="num">{{ __('count.value_diff') }}</th>
                    @endif
                </tr>

                @foreach ($items as $it)
                    <tr>
                        <td>
                            <b>{{ $it->product->displayName() }}</b>
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $it->product->code }}</span>
                        </td>
                        <td class="num s">{{ $it->batchLabel() }}</td>
                        <td class="num s">{{ $it->expiryLabel() ?? '—' }}</td>
                        <td class="num">{{ $fmt($it->expected_qty) }}</td>

                        @if (! $open)
                            <td class="num {{ $it->moved() ? 'mid' : '' }}"
                                @if ($it->moved()) title="{{ __('count.moved_hint') }}" @endif>
                                {{ $fmt($it->system_qty) }}
                            </td>
                        @endif

                        <td class="num">
                            @if ($open)
                                <input type="number" min="0" step="1"
                                       name="counted[{{ $it->id }}]"
                                       value="{{ $it->counted_qty }}"
                                       style="width:88px;text-align:center">
                            @else
                                {{ $it->notCounted() ? '—' : $fmt($it->counted_qty) }}
                            @endif
                        </td>

                        <td class="num {{ $it->difference < 0 ? 'neg' : ($it->difference > 0 ? 'pos' : '') }}">
                            @if ($it->notCounted())
                                <span style="color:var(--muted)">—</span>
                            @else
                                {{ $it->difference > 0 ? '+' : '' }}{{ $fmt($it->difference) }}
                            @endif
                        </td>

                        <td>
                            @if ($open)
                                <select name="reason[{{ $it->id }}]" style="width:130px">
                                    <option value="">—</option>
                                    @foreach ($reasons as $r)
                                        <option value="{{ $r }}" @selected($it->reason === $r)>{{ __('count.reason_'.$r) }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="s">{{ $it->reasonLabel() ?? '—' }}</span>
                            @endif
                        </td>

                        @if (! $open)
                            <td class="num {{ $it->value_diff < 0 ? 'neg' : ($it->value_diff > 0 ? 'pos' : '') }}">
                                {{ $money($it->value_diff) }}
                            </td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </div>

        @if ($open)
            <div style="display:flex;gap:9px;justify-content:flex-end;margin-top:14px" class="noprint">
                <button class="btn gold" type="submit">💾 {{ __('count.save_counts') }}</button>
            </div>
        @endif
    </div>
</form>

{{-- ═══════════ الاعتماد ═══════════ --}}
@if ($open && $manager)
<div class="card noprint" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:center">
    <form method="POST" action="{{ route('wh.count.approve', $count) }}" onsubmit="return confirm(APPROVE_CONFIRM)">
        @csrf
        <button class="btn green" style="padding:11px 26px">✅ {{ __('count.approve') }}</button>
    </form>

    <form method="POST" action="{{ route('wh.count.cancel', $count) }}" onsubmit="return confirm(CANCEL_CONFIRM)">
        @csrf
        <button class="btn red">✖️ {{ __('count.cancel_count') }}</button>
    </form>
</div>
@endif

@endsection

@section('scripts')
<script>
    {{-- ⚠️ في ثوابت مش جوه onsubmit — الأبوستروف بيكسّر الجافاسكريبت --}}
    const APPROVE_CONFIRM = @js(__('count.approve_confirm'));
    const CANCEL_CONFIRM = @js(__('count.cancel_confirm'));
</script>
<style>
@media print{
  .sidebar,.topbar,.flash,.noprint,.kpis{display:none !important}
  .main{padding:0 !important}
  body{background:#fff}
  input[type=number]{border:1px solid #999 !important;background:#fff !important}
  select{display:none}
}
</style>
@endsection
