@extends('layouts.system')

{{-- إعدادات الحوافز: شرايح العمولة + قيم النقاط + نطاق الليد (2026-08-06) — أدمن بس. --}}

@section('title', __('incent.settings_title'))

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

<form method="POST" action="{{ route('erp.incentives.save') }}">
    @csrf

    <div class="card">
        <h3>🏅 {{ __('incent.settings_title') }}
            <span class="side">{{ __('incent.settings_hint') }}</span></h3>
        <div class="frow">
            <div>
                <label class="f">{{ __('incent.point_value') }}</label>
                <input type="number" name="point_value" required min="0" step="0.5" dir="ltr"
                       value="{{ $values['point_value'] }}" style="width:100%;text-align:center;font-weight:800">
            </div>
            <div>
                <label class="f">{{ __('incent.pts_per_visit') }}</label>
                <input type="number" name="pts_per_visit" required min="0" step="1" dir="ltr"
                       value="{{ $values['pts_per_visit'] }}" style="width:100%;text-align:center">
            </div>
            <div>
                <label class="f">{{ __('incent.pts_per_new_client') }}</label>
                <input type="number" name="pts_per_new_client" required min="0" step="1" dir="ltr"
                       value="{{ $values['pts_per_new_client'] }}" style="width:100%;text-align:center">
            </div>
            <div>
                <label class="f">{{ __('incent.pts_per_100_pieces') }}</label>
                <input type="number" name="pts_per_100_pieces" required min="0" step="1" dir="ltr"
                       value="{{ $values['pts_per_100_pieces'] }}" style="width:100%;text-align:center">
            </div>
            {{-- ═══ عناوين العملاء المتأكّدة — ١٧ أغسطس ٢٠٢٦ ═══
                 «مع كل ٥ عناوين تأكيد ياخد نقطة».
                 ⚠️ **العدّ على التأكيد مش على الإرسال** — وإلا المندوب
                 بياخد نقط على ضغطة زرار من غير ما يتحرك من مكانه. --}}
            <div>
                <label class="f">{{ __('incent.locations_per_point') }}</label>
                <input type="number" name="locations_per_point" required min="1" max="1000" step="1" dir="ltr"
                       value="{{ $values['locations_per_point'] }}" style="width:100%;text-align:center">
            </div>
            <div>
                <label class="f">{{ __('incent.pts_per_locations') }}</label>
                <input type="number" name="pts_per_locations" required min="0" step="1" dir="ltr"
                       value="{{ $values['pts_per_locations'] }}" style="width:100%;text-align:center">
            </div>
            <div>
                <label class="f">{{ __('incent.lead_alert_km') }}</label>
                <input type="number" name="lead_alert_km" required min="0.1" max="20" step="0.1" dir="ltr"
                       value="{{ $values['lead_alert_km'] }}" style="width:100%;text-align:center">
            </div>
        </div>
    </div>

    <div class="card">
        <h3>💵 {{ __('incent.tiers') }}
            <span class="side">{{ __('incent.tiers_hint') }}</span></h3>
        <div class="tablewrap" style="max-width:480px">
            <table id="tiersTbl">
                <tr>
                    <th style="text-align:center">{{ __('incent.tier_min') }}</th>
                    <th style="text-align:center">{{ __('incent.tier_rate') }}</th>
                    <th style="width:40px"></th>
                </tr>
                @foreach ($tiers as $i => $t)
                    <tr>
                        <td><input type="number" name="tiers[{{ $i }}][min_pct]" required min="0" max="1000" step="1" dir="ltr"
                                   value="{{ (float) $t->min_pct }}" style="width:100%;text-align:center;font-weight:800"></td>
                        {{-- النسبة مئوية في الشاشة — القسمة على 100 في الكنترولر مرة واحدة --}}
                        <td><input type="number" name="tiers[{{ $i }}][rate]" required min="0" max="100" step="0.05" dir="ltr"
                                   value="{{ (float) $t->rate * 100 }}" style="width:100%;text-align:center"></td>
                        <td><button type="button" class="btn sm" onclick="this.closest('tr').remove()">✕</button></td>
                    </tr>
                @endforeach
            </table>
        </div>
        <div style="margin-top:10px">
            <button type="button" class="btn" onclick="tierAdd()">+ {{ __('common.add') }}</button>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end">
        <button class="btn gold" type="submit">💾 {{ __('common.save') }}</button>
    </div>
</form>

@endsection

@section('scripts')
<script>
let tierIdx = 1000;

function tierAdd() {
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="number" name="tiers[' + tierIdx + '][min_pct]" required min="0" max="1000" step="1" dir="ltr" style="width:100%;text-align:center;font-weight:800"></td>' +
        '<td><input type="number" name="tiers[' + tierIdx + '][rate]" required min="0" max="100" step="0.05" dir="ltr" style="width:100%;text-align:center"></td>' +
        '<td><button type="button" class="btn sm" onclick="this.closest(\'tr\').remove()">✕</button></td>';
    document.getElementById('tiersTbl').appendChild(tr);
    tierIdx++;
}
</script>
@endsection
