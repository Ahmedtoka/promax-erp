{{--
    زرار البانش في الهيدر (Lane 7 — المهمة ٢). `$punchBar` من `PunchComposer`:
    null = اليوزر مالوش ملف موظف (أو مالوش الصلاحية) فمفيش حاجة تترسم.
--}}
@if ($punchBar)
    @php
        $pbBadge = ['working' => 'b-green', 'break' => 'b-orange', 'off' => 'b-gray'][$punchBar['state']] ?? 'b-gray';
        $pbBtn = ['in' => 'green', 'break' => '', 'back' => '', 'out' => 'red'];
    @endphp
    <div id="punchBar" data-noprint style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        <span class="badge {{ $pbBadge }}" title="{{ __('hrm.my_attendance') }}">🕒 {{ __('hr.state_'.$punchBar['state']) }}</span>
        @foreach ($punchBar['next'] as $type)
            <form method="POST" action="{{ route('attendance.punch') }}" style="display:inline">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">
                <button class="btn sm {{ $pbBtn[$type] }}" type="submit">{{ __('hr.punch_'.$type) }}</button>
            </form>
        @endforeach
    </div>
@endif
