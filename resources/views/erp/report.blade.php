@extends('layouts.system')

@section('title', $title)

@section('actions')
    <a class="btn" href="{{ route('erp.reports.hub') }}">← {{ __('rpt.hub_title') }}</a>
    {{-- التصدير بنفس الفلاتر الحالية بالظبط — نفس الكويري ونفس الصفوف --}}
    <a class="btn gold" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇️ {{ __('rpt.export') }}</a>
    <button class="btn" type="button" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

{{-- ═══ الفلاتر ═══ --}}
<div class="card" style="padding:12px 14px">
    <form method="GET" action="{{ route('erp.reports.show', $key) }}"
          style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">

        @if (in_array('range', $filters))
            <div>
                <label class="f">{{ __('rpt.f_from') }}</label>
                <input type="date" name="from" value="{{ request('from', today()->startOfMonth()->toDateString()) }}">
            </div>
            <div>
                <label class="f">{{ __('rpt.f_to') }}</label>
                <input type="date" name="to" value="{{ request('to', today()->toDateString()) }}">
            </div>
        @endif

        @if (in_array('rep', $filters))
            <div style="min-width:180px">
                <label class="f">{{ __('rpt.f_rep') }}</label>
                <select name="user_id">
                    <option value="">{{ __('rpt.f_all') }}</option>
                    @foreach ($repOptions as $u)
                        <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (in_array('channel', $filters))
            <div style="min-width:160px">
                <label class="f">{{ __('rpt.f_channel') }}</label>
                <select name="channel_id">
                    <option value="">{{ __('rpt.f_all') }}</option>
                    @foreach ($channelOptions as $ch)
                        <option value="{{ $ch->id }}" @selected(request('channel_id') == $ch->id)>{{ $ch->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (in_array('payment', $filters))
            <div>
                <label class="f">{{ __('rpt.f_payment') }}</label>
                <select name="payment">
                    <option value="">{{ __('rpt.f_all') }}</option>
                    <option value="cash" @selected(request('payment') === 'cash')>{{ __('rpt.cash') }}</option>
                    <option value="credit" @selected(request('payment') === 'credit')>{{ __('rpt.credit') }}</option>
                </select>
            </div>
        @endif

        @if (in_array('status', $filters))
            <div>
                <label class="f">{{ __('common.status') }}</label>
                <select name="status">
                    <option value="">{{ __('rpt.f_all') }}</option>
                    @foreach (\App\Models\PurchaseOrder::STATUSES as $sk => $sv)
                        <option value="{{ $sk }}" @selected(request('status') === $sk)>{{ __('enums.po_status.'.$sk) }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if (in_array('days', $filters))
            <div>
                <label class="f">{{ __('rpt.f_days') }}</label>
                <input type="number" name="days" min="1" max="365" value="{{ request('days', 14) }}" style="width:90px">
            </div>
        @endif

        @if (in_array('q', $filters))
            <div style="flex:1;min-width:180px">
                <label class="f">{{ __('common.search') }}</label>
                <input type="search" name="q" value="{{ request('q') }}" style="width:100%">
            </div>
        @endif

        <button class="btn gold" type="submit">🔍 {{ __('rpt.apply') }}</button>
    </form>
</div>

{{-- ═══ السامري بوكسات ═══ --}}
<div class="kpis">
    @foreach ($kpis as [$lbl, $val, $cls])
        <div class="kpi">
            <div class="lbl">{{ $lbl }}</div>
            <div class="val {{ $cls }}">{{ $val }}</div>
        </div>
    @endforeach
</div>

{{-- ═══ الجدول — هيدر ثابت + صف إجماليات ═══ --}}
<div class="card">
    <h3>{{ $icon }} {{ $title }}
        <span class="side">{{ __('rpt.rows_n', ['n' => number_format(count($rows))]) }}</span>
    </h3>

    <div class="tablewrap rpt-wrap">
        <table>
            <thead>
            <tr>
                @foreach ($columns as $c)
                    <th @if (($c[1] ?? null) === 'num') class="num" @endif>{{ $c[0] }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        <td @if (($columns[$i][1] ?? null) === 'num') class="num" dir="ltr" @endif>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" style="text-align:center;color:var(--muted);padding:26px">
                    {{ __('rpt.no_rows') }}</td></tr>
            @endforelse
            </tbody>
            @if (! empty($totals))
                <tfoot>
                <tr class="rpt-total">
                    @foreach ($totals as $i => $cell)
                        <td @if (($columns[$i][1] ?? null) === 'num') class="num" dir="ltr" @endif>{{ $cell }}</td>
                    @endforeach
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

@endsection

@section('scripts')
<style>
/* هيدر ثابت مع التمرير — طلب المالك بالنص */
.rpt-wrap{max-height:68vh;overflow:auto}
.rpt-wrap thead th{
  position:sticky;top:0;z-index:3;
  background:var(--royal-blue);color:#fff;
}
.rpt-total td{
  position:sticky;bottom:0;z-index:2;
  background:var(--blue-050);font-weight:900;color:var(--royal-blue);
  border-top:2px solid var(--royal-blue);
}
@media print{.rpt-wrap{max-height:none;overflow:visible}}
</style>
@endsection
