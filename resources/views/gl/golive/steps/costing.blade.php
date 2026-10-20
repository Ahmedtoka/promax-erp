{{--
    ٧ — إعدادات التكاليف (الخط ٩، المهمة ٩): طريقة التقييم الرسمية، تحميل
    الكيلو الافتراضي، توزيع مصاريف الفرع، والـseed (معاينة الأول وبعدين
    تشغيل)، والأصناف التامة اللي مالهاش سعر تحويل. الكتابة أدمن بس.
--}}
@php $seed = session('costing_seed'); @endphp

<h4 style="margin:0 0 6px">{{ __('golive.s7_seed') }}</h4>
@if ($ready)
    <div class="alert good" style="margin-bottom:10px"><span>✓</span><span>{{ __('golive.s7_seed_ready') }}</span></div>
@else
    @if ($blocker)
        <div class="alert warn" style="margin-bottom:8px"><span>⚠️</span><span>{{ $blocker }}</span></div>
    @endif
    @if (is_array($seed))
        <div class="alert info" style="margin-bottom:8px"><span>🌱</span><span>
            <b>{{ $seed['dry'] ? __('golive.s7_seed_dry') : __('golive.s7_seeded') }}</b>
            <div>{{ __('golive.s7_seed_accounts') }}: <span class="num">{{ $seed['accounts_created'] }}</span></div>
            <div>{{ __('golive.s7_seed_rules') }}: <span class="num">{{ $seed['rules_created'] }}</span></div>
            @foreach ($seed['notes'] ?? [] as $line)<div style="font-size:11.5px">• {{ $line }}</div>@endforeach
        </span></div>
    @endif
    @if ($canAdmin)
        <div style="display:flex;gap:8px;margin-bottom:10px">
            <form method="POST" action="{{ route('gl.golive.costing.seed') }}">@csrf
                <input type="hidden" name="dry_run" value="1">
                <button class="btn" type="submit">🌱 {{ __('golive.s7_seed_preview') }}</button></form>
            @if (is_array($seed) && $seed['dry'])
                <form method="POST" action="{{ route('gl.golive.costing.seed') }}">@csrf
                    <input type="hidden" name="dry_run" value="0">
                    <button class="btn gold" type="submit" @disabled($blocker !== null)>🌱 {{ __('golive.s7_seed_apply') }}</button></form>
            @endif
        </div>
    @endif
@endif

<h4 style="margin:12px 0 6px">{{ __('golive.s7_settings') }}</h4>
<form method="POST" action="{{ route('gl.golive.costing.save') }}">
    @csrf
    <fieldset class="frow" style="border:0;padding:0;margin:0" @disabled(! $canAdmin)>
        <label class="fl"><span>{{ __('golive.s7_method') }}</span>
            <select name="cost_method" required>
                @foreach (\App\Services\Costing\CostingSettings::METHODS as $m)
                    <option value="{{ $m }}" @selected(old('cost_method', $values['cost_method']) === $m)>{{ __('costing.method_'.$m) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('golive.s7_kg_rate') }}</span>
            <input type="number" name="cost_default_kg_rate" step="0.0001" min="0.0001" required
                   value="{{ old('cost_default_kg_rate', $values['cost_default_kg_rate']) }}" title="{{ __('golive.s7_kg_rate_hint') }}"></label>
        <label class="fl"><span>{{ __('golive.s7_unit_kg') }}</span>
            <input type="number" name="cost_unit_kg_fallback" step="0.0001" min="0.0001" required
                   value="{{ old('cost_unit_kg_fallback', $values['cost_unit_kg_fallback']) }}"></label>
        <label class="fl"><span>{{ __('golive.s7_markup') }}</span>
            <input type="number" name="cost_markup_pct" step="0.01" min="0" max="500" required
                   value="{{ old('cost_markup_pct', $values['cost_markup_pct']) }}"></label>
        <label class="fl"><span>{{ __('golive.s7_alert') }}</span>
            <input type="number" name="cost_alert_pct" step="0.01" min="1" max="100" required
                   value="{{ old('cost_alert_pct', $values['cost_alert_pct']) }}"></label>
        <label class="fl"><span>{{ __('golive.s7_alloc') }}</span>
            <select name="cost_branch_alloc" required>
                @foreach (\App\Services\Costing\CostingSettings::ALLOC as $a)
                    <option value="{{ $a }}" @selected(old('cost_branch_alloc', $values['cost_branch_alloc']) === $a)>{{ __('costing.alloc_'.$a) }}</option>
                @endforeach
            </select></label>
        @if ($canAdmin)
            <button class="btn gold" type="submit">{{ __('golive.s7_save') }}</button>
        @endif
    </fieldset>
</form>
<p style="font-size:12px;color:var(--muted);margin:6px 0 0">
    {{ __('golive.s7_kg_rate_hint') }}
    @if ($savedAt) · {{ __('golive.s7_saved_at', ['at' => $savedAt]) }}@endif
</p>
<div class="alert info" style="margin-top:8px"><span>ℹ️</span><span>{{ __('golive.s7_method_note') }}</span></div>

<h4 style="margin:12px 0 6px">{{ __('golive.s7_no_tp_title') }}
    <a class="btn sm" href="{{ route('costing.cards') }}">🧾 {{ __('golive.s7_open_cards') }}</a></h4>
@if ($noTp->isEmpty())
    <div class="alert good"><span>✓</span><span>{{ __('golive.s7_all_tp') }}</span></div>
@else
    <div class="tablewrap" style="max-height:40vh;overflow:auto">
        <table>
            <tr><th data-nosum style="text-align:start">{{ __('costing.col_product') }}</th><th data-nosum>{{ __('costing.col_family') }}</th></tr>
            @foreach ($noTp as $p)
                <tr><td style="text-align:start">{{ $p->displayName() }}</td><td>{{ $p->familyLabel() }}</td></tr>
            @endforeach
        </table>
    </div>
@endif
