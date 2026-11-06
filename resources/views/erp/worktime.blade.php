@extends('layouts.system')

@section('title', __('worktime.page'))

@php
    use App\Services\ActivityStats as AS_;

    $fmt = fn ($n) => number_format((float) $n);
    $dur = fn (int $m) => AS_::duration($m);
    $verdictTone = ['good' => 'b-green', 'watch' => 'b-red', 'light' => 'b-gray', 'none' => 'b-gray'];
    $flagTone = ['open_no_work' => 'b-red', 'long_quiet' => 'b-red', 'low_ratio' => 'b-orange', 'mostly_hidden' => 'b-orange'];
    $tracked = array_values(array_filter($rows, fn ($r) => $r['sum']['verdict'] !== 'none'));
    $none = array_values(array_filter($rows, fn ($r) => $r['sum']['verdict'] === 'none'));
@endphp

@section('actions')
    <button class="btn sm gold" type="button" id="wtCheckBtn">🤖 {{ __('worktime.check') }}</button>
@endsection

@section('content')

<form class="searchbar" method="GET" style="margin-bottom:10px;align-items:flex-end">
    @if ($verdict)<input type="hidden" name="verdict" value="{{ $verdict }}">@endif
    @include('partials._range', ['from' => $from, 'to' => $to, 'all' => false, 'auto' => true])
    <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
</form>

<p style="font-size:11.5px;color:var(--muted);margin:0 0 12px">
    ℹ️ {{ $since ? __('worktime.since', ['d' => $since]) : __('worktime.not_started') }}
    @unless ($single) · {{ __('worktime.range_note') }} @endunless
</p>

<div class="kpis">
    <div class="kpi"><div class="lbl">🟢 {{ __('worktime.k_opened') }}</div><div class="val" style="color:var(--green)">{{ $fmt($kpi['opened']) }}</div></div>
    <a class="kpi @if($verdict === 'none') on @endif" href="{{ request()->fullUrlWithQuery(['verdict' => $verdict === 'none' ? null : 'none']) }}">
        <div class="lbl">⚪ {{ __('worktime.k_none') }}</div><div class="val">{{ $fmt($kpi['none']) }}</div></a>
    <div class="kpi"><div class="lbl">🖥 {{ __('worktime.k_open') }}</div><div class="val">{{ $dur($kpi['open']) }}</div></div>
    <div class="kpi"><div class="lbl">✍️ {{ __('worktime.k_active') }}</div><div class="val" style="color:var(--primary)">{{ $dur($kpi['active']) }}</div>
        <div class="sub2">{{ __('worktime.k_ratio') }}: {{ $kpi['ratio'] }}%</div></div>
    <div class="kpi"><div class="lbl">✅ {{ __('worktime.k_actions') }}</div><div class="val">{{ $fmt($kpi['actions']) }}</div>
        <div class="sub2">{{ __('worktime.k_actions_hint') }}</div></div>
    <a class="kpi @if($verdict === 'watch') on @endif" href="{{ request()->fullUrlWithQuery(['verdict' => $verdict === 'watch' ? null : 'watch']) }}">
        <div class="lbl">🔴 {{ __('worktime.k_watch') }}</div><div class="val" style="color:var(--red)">{{ $fmt($kpi['watch']) }}</div></a>
</div>

{{-- خلاصة «اتشيك» — بتتملا بعد الدوس --}}
<div class="card" id="wtAi" style="display:none">
    <h3>🤖 {{ __('worktime.ai_title') }}</h3>
    <div id="wtAiText" style="white-space:pre-wrap;line-height:1.9;font-size:13.5px"></div>
    <p style="font-size:11px;color:var(--muted);margin-top:10px">ℹ️ {{ __('worktime.ai_note') }}</p>
</div>

@if ($tracked || ! $none)
<div class="card">
    <h3>⏱ {{ __('worktime.board') }} <span class="side">{{ $fmt(count($tracked)) }}</span></h3>
    <div class="tablewrap">
        <table data-page="50">
            <thead><tr>
                <th>{{ __('worktime.c_user') }}</th>
                <th data-nosum>{{ __('worktime.c_first') }}</th><th data-nosum>{{ __('worktime.c_last') }}</th>
                <th data-nosum>{{ __('worktime.c_open') }}</th><th data-nosum>{{ __('worktime.c_active') }}</th>
                <th data-nosum>{{ __('worktime.c_idle') }}</th><th data-nosum>{{ __('worktime.c_hidden') }}</th>
                <th data-nosum>{{ __('worktime.c_ratio') }}</th><th data-nosum>{{ __('worktime.c_quiet') }}</th>
                <th>{{ __('worktime.c_actions') }}</th>
                <th>{{ __('worktime.c_flags') }}</th><th>{{ __('worktime.c_verdict') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($tracked as $r)
                @php $u = $r['user']; $s = $r['sum']; @endphp
                <tr class="clickable" onclick="location.href='{{ route('erp.worktime.user', ['user' => $u, 'day' => $to]) }}'">
                    <td><b>{{ $u->displayName() }}</b><br><span style="font-size:10.5px;color:var(--muted)">{{ $u->roleLabel() }}</span></td>
                    <td class="num" style="font-size:11.5px">{{ $s['first'] ? date('h:i A', $s['first']) : '—' }}</td>
                    <td class="num" style="font-size:11.5px">{{ $s['last'] ? date('h:i A', $s['last']) : '—' }}</td>
                    <td class="num">{{ $s['tracked'] ? $dur($s['open']) : '—' }}</td>
                    <td class="num" style="color:var(--green)"><b>{{ $s['tracked'] ? $dur($s['active']) : '—' }}</b></td>
                    <td class="num" style="color:var(--orange,#C77700)">{{ $s['tracked'] ? $dur($s['idle']) : '—' }}</td>
                    <td class="num" style="color:var(--muted)">{{ $s['tracked'] ? $dur($s['hidden']) : '—' }}</td>
                    <td class="num">{{ $s['tracked'] ? $s['ratio'].'%' : '—' }}</td>
                    <td class="num">{{ $s['tracked'] ? $dur($s['longest_quiet']) : '—' }}</td>
                    <td class="num"><b>{{ $fmt($s['actions']) }}</b></td>
                    <td style="white-space:normal;font-size:11px">
                        @foreach ($s['flags'] as $f)<span class="badge {{ $flagTone[$f] }}" style="margin:1px">{{ __('worktime.f_'.$f) }}</span> @endforeach
                    </td>
                    <td><span class="badge {{ $verdictTone[$s['verdict']] }}">{{ __('worktime.v_'.$s['verdict']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="12" style="text-align:center;color:var(--muted);padding:26px">{{ __('worktime.empty_day') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p style="font-size:11.5px;color:var(--muted);margin-top:10px">ℹ️ {{ __('worktime.legend') }}</p>
</div>
@endif

@if ($none)
<div class="card">
    <h3>🔕 {{ __('worktime.none_title') }} <span class="side">{{ $fmt(count($none)) }}</span></h3>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
        @foreach ($none as $r)
            <span class="badge b-gray">{{ $r['user']->displayName() }} · {{ $r['user']->roleLabel() }}</span>
        @endforeach
    </div>
</div>
@endif

@endsection

@section('scripts')
<script>
(function () {
  var btn = document.getElementById('wtCheckBtn');
  if (!btn) return;
  var label = btn.textContent;
  btn.addEventListener('click', function () {
    var box = document.getElementById('wtAi'), out = document.getElementById('wtAiText');
    btn.disabled = true; btn.textContent = '⏳ ' + @json(__('worktime.checking'));
    fetch(@json(route('erp.worktime.check', ['from' => $from, 'to' => $to])), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
    }).then(function (r) { return r.json(); }).then(function (j) {
      box.style.display = ''; out.textContent = j.ok ? j.text : ('⚠️ ' + j.message);
    }).catch(function () {
      box.style.display = ''; out.textContent = '⚠️ ' + @json(__('worktime.ai_failed'));
    }).finally(function () { btn.disabled = false; btn.textContent = label; });
  });
})();
</script>
@endsection
