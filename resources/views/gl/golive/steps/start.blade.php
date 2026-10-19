{{-- ١ — تاريخ البداية وسياسة القفل (D11) --}}
<p style="font-size:13px;color:var(--muted);margin:0 0 10px">{{ __('golive.s1_intro') }}</p>

@if ($canAdmin)
<form method="POST" action="{{ route('gl.golive.start') }}" class="frow">
    @csrf
    <label class="fl"><span>{{ __('golive.s1_start') }}</span>
        <input type="date" name="gl_start_date" value="{{ $start }}" required></label>
    <label class="fl"><span>{{ __('golive.s1_policy') }}</span>
        <select name="gl_close_policy" required>
            <option value="">—</option>
            <option value="manual" @selected($policy === 'manual')>{{ __('golive.s1_policy_manual') }}</option>
            <option value="auto" @selected($policy === 'auto')>{{ __('golive.s1_policy_auto') }}</option>
        </select></label>
    <label class="fl"><span>{{ __('golive.s1_after_days') }}</span>
        <input type="number" name="gl_close_after_days" min="0" max="90" value="{{ $afterDays }}"></label>
    <label class="fl"><span>{{ __('golive.s1_close_through') }}</span>
        <input type="month" name="close_through"></label>
    <button class="btn gold" type="submit">{{ __('common.save') }}</button>
</form>
<p style="font-size:12px;color:var(--muted)">{{ __('golive.s1_close_hint') }}</p>
@else
    <div class="kpis">
        <div class="kpi"><div class="lbl">{{ __('golive.s1_start') }}</div><div class="val num">{{ $start ?: '—' }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('golive.s1_policy') }}</div><div class="val">{{ $policy ? __('golive.s1_policy_'.$policy) : '—' }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('golive.s1_after_days') }}</div><div class="val num">{{ $afterDays }}</div></div>
    </div>
@endif

<div style="margin-top:8px;font-size:12.5px">
    <b>{{ __('golive.s1_closed_months') }}:</b>
    @forelse ($closed as $k)
        <span class="badge b-gray num">{{ $k }}</span>
    @empty
        <span style="color:var(--muted)">—</span>
    @endforelse
</div>
