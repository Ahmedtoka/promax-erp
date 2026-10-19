@extends('layouts.system')

{{--
    إعدادات المرتبات (Lane 7 — المهمة ٤) — قراءة للموارد البشرية، الحفظ أدمن.
    ⚠️ الديفولتات مبدئية: التنبيه فاضل ظاهر لحد ما الأدمن يحفظ
    (`hr_rates_verified_at`). الشرايح `[from, to, rate]` و«إلى» فاضية = من غير سقف.
--}}

@section('title', __('hrm.settings'))

@php
    $ro = $canEdit ? '' : 'disabled';
    $val = fn ($k) => old($k, $s[$k]);
    $fmtN = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
    $bRows = old('brackets', array_map(fn ($b) => ['from' => $fmtN($b[0] ?? 0), 'to' => $fmtN($b[1] ?? null), 'rate' => $fmtN($b[2] ?? 0)], $brackets));
    $eRows = old('elimination', array_map(fn ($e) => ['over' => $fmtN($e[0] ?? 0), 'upto' => (string) ($e[1] ?? 0)], $elimination));
    $numFields = [
        'set_insurance' => [
            'hr_si_employee_rate' => '0.0001', 'hr_si_employer_rate' => '0.0001',
            'hr_si_min_wage' => '0.01', 'hr_si_max_wage' => '0.01',
        ],
        'set_time' => [
            'hr_month_days' => '1', 'hr_day_minutes' => '1',
            'hr_late_grace_minutes' => '1', 'hr_overtime_rate' => '0.01',
        ],
    ];
@endphp

@section('content')

@if ($s['hr_rates_verified_at'] === '')
    <div class="alert warn" style="margin-bottom:12px"><span>⚠️</span><span><b>{{ __('hrm.defaults_verify') }}</b></span></div>
@endif

{{-- ⚠️ الموارد البشرية بتشوف بس — مفيش فورم حفظ في صفحتها خالص --}}
@if ($canEdit)
<form method="POST" action="{{ route('hr.settings.save') }}">
    @csrf
@else
<div>
@endif

    <div class="card">
        <h3>⚙️ {{ __('hrm.settings') }} <span class="side">{{ __('hrm.settings_sub') }}</span></h3>
        @if ($s['hr_rates_verified_at'] !== '')
            <p style="color:var(--muted);font-size:12px;margin:0 0 8px">{{ __('hrm.set_verified_at', ['at' => $s['hr_rates_verified_at']]) }}</p>
        @endif
        @unless ($canEdit)
            <p style="color:var(--muted);font-size:12px;margin:0 0 8px">🔒 {{ __('hrm.set_read_only') }}</p>
        @endunless

        @foreach ($numFields as $group => $fields)
            <h4>{{ __('hrm.'.$group) }}</h4>
            <div class="searchbar">
                @foreach ($fields as $k => $step)
                    <label class="fl"><span>{{ __('hrm.set_'.substr($k, 3)) }} <b class="req-star">*</b></span>
                        <input type="number" name="{{ $k }}" step="{{ $step }}" min="0" value="{{ $val($k) }}" required {{ $ro }} dir="ltr"></label>
                @endforeach
                @if ($group === 'set_time')
                    <label class="fl"><span>{{ __('hrm.set_shift_start') }} <b class="req-star">*</b></span>
                        <input type="time" name="hr_shift_start" value="{{ $val('hr_shift_start') }}" required {{ $ro }}></label>
                @endif
            </div>
        @endforeach
    </div>

    <div class="card">
        <h3>🧾 {{ __('hrm.set_tax') }}</h3>
        <div class="searchbar">
            <label class="fl"><span>{{ __('hrm.set_tax_exemption') }} <b class="req-star">*</b></span>
                <input type="number" name="hr_tax_exemption" step="0.01" min="0" value="{{ $val('hr_tax_exemption') }}" required {{ $ro }} dir="ltr"></label>
        </div>

        <h4>{{ __('hrm.set_brackets') }}</h4>
        <p style="color:var(--muted);font-size:11px;margin:0 0 6px">{{ __('hrm.set_brackets_hint') }}</p>
        <div class="tablewrap">
            <table id="bracketRows">
                <tr><th data-nosum>#</th><th>{{ __('hrm.set_from') }} *</th><th>{{ __('hrm.set_to') }}</th><th>{{ __('hrm.set_rate') }} *</th></tr>
                @foreach (array_values($bRows) as $i => $r)
                    <tr>
                        <td>{{ $i }}</td>
                        <td><input type="number" name="brackets[{{ $i }}][from]" step="0.01" min="0" value="{{ $r['from'] ?? '' }}" required {{ $ro }} dir="ltr" style="width:120px"></td>
                        <td><input type="number" name="brackets[{{ $i }}][to]" step="0.01" min="0" value="{{ $r['to'] ?? '' }}" {{ $ro }} dir="ltr" style="width:120px"></td>
                        <td><input type="number" name="brackets[{{ $i }}][rate]" step="0.0001" min="0" max="1" value="{{ $r['rate'] ?? '' }}" required {{ $ro }} dir="ltr" style="width:90px"></td>
                    </tr>
                @endforeach
            </table>
        </div>
        @if ($canEdit)
            <button type="button" class="btn sm" onclick="hrAddBracket()" data-noprint>＋ {{ __('hrm.set_add_row') }}</button>
        @endif

        <h4>{{ __('hrm.set_elimination') }}</h4>
        <p style="color:var(--muted);font-size:11px;margin:0 0 6px">{{ __('hrm.set_elimination_hint') }}</p>
        <div class="tablewrap">
            <table id="elimRows">
                <tr><th>{{ __('hrm.set_over') }} *</th><th>{{ __('hrm.set_upto') }} *</th></tr>
                @foreach (array_values($eRows) as $i => $r)
                    <tr>
                        <td><input type="number" name="elimination[{{ $i }}][over]" step="0.01" min="0" value="{{ $r['over'] ?? '' }}" required {{ $ro }} dir="ltr" style="width:120px"></td>
                        <td><input type="number" name="elimination[{{ $i }}][upto]" step="1" min="0" value="{{ $r['upto'] ?? '' }}" required {{ $ro }} dir="ltr" style="width:70px"></td>
                    </tr>
                @endforeach
            </table>
        </div>
        @if ($canEdit)
            <button type="button" class="btn sm" onclick="hrAddElim()" data-noprint>＋ {{ __('hrm.set_add_row') }}</button>
        @endif
    </div>

@if ($canEdit)
    <button class="btn green" type="submit">💾 {{ __('hrm.set_save') }}</button>
</form>
@else
</div>
@endif

@if ($canEdit)
<script>
    function hrNum(name, step, extra) {
        return '<input type="number" name="' + name + '" step="' + step + '" min="0" dir="ltr" ' + (extra || '') + '>';
    }
    function hrAddBracket() {
        const t = document.getElementById('bracketRows');
        const i = t.rows.length - 1;
        const tr = t.insertRow(-1);
        tr.innerHTML = '<td>' + i + '</td>'
            + '<td>' + hrNum('brackets[' + i + '][from]', '0.01', 'required style="width:120px"') + '</td>'
            + '<td>' + hrNum('brackets[' + i + '][to]', '0.01', 'style="width:120px"') + '</td>'
            + '<td>' + hrNum('brackets[' + i + '][rate]', '0.0001', 'max="1" required style="width:90px"') + '</td>';
    }
    function hrAddElim() {
        const t = document.getElementById('elimRows');
        const i = t.rows.length - 1;
        const tr = t.insertRow(-1);
        tr.innerHTML = '<td>' + hrNum('elimination[' + i + '][over]', '0.01', 'required style="width:120px"') + '</td>'
            + '<td>' + hrNum('elimination[' + i + '][upto]', '1', 'required style="width:70px"') + '</td>';
    }
</script>
@endif

@endsection
