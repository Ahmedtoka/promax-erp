{{--
    ═══ فلتر المناطق — السينك وجاهزة للشحن (٢٥/٩) ═══
    $af = ناتج OnlineOrderController::areaFilter · $shown = عدد الصفوف المعروضة

    المحافظة بتقصّ ليستة المناطق تحتها (بتبعت الفورم فوراً)، والمناطق
    شيبس اختيار متعدد بعدّادها — عشان «التجمع الخامس» و«القاهرة الجديدة»
    و«New Cairo» يتعلّموا مع بعض.
--}}
<form method="GET" class="searchbar" style="margin:0 0 12px;flex-wrap:wrap;gap:8px 10px">
    <label class="fl"><span>{{ __('online.f_gov') }}</span>
        <select name="gov" onchange="this.form.querySelectorAll('.af-chip input').forEach(function (c) { c.checked = false; }); this.form.submit()">
            <option value="">{{ __('online.f_gov_all') }} ({{ $af['total'] }})</option>
            @foreach ($af['govs'] as $g => $n)
                <option value="{{ $g }}" @selected($af['gov'] === (string) $g)>{{ $g !== $af['none'] ? $g : __('online.f_no_area') }} ({{ $n }})</option>
            @endforeach
        </select></label>
    <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
        <input type="text" name="q" value="{{ $af['q'] }}" placeholder="🔎 {{ __('online.f_q') }}"></label>
    <button class="btn sm" type="submit">{{ __('common.filter') }}</button>
    @if ($af['active'])
        <a class="btn sm" href="{{ url()->current() }}">✖ {{ __('common.clear') }}</a>
        <span class="badge b-blue" style="align-self:center">{{ __('online.f_showing', ['n' => $shown, 'total' => $af['total']]) }}</span>
    @endif

    @if (count($af['options']) > 1 || $af['areas'] !== [])
        <details style="flex:1 1 100%" @if ($af['areas'] !== []) open @endif>
            <summary style="cursor:pointer;font-size:12px;font-weight:800;color:var(--muted)">
                📍 {{ __('online.f_areas') }}
                @if ($af['areas'] !== [])
                    <span class="badge b-blue">{{ __('online.f_areas_n', ['n' => count($af['areas'])]) }}</span>
                @endif
            </summary>
            <div class="dash-hint" style="margin:6px 0">{{ __('online.f_hint') }}</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
                @foreach ($af['options'] as $opt)
                    <label class="af-chip">
                        <input type="checkbox" name="areas[]" value="{{ $opt['value'] }}"
                               @checked(in_array($opt['value'], $af['areas'], true))>
                        <span>{{ $opt['area'] !== '' ? $opt['area'] : __('online.f_no_area') }} <b>{{ $opt['n'] }}</b></span>
                    </label>
                @endforeach
            </div>
            <button class="btn sm gold" type="submit" style="margin-top:8px">{{ __('common.filter') }}</button>
        </details>
    @endif
</form>

<style>
.af-chip{display:inline-flex;align-items:center;cursor:pointer}
.af-chip input{display:none}
.af-chip span{font-size:12px;padding:5px 11px;border-radius:99px;border:1px solid var(--border);background:var(--card2);white-space:nowrap}
.af-chip span b{color:var(--muted);margin-inline-start:4px}
.af-chip input:checked+span{background:var(--royal-blue);border-color:var(--royal-blue);color:#fff}
.af-chip input:checked+span b{color:#fff}
</style>
