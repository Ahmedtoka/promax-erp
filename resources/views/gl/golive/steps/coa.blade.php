{{-- ٢ — شجرة المحاسب: الفحوص والاعتماد بيتعملوا في شاشة الشجرة نفسها --}}
<div class="kpis">
    <a class="kpi" href="{{ route('gl.coa') }}"><div class="lbl">{{ __('golive.s2_red_n') }}</div><div class="val num {{ $red > 0 ? 'neg' : '' }}">{{ $red }}</div></a>
    <a class="kpi" href="{{ route('gl.coa') }}"><div class="lbl">{{ __('golive.s2_yellow_n') }}</div><div class="val num {{ $yellow > 0 ? 'mid' : '' }}">{{ $yellow }}</div></a>
    <a class="kpi" href="{{ route('gl.coa') }}"><div class="lbl">{{ __('golive.s2_accepted_n') }}</div><div class="val num">{{ $accepted }}</div></a>
    <div class="kpi"><div class="lbl">{{ __('golive.s2_adopted') }}</div>
        <div class="val {{ $adopted ? 'pos' : 'neg' }}">{{ $adopted ? '✓' : '✕' }}</div>
        <div class="sub2 num">{{ $adoptedAt ?: '—' }}</div></div>
</div>
<a class="btn" href="{{ route('gl.coa') }}">📝 {{ __('golive.s2_open_coa') }}</a>
