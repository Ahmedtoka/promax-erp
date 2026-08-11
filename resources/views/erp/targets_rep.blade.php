@extends('layouts.system')

{{-- توزيع تارجيت مندوب على عملائه (١١ أغسطس ٢٠٢٦) — المدير بيوزّع من هنا. --}}
{{-- المحقق لكل عميل من قيوده (sale − return) — والسلسلة ليها شارة. --}}

@section('title', __('targets.rep_page_title', ['name' => $rep->displayName()]))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $pctOf = fn ($a, $t) => $t > 0 ? round($a / $t * 100, 1) : 0.0;
    $annual = $repNode !== null ? (float) $repNode->amount : 0.0;
    $repPct = $pctOf($repAchieved, $annual);
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.targets.annual', ['year' => $year]) }}">← {{ __('targets.back') }}</a>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

{{-- ═══ رأس الصفحة: المندوب + سنته ═══ --}}
<div class="card">
    <h3>
        🎯 {{ __('targets.clients_title', ['name' => $rep->displayName()]) }}
        <span class="side">
            <span class="badge b-gray">{{ __('enums.role.'.$rep->role) }}</span>
            <span class="badge b-blue">{{ $year }}</span>
        </span>
    </h3>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        @include('partials._avatar', ['u' => $rep, 'size' => 44])
        <div class="kpis" style="flex:1;margin:0">
            <div class="kpi">
                <div class="lbl">{{ __('targets.kpi_target') }}</div>
                <div class="val num">{{ $fmt($annual) }}</div>
            </div>
            <div class="kpi">
                <div class="lbl">{{ __('targets.kpi_achieved') }}</div>
                <div class="val num pos">{{ $fmt($repAchieved) }}</div>
            </div>
            <div class="kpi">
                <div class="lbl">{{ __('targets.kpi_pct') }}</div>
                <div class="val num">{{ $repPct }}%</div>
                <div style="background:var(--card2);border:1px solid var(--border);border-radius:6px;height:9px;overflow:hidden;margin-top:6px">
                    <div style="height:100%;width:{{ min($repPct, 100) }}%;background:linear-gradient(135deg,var(--royal-blue),var(--purple-heart))"></div>
                </div>
            </div>
        </div>
    </div>
</div>

@if ($repNode === null)
    <div class="alert warn" style="margin-top:12px">
        <span>⏳</span><span>{{ __('targets.no_rep_node') }}</span>
    </div>
@else
    {{-- ═══ توزيع العملاء ═══ --}}
    <div class="card" style="margin-top:12px">
        <h3>🏪 {{ __('targets.clients_split') }}
            <span class="side">{{ __('targets.managers_hint') }}</span></h3>

        <div class="searchbar" style="margin-bottom:10px">
            <input type="text" id="cliFilter" placeholder="🔎 {{ __('targets.filter_clients') }}"
                   oninput="tgFilter(this.value)" style="max-width:280px">
            <span class="badge b-gray">{{ count($rows) }}</span>
        </div>

        <form method="POST" action="{{ route('erp.targets.annual.clients', $repNode->id) }}" data-split>
            @csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <div class="tablewrap">
                <table id="cliTable" data-annual="{{ $annual + 0 }}">
                    <tr>
                        <th style="text-align:start">{{ __('targets.client') }}</th>
                        <th data-nosum style="width:160px">{{ __('targets.amount') }}</th>
                        <th>{{ __('targets.achieved') }}</th>
                        <th data-nosum style="width:90px">%</th>
                    </tr>
                    @forelse ($rows as $row)
                        @php
                            $c = $row['client'];
                            $cliPct = $pctOf($row['achieved'], (float) ($row['amount'] ?? 0));
                            $needle = mb_strtolower(trim(
                                $c->name.' '.($c->name_en ?? '').' '.$c->code.' '.($c->group?->displayName() ?? '')
                            ));
                        @endphp
                        <tr data-name="{{ $needle }}">
                            <td style="text-align:start">
                                <b>{{ $c->fullName() }}</b>
                                @if ($c->group_id !== null)
                                    <span class="badge b-purple" style="margin-inline-start:6px">🏬 {{ __('targets.chain') }}</span>
                                @endif
                                <div style="font-size:10px;color:var(--muted)">{{ $c->code }}</div>
                            </td>
                            <td><input class="sp-amt" type="number" name="rows[{{ $c->id }}]"
                                       step="0.01" min="0" dir="ltr"
                                       value="{{ $row['amount'] !== null ? $row['amount'] + 0 : '' }}"
                                       style="width:100%;text-align:center;font-weight:800"></td>
                            <td class="num">{{ $fmt($row['achieved']) }}</td>
                            <td class="num {{ $cliPct >= 100 ? 'pos' : ($cliPct >= 60 ? 'mid' : '') }}">{{ $cliPct }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:18px">{{ __('targets.no_clients') }}</td></tr>
                    @endforelse
                </table>
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
                <span class="sp-hint badge b-gray"></span>
                <button class="btn gold" type="submit" style="margin-inline-start:auto">💾 {{ __('common.save') }}</button>
            </div>
        </form>
    </div>
@endif

@endsection

@section('scripts')
@php
    $tjsPayload = json_encode([
        'distributed' => __('targets.distributed'),
        'of' => __('targets.of'),
        'left' => __('targets.left'),
        'over' => __('targets.over'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
<script>
(function () {
  var TJS = {!! $tjsPayload !!};

  function fmt(n) {
    return (Math.round(n * 100) / 100).toLocaleString('en-US', {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    });
  }

  // فلتر محلي على صفوف العملاء — بالاسم أو الكود أو السلسلة
  window.tgFilter = function (q) {
    q = (q || '').trim().toLowerCase();
    document.querySelectorAll('#cliTable tr[data-name]').forEach(function (tr) {
      tr.style.display = q === '' || tr.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
    });
  };

  // تلميح «الموزَّع من الإجمالي» — مرن، بيحذّر ومابيمنعش
  function bindSplit(form) {
    var table = form.querySelector('table[data-annual]');
    var hint = form.querySelector('.sp-hint');
    if (!table || !hint) return;

    var annual = parseFloat(table.getAttribute('data-annual') || '0') || 0;
    var amts = Array.prototype.slice.call(table.querySelectorAll('input.sp-amt'));

    function refresh() {
      var sum = 0;
      amts.forEach(function (i) { sum += parseFloat(i.value || '0') || 0; });
      var left = Math.round((annual - sum) * 100) / 100;
      hint.textContent = TJS.distributed + ': ' + fmt(sum) + ' ' + TJS.of + ' ' + fmt(annual)
        + ' — ' + (left >= 0 ? TJS.left : TJS.over) + ' ' + fmt(Math.abs(left));
      hint.className = 'sp-hint badge ' + (Math.abs(left) < 0.01 ? 'b-green' : (left > 0 ? 'b-orange' : 'b-red'));
    }

    amts.forEach(function (a) {
      a.addEventListener('input', refresh);
    });

    refresh();
  }

  document.querySelectorAll('form[data-split]').forEach(bindSplit);
})();
</script>
@endsection
