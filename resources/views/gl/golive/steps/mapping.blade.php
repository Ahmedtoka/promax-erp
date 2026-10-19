{{-- ٥ — الربط: ملخص + لينك شاشة الربط نفسها --}}
<div class="kpis">
    <a class="kpi" href="{{ route('gl.mapping') }}"><div class="lbl">{{ __('golive.s5_keys_ok') }}</div>
        <div class="val num">{{ $keysOk }} / {{ $keysTotal }}</div></a>
    <a class="kpi" href="{{ route('gl.mapping') }}"><div class="lbl">{{ __('golive.s5_problems') }}</div>
        <div class="val num {{ count($problems) ? 'neg' : 'pos' }}">{{ count($problems) }}</div></a>
</div>
@if ($problems !== [])
    <ul style="font-size:12.5px;margin:0 0 10px">
        @foreach ($problems as $p)<li>{{ $p }}</li>@endforeach
    </ul>
@endif
<a class="btn" href="{{ route('gl.mapping') }}">🔗 {{ __('golive.s5_open_mapping') }}</a>
