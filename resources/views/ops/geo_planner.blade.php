@extends('layouts.system')

@section('title', __('journey.geo_page'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $me = auth()->user();

    // ⚠️ **`Access::action` مش `Access::allows`** — كتابة خط السير
    // زرار حساس تحت `act.field.plan` زي شاشة التسكين بالظبط.
    // `allows()` بتقرا استثناء **الصفحة** الأول (خطوة ٢ في
    // `userOverride`) وبتخلص قبل ما توصل لاستثناء الأكشن (خطوة ٣)،
    // فمنح `ops.geo` كصفحة كان بيدي زراير الحفظ لواحد الأدمن منع
    // عنه `act.field.plan` صراحةً.
    $canPlan = \App\Support\Access::action($me, 'act.field.plan');
    $canUnplan = $canPlan;

    // ⚠️ التوزيع تحت **نفس** الأكشن — «مين بيزور مين» و«مين مسؤول عن
    // مين» قرار واحد، وفصلهم كان معناه إن الأدمن يمنع زرار في شاشة
    // ويسيب التاني مفتوح من غير ما ياخد باله.
    $canAssign = $canPlan;

    $dayOpts = [];
    foreach (\App\Models\JourneyPlan::WEEKDAYS as $w) {
        $dayOpts[] = ['v' => $w, 't' => __('journey.day_'.$w)];
    }

    $freqOpts = [];
    foreach (\App\Models\JourneyPlan::FREQUENCIES as $f) {
        $freqOpts[] = ['v' => $f, 't' => __('journey.freq_'.$f)];
    }

    // لينك الشاشة نفسها بكل الفلاتر الشغّالة — أي زرار بيغيّر فلتر
    // واحد بس ويسيب الباقي زي ما هو (`null` بيشيل الفلتر).
    $geoLink = fn (array $extra = []) => route('ops.geo', array_filter(array_merge([
        'manager' => $picked?->id,
        'q' => $filters['q'],
        'norep' => $filters['norep'] ? 1 : null,
    ], $extra)));

    // ⚠️ حمولة واحدة بـ json_encode هنا — دايركتيف الجيسون بمصفوفة
    // متعددة السطور بيكسّر بارسر بليد، والفلاجات بتمنع أي وسم أو
    // كوتيشن يتسرّب جوه بلوك السكربت.
    $geoJs = json_encode([
        'zoneUrl' => route('ops.geo.zone', ['zone' => '__ZID__']),
        'planUrl' => route('ops.geo.plan'),
        'unplanUrl' => route('ops.geo.unplan'),
        'distUrl' => route('ops.geo.distribute'),
        'assignUrl' => route('ops.geo.assign'),
        'manager' => $picked?->id,
        'managerName' => $picked?->displayName(),
        // ⚠️ نفس فلتر البحث بيتبعت للوحة — الأعداد في الشجرة مفلترة
        // بيه، ولوحة مش مفلترة بتخلّي «اختيار الكل» يخطّط لمحلات
        // اللي بيبص مشافهاش
        'q' => $filters['q'],
        'norep' => (bool) $filters['norep'],
        'canPlan' => $canPlan,
        'canUnplan' => $canUnplan,
        'canAssign' => $canAssign,
        'days' => $dayOpts,
        'freqs' => $freqOpts,
        'next' => $nextDue,
        't' => [
            'loading' => __('common.loading'),
            'fail' => __('common.something_wrong'),
            'saving' => __('common.loading'),
            'none' => __('journey.geo_none_picked'),
            'empty' => __('journey.geo_zone_empty'),
            'notPlanned' => __('journey.geo_not_planned'),
            'noReps' => __('journey.geo_no_reps_for'),
            'noLoc' => __('journey.geo_no_location_badge'),
            'remove' => __('journey.geo_remove'),
            'removeConfirm' => __('journey.geo_remove_confirm'),
            'firstVisit' => __('journey.geo_first_visit'),
            'foreign' => __('journey.geo_plan_other_team'),
            'client' => __('journey.geo_client'),
            'code' => __('journey.geo_code'),
            'category' => __('journey.geo_category'),
            'status' => __('journey.geo_status'),
            'rep' => __('journey.geo_rep'),
            'day' => __('journey.geo_day'),
            'freq' => __('journey.geo_freq'),
            'include' => __('journey.geo_include'),
            'date' => __('journey.geo_date'),
            'time' => __('journey.geo_time'),
            'dateHint' => __('journey.geo_date_hint'),
            'govLine' => __('journey.geo_gov_line', [
                'gov' => '__G__', 'govn' => '__GN__', 'zones' => '__GZ__', 'zonen' => '__ZN__',
            ]),
            'distTitle' => __('journey.geo_dist_title', ['name' => '__N__']),
            'distHint' => __('journey.geo_dist_hint'),
            'distZone' => __('journey.geo_dist_zone'),
            'distSuggested' => __('journey.geo_dist_suggested'),
            'distCurrent' => __('journey.geo_dist_current'),
            'distAdded' => __('journey.geo_dist_added'),
            'distNone' => __('journey.geo_dist_none'),
            'distEmpty' => __('journey.geo_dist_empty'),
            'distNoReps' => __('journey.geo_dist_no_reps'),
            'distBlocked' => __('journey.geo_dist_blocked'),
            'distSkip' => __('journey.geo_dist_skip'),
            'norepTitle' => __('journey.geo_norep_title'),
            'norepHint' => __('journey.geo_norep_hint'),
            'norepEmpty' => __('journey.geo_norep_empty'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.assignments') }}">👥 {{ __('nav.assignments') }}</a>
    <a class="btn" href="{{ route('ops.journeys') }}">🗺️ {{ __('journey.page') }}</a>
@endsection

@section('content')

{{-- رسالة نجاح الحفظ بعد الريلود — بتتخزن في sessionStorage قبل
     ما الصفحة تتحمّل من أول، لأن الحفظ AJAX مفيهوش flash سيرفر. --}}
<div class="alert good" id="geoFlash" style="display:none"></div>

{{-- ═══════════════════════ شريط الأرقام ═══════════════════════ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('journey.geo_kpi_clients') }}</div>
        <div class="val num">{{ $fmt($total) }}</div>
        <div class="sub2">{{ $picked?->displayName() ?? __('journey.geo_all_teams') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.geo_kpi_govs') }}</div>
        <div class="val num">{{ $fmt($govCount) }}</div>
        <div class="sub2">{{ __('journey.geo_kpi_zones') }}: {{ $fmt($zoneCount) }}</div>
    </div>
    <div class="kpi {{ $noPlanTotal > 0 ? 'mid' : 'pos' }}">
        <div class="lbl">{{ __('journey.geo_kpi_noplan') }}</div>
        <div class="val num">{{ $fmt($noPlanTotal) }}</div>
        <div class="sub2">{{ __('journey.geo_unplanned') }}</div>
    </div>
    <div class="kpi {{ $noRepTotal > 0 ? 'neg' : 'pos' }}">
        <div class="lbl">{{ __('journey.geo_kpi_norep') }}</div>
        <div class="val num">{{ $fmt($noRepTotal) }}</div>
        <div class="sub2">
            {{-- ⚠️ الفلتر الجغرافي الأول والتسكين تاني — الترتيب ده
                 مقصود: المالك بيدوس على الرقم عشان يشوف **فين** الناقص
                 مش عشان يفتح شاشة تانية. --}}
            @if ($filters['norep'])
                <a href="{{ $geoLink(['norep' => null]) }}">{{ __('journey.geo_norep_clear') }}</a>
            @else
                <a href="{{ $geoLink(['norep' => 1]) }}">{{ __('journey.geo_norep_filter') }}</a>
            @endif
            · <a href="{{ route('ops.assignments', ['only' => 'orphans']) }}">{{ __('journey.geo_fix_rep') }}</a>
        </div>
    </div>
    <div class="kpi {{ $noLocTotal > 0 ? 'mid' : 'pos' }}">
        <div class="lbl">{{ __('journey.geo_kpi_noloc') }}</div>
        <div class="val num">{{ $fmt($noLocTotal) }}</div>
        <div class="sub2"><a href="{{ route('erp.client_locations', ['show' => 'no_location']) }}">{{ __('journey.geo_fix_loc') }}</a></div>
    </div>
</div>

{{-- ═══════════════════════ الفلاتر الشغّالة ═══════════════════════ --}}
@if ($picked !== null)
    <div class="alert warn">
        {{ __('journey.geo_filtered_on', ['name' => $picked->displayName()]) }}
        — <a href="{{ $geoLink(['manager' => null]) }}">{{ __('journey.geo_clear_filter') }}</a>
    </div>
@endif

@if ($filters['norep'])
    <div class="alert warn">
        ⚠️ {{ __('journey.geo_norep_on') }}
        — <a href="{{ $geoLink(['norep' => null]) }}">{{ __('journey.geo_norep_clear') }}</a>
    </div>
@endif

{{-- ═══════════════════════ فرق المديرين ═══════════════════════
     ⚠️ **تاب كامل بعرض الشاشة لكل مدير** (طلب المالك ١٣/٨):
     «اعمل عمرو لوحده بكل الناس بتاعته في تاب كامل بعرض الشاشة
     وتحتيه محمد حجر». الكروت جنب بعض كانت بتزنق الفريق في عمود
     ضيق، وفصل المناديب عن السواقين مايبانش فيه أصلاً.

     ⚠️ **والكارت مابقاش لينك** — دي كانت جذر باج «بدون مندوب»:
     الشيب كان جوه `<a>` بتاع الكارت، فالدوسة عليه كانت بتفلتر على
     المدير وبس والشاشة تفتح مليانة مناديب في عمود «بيغطيها». كل
     أكشن بقى زرار مستقل بوظيفة واضحة. --}}
@if ($teams === [])
    <div class="card">
        <h3>👔 {{ __('journey.geo_teams') }}</h3>
        <div class="alert warn">{{ __('journey.geo_no_teams') }}</div>
    </div>
@else
    <div class="alert info">{{ __('journey.geo_panel_hint') }}</div>

    @foreach ($teams as $t)
        @php $isOn = $picked && $picked->id === $t['manager']->id; @endphp
        <div class="card geo-mgr {{ $isOn ? 'on' : '' }}">
            <div class="geo-mgr-head">
                @include('partials._avatar', ['u' => $t['manager'], 'size' => 52, 'ring' => '#602D90'])
                <div style="min-width:0;flex:1">
                    <b style="display:block;font-size:16px">{{ $t['manager']->displayName() }}</b>
                    <span class="s" style="color:var(--muted)">{{ $t['manager']->roleLabel() }}</span>
                </div>

                <div class="geo-mgr-kpis">
                    <span class="badge b-green">🏪 {{ $fmt($t['clients']) }} {{ __('journey.geo_team_clients') }}</span>
                    <span class="badge b-blue">🗺️ {{ $fmt($t['govs']) }} {{ __('journey.geo_team_govs') }}</span>
                    <span class="badge b-purple">📍 {{ $fmt($t['zones']) }} {{ __('journey.geo_team_zones') }}</span>
                    @if ($t['own'] > 0)
                        <span class="badge b-gold">👔 {{ $fmt($t['own']) }} {{ __('journey.geo_manager_own') }}</span>
                    @endif
                </div>

                <div class="geo-mgr-n num">{{ $fmt($t['clients']) }}</div>
            </div>

            {{-- ═══ الفريق مفصول بالرول ═══ --}}
            @if ($t['groups'] === [])
                <div class="alert warn">{{ __('journey.geo_no_members') }}</div>
            @else
                @foreach ($t['groups'] as $g)
                    <div class="geo-rolerow">
                        <div class="geo-rolelbl">
                            {{ $g['label'] }}
                            <span class="badge b-gray">{{ count($g['reps']) }}</span>
                        </div>
                        <div class="geo-chips">
                            @foreach ($g['reps'] as $tr)
                                <span class="geo-chip">
                                    @include('partials._avatar', ['u' => $tr['user'], 'size' => 24])
                                    <span>{{ $tr['user']->displayName() }}</span>
                                    <b class="num">{{ $fmt($tr['clients']) }}</b>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif

            {{-- ═══ الأكشنز ═══ --}}
            <div class="geo-mgr-acts">
                @if ($isOn)
                    <span class="badge b-blue">✓ {{ __('journey.geo_viewing_geo') }}</span>
                    <a class="btn sm" href="{{ $geoLink(['manager' => null]) }}">{{ __('journey.geo_clear_filter') }}</a>
                @else
                    <a class="btn sm gold" href="{{ $geoLink(['manager' => $t['manager']->id]) }}">
                        🧭 {{ __('journey.geo_view_geo') }}
                    </a>
                @endif

                {{-- ⚠️ الشيب زرار حقيقي دلوقتي: بيفتح **قايمة المحلات
                     اللي بلا مسؤول** مجمّعة بالمنطقة بسيلكت مندوب في
                     كل صف — مش بيفلتر على المدير ويسيب اللي بيبص
                     يدوّر. والفلترة الجغرافية لينك جنبه بالنص. --}}
                @if ($t['no_rep'] > 0)
                    @if ($canAssign)
                        <button type="button" class="btn sm warn-btn"
                                data-mid="{{ $t['manager']->id }}"
                                data-mname="{{ $t['manager']->displayName() }}"
                                onclick="geoOpenNoRep(this)">
                            ⚠️ {{ __('journey.no_rep') }}
                            <b class="num">{{ $fmt($t['no_rep']) }}</b>
                        </button>
                    @else
                        <span class="badge b-orange">⚠️ {{ __('journey.no_rep') }} {{ $fmt($t['no_rep']) }}</span>
                    @endif

                    <a class="btn sm" href="{{ $geoLink(['manager' => $t['manager']->id, 'norep' => 1]) }}">
                        🔎 {{ __('journey.geo_norep_filter') }}
                    </a>

                    @if ($canAssign && $t['sales_count'] > 0)
                        <button type="button" class="btn sm gold"
                                data-mid="{{ $t['manager']->id }}"
                                data-mname="{{ $t['manager']->displayName() }}"
                                onclick="geoOpenDist(this)">
                            ⚡ {{ __('journey.geo_dist_btn') }}
                        </button>
                    @elseif ($canAssign)
                        <span class="s" style="color:var(--muted)">{{ __('journey.geo_dist_no_reps') }}</span>
                    @endif
                @endif
            </div>
        </div>
    @endforeach
@endif

{{-- ═══════════════════════ الشجرة الجغرافية ═══════════════════════ --}}
<div class="card">
    <h3>🧭 {{ __('journey.geo_tree') }} <span class="side">{{ $fmt($total) }} {{ __('journey.geo_shops') }}</span></h3>
    <div class="alert info">{{ __('journey.geo_tree_hint') }}</div>

    {{-- ⚠️ فورم GET عشان Enter يبعت البحث للسيرفر (اسم/كود المحل
         مش موجود في الشجرة أصلاً)، و oninput بيفلتر المحافظات
         والمناطق المعروضة فوراً من غير رحلة للسيرفر. --}}
    <form method="GET" action="{{ route('ops.geo') }}" class="searchbar">
        @if ($picked !== null)
            <input type="hidden" name="manager" value="{{ $picked->id }}">
        @endif
        @if ($filters['norep'])
            <input type="hidden" name="norep" value="1">
        @endif
        <div style="flex:1;min-width:260px">
            <label class="f">{{ __('common.search') }}</label>
            <input type="search" name="q" value="{{ $filters['q'] }}" style="width:100%"
                   placeholder="{{ __('journey.geo_search_ph') }}" oninput="geoTreeFilter(this.value)">
        </div>
        <button class="btn gold" type="submit">🔍 {{ __('common.search') }}</button>
        @if ($filters['q'] !== '')
            <a class="btn" href="{{ $geoLink(['q' => null]) }}">{{ __('common.clear') }}</a>
        @endif
    </form>

    @if ($tree === [])
        <div class="alert warn">
            {{ $filters['norep'] ? __('journey.geo_norep_empty') : __('journey.geo_empty') }}
        </div>
    @else
        <div class="geo-tree">
            @foreach ($tree as $g)
                @php $pct = $total > 0 ? round($g['clients'] / $total * 100, 1) : 0; @endphp
                <details class="geo-gov" data-txt="{{ mb_strtolower($g['label'].' '.$g['key']) }}">
                    <summary>
                        <span class="zg-plus" aria-hidden="true"></span>
                        <span class="geo-gname">{{ $g['label'] }}</span>
                        <span class="badge b-green">🏪 {{ $fmt($g['clients']) }}</span>
                        <span class="badge b-gray">📍 {{ count($g['zones']) }} {{ __('journey.geo_zones_n') }}</span>
                        @if ($g['no_plan'] > 0)
                            <span class="badge b-orange">🕳️ {{ $fmt($g['no_plan']) }} {{ __('journey.geo_unplanned') }}</span>
                        @endif
                        <span class="geo-bar" title="{{ __('journey.geo_share') }}"><i style="width:{{ $pct }}%"></i></span>
                        <span class="s num geo-pct">{{ $pct }}%</span>
                    </summary>

                    {{-- ⚠️ `data-plain`: أدوات الجداول العامة (بحث/ترتيب/
                         صف إجماليات) بتتطبق على كل `.tablewrap`، وهنا
                         الجدول جوه أكورديون محافظة — يعني خانة بحث
                         وصف إجماليات مكررين لكل محافظة. البحث موحّد
                         فوق، والإجماليات في رأس المحافظة نفسه. --}}
                    <div class="tablewrap">
                        <table data-plain>
                            <tr>
                                <th>{{ __('geo.zone') }}</th>
                                <th data-nosum>{{ __('journey.geo_shops') }}</th>
                                <th data-nosum>{{ __('journey.geo_unplanned') }}</th>
                                <th data-nosum>{{ __('journey.geo_covered_by') }}</th>
                                <th data-nosum></th>
                            </tr>
                            @foreach ($g['zones'] as $z)
                                <tr class="geo-zrow" data-txt="{{ mb_strtolower($z['name'].' '.($z['code'] ?? '')) }}">
                                    <td>
                                        <b>{{ $z['name'] }}</b>
                                        @if ($z['code'])
                                            <span class="s" style="color:var(--muted)">{{ $z['code'] }}</span>
                                        @endif
                                        {{-- المحافظة جنب المنطقة في الشجرة كمان —
                                             الصف بيتقري لوحده لما الأكورديون مفتوح --}}
                                        <div class="s" style="color:var(--muted)">{{ $g['label'] }}</div>
                                        @if ($z['id'] === null)
                                            <div class="s" style="color:var(--orange,#EA8C1C)">{{ __('journey.geo_no_zone_hint') }}</div>
                                        @endif
                                    </td>
                                    <td class="num"><b>{{ $fmt($z['clients']) }}</b></td>
                                    <td class="num">
                                        @if ($z['no_plan'] > 0)
                                            <span class="badge b-orange">{{ $fmt($z['no_plan']) }}</span>
                                        @else
                                            <span class="badge b-green">✓</span>
                                        @endif
                                    </td>
                                    <td>
                                        @forelse ($z['reps'] as $zr)
                                            <span class="geo-chip">
                                                @include('partials._avatar', ['u' => $zr['user'], 'size' => 22])
                                                <span>{{ $zr['user']->displayName() }}</span>
                                                <b class="num">{{ $fmt($zr['n']) }}</b>
                                            </span>
                                        @empty
                                            <span class="badge b-gray">{{ __('journey.no_rep') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="num">
                                        @if ($z['id'] !== null)
                                            <button type="button" class="btn sm gold"
                                                    onclick="geoOpenZone({{ $z['id'] }}, this.dataset.zn)"
                                                    data-zn="{{ $z['name'] }}">
                                                🧭 {{ __('journey.geo_plan_btn') }}
                                            </button>
                                        @else
                                            <span class="s" style="color:var(--muted)">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</div>

{{-- ═══════════════════════ لوحة المنطقة ═══════════════════════ --}}
<dialog id="zoneDlg" class="geo-dlg">
    <div class="dlg">
        {{-- المحافظة › المنطقة — طلب المالك: «في الشاشة الثانية عاوز
             المحافظة والمناطق» --}}
        <h4>🧭 <span id="zoneGov" class="geo-crumb">—</span>
            <span class="geo-crumb-sep">›</span>
            <span id="zoneTitle">—</span>
            <span class="side s" id="zoneSub"></span></h4>

        <div class="alert info" id="zoneGovLine" style="display:none"></div>
        <div class="alert" id="zoneMsg" style="display:none"></div>

        <div id="zoneBody" style="max-height:56vh;overflow:auto"></div>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px">
            @if ($canPlan)
                <label style="font-size:12.5px;display:inline-flex;align-items:center;gap:7px">
                    <input type="checkbox" id="zonePickAll" onchange="geoPickAll(this)">
                    {{ __('journey.geo_select_all') }}
                </label>
            @endif
            @if ($canAssign)
                <button class="btn sm" type="button" id="zoneDistBtn" onclick="geoDistZone()">
                    ⚡ {{ __('journey.geo_dist_zone_btn') }}
                </button>
            @endif
            <div style="margin-inline-start:auto;display:flex;gap:8px">
                <button class="btn" type="button" onclick="closeDlg('zoneDlg')">{{ __('common.close') }}</button>
                @if ($canPlan)
                    <button class="btn gold" type="button" id="zoneSave" onclick="geoSaveRoute()">
                        💾 {{ __('journey.geo_save') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</dialog>

{{-- ═══════════════════════ لوحة التوزيع ═══════════════════════
     نفس اللوحة بتخدم الحالتين: «توزيع تلقائي» (باقتراح) و«بدون
     مندوب» (قايمة يدوية بلا اقتراح). لوحتين منفصلتين كان معناه
     نسختين من نفس الجدول ونفس الحفظ. --}}
<dialog id="distDlg" class="geo-dlg">
    <div class="dlg">
        <h4>⚡ <span id="distTitle">—</span> <span class="side s" id="distSub"></span></h4>

        <div class="alert info" id="distHint"></div>
        <div class="alert" id="distMsg" style="display:none"></div>

        <div id="distReps" class="geo-chips" style="margin-bottom:10px"></div>

        <div id="distBody" style="max-height:52vh;overflow:auto"></div>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px">
            <label style="font-size:12.5px;display:inline-flex;align-items:center;gap:7px">
                <input type="checkbox" id="distDrivers" onchange="geoDistReload()">
                {{ __('journey.geo_dist_drivers') }}
            </label>
            <div style="margin-inline-start:auto;display:flex;gap:8px">
                <button class="btn" type="button" onclick="closeDlg('distDlg')">{{ __('common.close') }}</button>
                <button class="btn gold" type="button" id="distSave" onclick="geoDistSave()">
                    ✅ {{ __('journey.geo_dist_confirm') }}
                </button>
            </div>
        </div>
    </div>
</dialog>

@endsection

@section('scripts')
<style>
    /* ═══ تاب المدير — عرض الشاشة كامل ═══ */
    .geo-mgr.on{border-color:var(--royal-blue);box-shadow:0 0 0 2px rgba(18,57,155,.14)}
    .geo-mgr-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;
      padding-bottom:10px;border-bottom:1px solid var(--border);margin-bottom:10px}
    .geo-mgr-kpis{display:flex;gap:6px;flex-wrap:wrap}
    .geo-mgr-n{font-weight:900;font-size:26px;color:var(--royal-blue);margin-inline-start:8px}
    .geo-mgr-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center;
      margin-top:12px;padding-top:10px;border-top:1px solid var(--border)}
    .geo-mgr-acts .warn-btn{border-color:#EA8C1C;color:#EA8C1C}

    /* صف الرول — ليبل ثابت العرض وجنبه شيبس ناسه */
    .geo-rolerow{display:flex;gap:10px;align-items:flex-start;padding:7px 0;
      border-bottom:1px dashed var(--border)}
    .geo-rolerow:last-child{border-bottom:none}
    .geo-rolelbl{min-width:130px;font-size:12.5px;font-weight:800;color:var(--purple-heart,#602D90);
      display:flex;align-items:center;gap:6px;flex-shrink:0;padding-top:2px}
    .geo-chips{display:flex;gap:6px;flex-wrap:wrap}
    .geo-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);
      border-radius:20px;padding:2px 9px 2px 3px;font-size:12px;background:var(--card2)}
    [dir=rtl] .geo-chip{padding:2px 3px 2px 9px}
    .geo-chip b{color:var(--royal-blue)}

    /* ═══ الشجرة — نفس أسلوب أكورديون المناطق في شاشة التسكين ═══ */
    .geo-gov{border-bottom:1px solid var(--border)}
    .geo-gov:last-child{border-bottom:none}
    .geo-gov>summary{display:flex;align-items:center;gap:8px;padding:9px 3px;font-size:13px;
      font-weight:900;color:var(--royal-blue);cursor:pointer;list-style:none;user-select:none;border-radius:7px}
    .geo-gov>summary::-webkit-details-marker{display:none}
    .geo-gov>summary:hover{background:var(--card2)}
    .geo-gname{min-width:120px}
    .zg-plus{width:18px;height:18px;flex-shrink:0;display:grid;place-items:center;
      border:1px solid var(--border);border-radius:6px;background:var(--card);font-size:13px;line-height:1}
    .zg-plus::before{content:'+'}
    .geo-gov[open] .zg-plus::before{content:'-'}
    .geo-bar{margin-inline-start:auto;width:120px;height:8px;border-radius:6px;
      background:var(--card2);overflow:hidden;flex-shrink:0}
    .geo-bar>i{display:block;height:100%;background:linear-gradient(135deg,#12399B 0%,#602D90 100%)}
    .geo-pct{width:48px;text-align:end;color:var(--muted);font-weight:700}

    /* ═══ اللوحات ═══
       ⚠️ العرض على الابن المباشر مش على الـdialog — القاعدة العامة
       `dialog>div{width:min(620px,92vw)}` بتغلب أي عرض على الغلاف،
       واللوحة فيها أعمدة كتير مالهاش تتزنق في 620px. */
    .geo-dlg>.dlg{width:min(1320px,97vw)}
    .geo-dlg select{min-width:112px}
    .geo-dlg input[type=date]{min-width:132px}
    .geo-dlg input[type=time]{min-width:104px}
    .geo-next{font-size:11.5px;color:var(--muted);white-space:nowrap;display:block}
    .geo-crumb{color:var(--purple-heart,#602D90)}
    .geo-crumb-sep{color:var(--muted);margin:0 4px}
    .geo-zgrp{background:var(--card2);font-weight:900;color:var(--royal-blue)}
</style>
<script>
(function () {
    'use strict';

    var GEO = {!! $geoJs !!};

    var body = document.getElementById('zoneBody');
    var titleEl = document.getElementById('zoneTitle');
    var govEl = document.getElementById('zoneGov');
    var govLineEl = document.getElementById('zoneGovLine');
    var subEl = document.getElementById('zoneSub');
    var msgEl = document.getElementById('zoneMsg');
    var saveBtn = document.getElementById('zoneSave');
    var distBtn = document.getElementById('zoneDistBtn');

    var rows = [];       // حالة صفوف لوحة المنطقة
    var openZoneId = null;

    function say(text, kind) {
        msgEl.className = 'alert' + (kind ? ' ' + kind : '');
        msgEl.textContent = text || '';
        msgEl.style.display = text ? '' : 'none';
    }

    function el(tag, cls, txt) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (txt !== undefined && txt !== null) { e.textContent = txt; }
        return e;
    }

    /* ⚠️ `data-nosearch` عن قصد: مُحسّن السيلكت العام بيشتغل مرة واحدة
       على DOMContentLoaded، والسيلكتات دي بتتولد بعد الفتح — فكانت
       هتفضل خام جنب سيلكتات متحسّنة والشكل يتفرّق. وكمان لوحة بحث
       عايمة جوه جدول بيتسكرول تجربة وحشة. */
    function mkSelect(opts, value) {
        var s = document.createElement('select');
        s.dataset.nosearch = '1';
        opts.forEach(function (o) {
            var op = document.createElement('option');
            op.value = o.v;
            op.textContent = o.t;
            if (String(o.v) === String(value)) { op.selected = true; }
            s.appendChild(op);
        });
        return s;
    }

    function nextLabel(day, freq) {
        return GEO.next[day + '-' + freq] || '-';
    }

    /* يوم الأسبوع من تاريخ `Y-m-d` — نفس ترقيم `Carbon::dayOfWeek`
       (0 = الأحد). ⚠️ `T00:00:00` مقصودة: `new Date('2026-08-13')`
       بتتقري UTC وبتزحف يوم كامل في التوقيتات السالبة. */
    function weekdayOf(value) {
        if (!value) { return null; }
        var d = new Date(value + 'T00:00:00');
        return isNaN(d.getTime()) ? null : d.getDay();
    }

    function render(data) {
        rows = [];
        body.innerHTML = '';

        // ⚠️ تصفير «اختيار الكل» مع كل فتح — من غيره اللوحة الجديدة
        // بتفتح والمربع متعلّم وكل الصفوف فاضية، فالمستخدم لازم
        // يدوس مرتين عشان يعلّم
        var all = document.getElementById('zonePickAll');
        if (all) { all.checked = false; }

        govEl.textContent = data.zone.governorate;
        subEl.textContent = data.zone.code + ' · ' + data.clients.length;

        govLineEl.textContent = GEO.t.govLine
            .replace('__G__', data.zone.governorate)
            .replace('__GN__', data.zone.gov_clients)
            .replace('__GZ__', data.zone.gov_zones)
            .replace('__ZN__', data.zone.zone_clients);
        govLineEl.style.display = '';

        if (distBtn) {
            // التوزيع محتاج مدير — اللوحة ممكن تتفتح بلا فلتر فريق
            distBtn.disabled = !GEO.manager;
            distBtn.title = GEO.manager ? '' : {!! json_encode(__('journey.geo_dist_pick_manager'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
        }

        if (!data.clients.length) {
            body.appendChild(el('div', 'alert warn', GEO.t.empty));
            return;
        }

        var wrap = el('div', 'tablewrap');
        var table = el('table');
        var head = document.createElement('tr');
        var cols = [];

        if (GEO.canPlan) { cols.push(GEO.t.include); }
        cols.push(GEO.t.client, GEO.t.category, GEO.t.status);
        if (GEO.canPlan) {
            cols.push(GEO.t.rep, GEO.t.date, GEO.t.day, GEO.t.freq, GEO.t.time);
        }
        cols.push('');

        cols.forEach(function (c) {
            var th = el('th', null, c);
            th.setAttribute('data-nosum', '1');
            head.appendChild(th);
        });
        table.appendChild(head);

        data.clients.forEach(function (c) {
            var tr = document.createElement('tr');
            var state = {
                id: c.id, pick: null, rep: null, day: null,
                freq: null, date: null, time: null, plans: c.plans
            };
            var plan = c.plans.length ? c.plans[0] : null;

            if (GEO.canPlan) {
                var tdPick = el('td');
                var pick = document.createElement('input');
                pick.type = 'checkbox';
                pick.className = 'geo-pick';
                pick.disabled = !c.reps.length;
                state.pick = pick;
                tdPick.appendChild(pick);
                tr.appendChild(tdPick);
            }

            var tdName = el('td');
            tdName.appendChild(el('b', null, c.name));
            if (!c.has_location) {
                tdName.appendChild(document.createTextNode(' '));
                tdName.appendChild(el('span', 'badge b-orange', GEO.t.noLoc));
            }
            tdName.appendChild(el('span', 'geo-next', c.code));
            tr.appendChild(tdName);

            var tdCat = el('td');
            tdCat.appendChild(el('span', 'badge ' + c.category_class, c.category));
            tr.appendChild(tdCat);

            var tdStatus = el('td');
            if (plan) {
                c.plans.forEach(function (p) {
                    tdStatus.appendChild(el('span', 'badge b-green', p.label));
                    if (p.visit_label) {
                        tdStatus.appendChild(document.createTextNode(' '));
                        tdStatus.appendChild(el('span', 'badge b-blue', '🕒 ' + p.visit_label));
                    }
                    tdStatus.appendChild(document.createElement('br'));
                });
            } else if (!c.foreign.length) {
                tdStatus.appendChild(el('span', 'badge b-gray', GEO.t.notPlanned));
            }
            // خطط فرق تانية — للعلم بس، مفيش زرار شيل ولا تعديل
            c.foreign.forEach(function (lbl) {
                var b = el('span', 'badge b-purple', '🔒 ' + lbl);
                b.title = GEO.t.foreign;
                tdStatus.appendChild(b);
                tdStatus.appendChild(document.createElement('br'));
            });
            tr.appendChild(tdStatus);

            if (GEO.canPlan) {
                var tdRep = el('td');
                if (c.reps.length) {
                    // الافتراضي: مندوب الخطة الحالية، وإلا المسؤول
                    // الأساسي، وإلا أول مندوب مسموح
                    var want = plan ? plan.user_id : c.rep_id;
                    state.rep = mkSelect(
                        c.reps.map(function (r) { return { v: r.id, t: r.name }; }),
                        want
                    );
                    tdRep.appendChild(state.rep);
                } else {
                    tdRep.appendChild(el('span', 'badge b-red', GEO.t.noReps));
                }
                tr.appendChild(tdRep);

                // ═══ تاريخ أول زيارة (طلب المالك ١٣/٨) ═══
                // ⚠️ التاريخ **مش** بديل النمط: هو مرساة النمط. أول ما
                // يتكتب، يوم الأسبوع بيتشتق منه ويتقفل — والاتنين
                // بيتبعتوا مع بعض، والسيرفر بيعيد الاشتقاق بنفسه.
                var tdDate = el('td');
                var dateIn = document.createElement('input');
                dateIn.type = 'date';
                dateIn.value = plan && plan.starts_on ? plan.starts_on : '';
                dateIn.title = GEO.t.dateHint;
                state.date = dateIn;
                tdDate.appendChild(dateIn);
                tr.appendChild(tdDate);

                var tdDay = el('td');
                state.day = mkSelect(GEO.days, plan ? plan.weekday : GEO.days[0].v);
                tdDay.appendChild(state.day);
                var nextEl = el('span', 'geo-next', '');
                tdDay.appendChild(nextEl);
                tr.appendChild(tdDay);

                var tdFreq = el('td');
                state.freq = mkSelect(GEO.freqs, plan ? plan.every_weeks : GEO.freqs[0].v);
                tdFreq.appendChild(state.freq);
                tr.appendChild(tdFreq);

                var tdTime = el('td');
                var timeIn = document.createElement('input');
                timeIn.type = 'time';
                timeIn.value = plan && plan.visit_at ? plan.visit_at : '';
                state.time = timeIn;
                tdTime.appendChild(timeIn);
                tr.appendChild(tdTime);

                // معاينة «أول زيارة» بتتحدث مع أي تغيير — الخريطة
                // محسوبة في السيرفر من dueOn نفسها، فمفيش منطق تردد
                // متكرر في الجافاسكربت. ولما يبقى فيه تاريخ صريح،
                // هو نفسه أول زيارة فالمعاينة بتختفي.
                var sync = function () {
                    var wd = weekdayOf(state.date.value);

                    if (wd !== null) {
                        state.day.value = String(wd);
                        state.day.disabled = true;
                        nextEl.textContent = GEO.t.firstVisit + ': ' + state.date.value;
                    } else {
                        state.day.disabled = false;
                        nextEl.textContent = nextLabel(state.day.value, state.freq.value);
                    }

                    if (state.pick && !state.pick.disabled) { state.pick.checked = true; }
                };

                state.day.addEventListener('change', sync);
                state.freq.addEventListener('change', sync);
                state.date.addEventListener('change', sync);
                state.time.addEventListener('change', sync);
                if (state.rep) { state.rep.addEventListener('change', sync); }

                // أول رسم — من غير ما يعلّم الصف
                var wd0 = weekdayOf(dateIn.value);
                if (wd0 !== null) {
                    state.day.value = String(wd0);
                    state.day.disabled = true;
                    nextEl.textContent = GEO.t.firstVisit + ': ' + dateIn.value;
                } else {
                    nextEl.textContent = nextLabel(state.day.value, state.freq.value);
                }
            }

            var tdAct = el('td', 'num');
            if (GEO.canUnplan && c.plans.length) {
                var rm = el('button', 'btn sm', GEO.t.remove);
                rm.type = 'button';
                rm.addEventListener('click', function () {
                    if (!window.confirm(GEO.t.removeConfirm)) { return; }
                    post(GEO.unplanUrl, {
                        plan_ids: c.plans.map(function (p) { return p.id; })
                    });
                });
                tdAct.appendChild(rm);
            }
            tr.appendChild(tdAct);

            table.appendChild(tr);
            rows.push(state);
        });

        wrap.appendChild(table);
        body.appendChild(wrap);
    }

    function openZone(id, name) {
        openZoneId = id;
        titleEl.textContent = name;
        govEl.textContent = '—';
        govLineEl.style.display = 'none';
        subEl.textContent = '';
        body.innerHTML = '';
        rows = [];
        say(GEO.t.loading, 'info');
        openDlg('zoneDlg');

        var url = GEO.zoneUrl.replace('__ZID__', encodeURIComponent(id));
        var qs = [];
        if (GEO.manager) { qs.push('manager=' + encodeURIComponent(GEO.manager)); }
        if (GEO.q) { qs.push('q=' + encodeURIComponent(GEO.q)); }
        if (GEO.norep) { qs.push('norep=1'); }
        if (qs.length) { url += '?' + qs.join('&'); }

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) { throw new Error('http'); }
                return r.json();
            })
            .then(function (j) {
                render(j);
                say('');
            })
            .catch(function () { say(GEO.t.fail, 'warn'); });
    }

    function post(url, payload) {
        say(GEO.t.saving, 'info');
        if (saveBtn) { saveBtn.disabled = true; }

        var token = document.querySelector('meta[name="csrf-token"]');

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token ? token.content : ''
            },
            body: JSON.stringify(payload)
        })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    return { ok: r.ok, body: j };
                });
            })
            .then(function (res) {
                if (saveBtn) { saveBtn.disabled = false; }
                if (!res.ok) {
                    say(res.body.message || GEO.t.fail, 'warn');
                    return;
                }
                // ⚠️ ريلود مقصود — الأعداد في الشجرة والـKPIs بتتغير
                // بالحفظ، وتحديثها في المتصفح معناه نسخة تانية من
                // منطق العدّ تفرق عن السيرفر أول تعديل
                try { sessionStorage.setItem('geoFlash', res.body.message || ''); } catch (e) { /* خاص */ }
                window.location.reload();
            })
            .catch(function () {
                if (saveBtn) { saveBtn.disabled = false; }
                say(GEO.t.fail, 'warn');
            });
    }

    function saveRoute() {
        var payload = [];

        rows.forEach(function (r) {
            if (!r.pick || !r.pick.checked || !r.rep) { return; }
            payload.push({
                client_id: r.id,
                user_id: parseInt(r.rep.value, 10),
                weekday: parseInt(r.day.value, 10),
                every_weeks: parseInt(r.freq.value, 10),
                starts_on: r.date && r.date.value ? r.date.value : null,
                visit_at: r.time && r.time.value ? r.time.value : null
            });
        });

        if (!payload.length) { say(GEO.t.none, 'warn'); return; }

        post(GEO.planUrl, { rows: payload });
    }

    function pickAll(box) {
        rows.forEach(function (r) {
            if (r.pick && !r.pick.disabled) { r.pick.checked = box.checked; }
        });
    }

    /* ═══════════════════ لوحة التوزيع ═══════════════════
       نفس الجدول بيخدم «توزيع تلقائي» و«بدون مندوب» — الفرق
       `suggest` وبس: باقتراح جاهز، أو سيلكتات فاضية للتسكين اليدوي. */

    var distTitleEl = document.getElementById('distTitle');
    var distSubEl = document.getElementById('distSub');
    var distHintEl = document.getElementById('distHint');
    var distMsgEl = document.getElementById('distMsg');
    var distRepsEl = document.getElementById('distReps');
    var distBodyEl = document.getElementById('distBody');
    var distSaveBtn = document.getElementById('distSave');
    var distDrivers = document.getElementById('distDrivers');

    var dist = {
        manager: null, name: '', zone: null, zoneName: '',
        suggest: true, rows: [], reps: []
    };

    function distSay(text, kind) {
        distMsgEl.className = 'alert' + (kind ? ' ' + kind : '');
        distMsgEl.textContent = text || '';
        distMsgEl.style.display = text ? '' : 'none';
    }

    /* عدّاد كل مندوب من السيلكتات المعروضة — بيتحدّث مع أي تغيير
       يدوي، عشان اللي بيوزّع يشوف القسمة النهائية مش المقترحة. */
    function distCounts() {
        var out = {};

        dist.rows.forEach(function (r) {
            var v = r.sel ? r.sel.value : '';
            if (!v) { return; }
            out[v] = (out[v] || 0) + 1;
        });

        return out;
    }

    function distRenderReps() {
        var counts = distCounts();
        distRepsEl.innerHTML = '';

        dist.reps.forEach(function (r) {
            var chip = el('span', 'geo-chip');
            chip.appendChild(el('span', null, r.name + ' · ' + r.role));
            chip.appendChild(el('span', 'geo-next', GEO.t.distCurrent + ' ' + r.current));
            chip.appendChild(el('b', 'num', '+' + (counts[String(r.id)] || 0)));
            distRepsEl.appendChild(chip);
        });
    }

    function distRender(data) {
        dist.rows = [];
        dist.reps = data.reps || [];
        distBodyEl.innerHTML = '';

        distSubEl.textContent = (dist.zoneName ? dist.zoneName + ' · ' : '') + data.clients.length;

        if (!data.reps.length) {
            distRepsEl.innerHTML = '';
            distBodyEl.appendChild(el('div', 'alert warn', GEO.t.distNoReps));
            return;
        }

        if (!data.clients.length) {
            distRepsEl.innerHTML = '';
            distBodyEl.appendChild(el('div', 'alert good',
                dist.suggest ? GEO.t.distEmpty : GEO.t.norepEmpty));
            return;
        }

        var wrap = el('div', 'tablewrap');
        var table = el('table');
        var head = document.createElement('tr');

        [GEO.t.client, GEO.t.category, GEO.t.distSuggested].forEach(function (c) {
            var th = el('th', null, c);
            th.setAttribute('data-nosum', '1');
            head.appendChild(th);
        });
        table.appendChild(head);

        // ⚠️ التجميع بالمنطقة مطلوب في الحالتين — المالك بيوزّع خط
        // سير مش أسماء، والصفوف المبعترة بتخلّيه يفتح ٤٠ صف عشان
        // يشوف منطقة واحدة. الصفوف راجعة مرتّبة بالمنطقة من السيرفر.
        var lastZone = null;

        data.clients.forEach(function (c) {
            var zKey = String(c.zone_id === null ? 'none' : c.zone_id);

            if (zKey !== lastZone) {
                lastZone = zKey;
                var gtr = document.createElement('tr');
                var gtd = el('td', null, c.governorate + ' › ' + c.zone);
                gtd.colSpan = 3;
                gtr.className = 'geo-zgrp';
                gtr.appendChild(gtd);
                table.appendChild(gtr);
            }

            var tr = document.createElement('tr');
            var state = { id: c.id, sel: null };

            var tdName = el('td');
            tdName.appendChild(el('b', null, c.name));
            tdName.appendChild(el('span', 'geo-next', c.code));
            tr.appendChild(tdName);

            var tdCat = el('td');
            tdCat.appendChild(el('span', 'badge ' + c.category_class, c.category));
            tr.appendChild(tdCat);

            var tdSel = el('td');

            if (!c.reps.length) {
                tdSel.appendChild(el('span', 'badge b-red', GEO.t.distBlocked));
            } else {
                var opts = [{ v: '', t: '— ' + GEO.t.distSkip + ' —' }];
                c.reps.forEach(function (r) { opts.push({ v: r.id, t: r.name }); });

                state.sel = mkSelect(opts, c.suggested === null ? '' : c.suggested);
                state.sel.addEventListener('change', distRenderReps);
                tdSel.appendChild(state.sel);
            }

            tr.appendChild(tdSel);
            table.appendChild(tr);
            dist.rows.push(state);
        });

        wrap.appendChild(table);
        distBodyEl.appendChild(wrap);
        distRenderReps();
    }

    function distLoad() {
        distBodyEl.innerHTML = '';
        distRepsEl.innerHTML = '';
        dist.rows = [];
        distSay(GEO.t.loading, 'info');

        var qs = ['manager=' + encodeURIComponent(dist.manager)];
        if (dist.zone) { qs.push('zone=' + encodeURIComponent(dist.zone)); }
        if (!dist.suggest) { qs.push('suggest=0'); }
        if (distDrivers && distDrivers.checked) { qs.push('drivers=1'); }

        fetch(GEO.distUrl + '?' + qs.join('&'), { headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) { throw new Error('http'); }
                return r.json();
            })
            .then(function (j) {
                distRender(j);
                distSay('');
            })
            .catch(function () { distSay(GEO.t.fail, 'warn'); });
    }

    function distOpen(managerId, name, opts) {
        opts = opts || {};
        dist.manager = managerId;
        dist.name = name || '';
        dist.zone = opts.zone || null;
        dist.zoneName = opts.zoneName || '';
        dist.suggest = opts.suggest !== false;

        distTitleEl.textContent = dist.suggest
            ? GEO.t.distTitle.replace('__N__', dist.name)
            : GEO.t.norepTitle;
        distHintEl.textContent = dist.suggest ? GEO.t.distHint : GEO.t.norepHint;
        distSubEl.textContent = '';

        openDlg('distDlg');
        distLoad();
    }

    function distSave() {
        var payload = [];

        dist.rows.forEach(function (r) {
            if (!r.sel || !r.sel.value) { return; }
            payload.push({ client_id: r.id, user_id: parseInt(r.sel.value, 10) });
        });

        if (!payload.length) { distSay(GEO.t.distNone, 'warn'); return; }

        distSay(GEO.t.saving, 'info');
        distSaveBtn.disabled = true;

        var token = document.querySelector('meta[name="csrf-token"]');

        fetch(GEO.assignUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token ? token.content : ''
            },
            body: JSON.stringify({ rows: payload })
        })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    return { ok: r.ok, body: j };
                });
            })
            .then(function (res) {
                distSaveBtn.disabled = false;
                if (!res.ok) {
                    distSay(res.body.message || GEO.t.fail, 'warn');
                    return;
                }
                // ريلود زي الحفظ — الأعداد في كل الشاشة بتتغيّر
                try { sessionStorage.setItem('geoFlash', res.body.message || ''); } catch (e) { /* خاص */ }
                window.location.reload();
            })
            .catch(function () {
                distSaveBtn.disabled = false;
                distSay(GEO.t.fail, 'warn');
            });
    }

    /* فلترة فورية للشجرة بالاسم — المحافظة أو المنطقة. البحث باسم
       أو كود المحل بيروح للسيرفر بـEnter لأن أسماء المحلات مش
       معروضة في الشجرة أصلاً. */
    function treeFilter(raw) {
        var q = (raw || '').trim().toLowerCase();

        document.querySelectorAll('.geo-gov').forEach(function (d) {
            if (q === '') {
                d.querySelectorAll('.geo-zrow').forEach(function (r) { r.style.display = ''; });
                d.style.display = '';
                if ('wasOpen' in d.dataset) {
                    d.open = d.dataset.wasOpen === '1';
                    delete d.dataset.wasOpen;
                }
                return;
            }

            if (!('wasOpen' in d.dataset)) { d.dataset.wasOpen = d.open ? '1' : '0'; }

            var govHit = (d.dataset.txt || '').indexOf(q) !== -1;
            var any = false;

            d.querySelectorAll('.geo-zrow').forEach(function (r) {
                var hit = govHit || (r.dataset.txt || '').indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) { any = true; }
            });

            d.style.display = any ? '' : 'none';
            d.open = any;
        });
    }

    window.geoOpenZone = openZone;
    window.geoSaveRoute = saveRoute;
    window.geoPickAll = pickAll;
    window.geoTreeFilter = treeFilter;

    window.geoOpenDist = function (btn) {
        distOpen(parseInt(btn.dataset.mid, 10), btn.dataset.mname, {});
    };

    window.geoOpenNoRep = function (btn) {
        distOpen(parseInt(btn.dataset.mid, 10), btn.dataset.mname, { suggest: false });
    };

    // «وزّع المنطقة دي» — من جوه لوحة المنطقة، على المدير المفلتر
    window.geoDistZone = function () {
        if (!GEO.manager || !openZoneId) { return; }
        // اسم المنطقة بيتمرّر للعنوان الفرعي — اللي بيوزّع لازم يشوف
        // إنه جوه منطقة واحدة مش البول كله
        var zoneName = titleEl.textContent;
        closeDlg('zoneDlg');
        distOpen(GEO.manager, GEO.managerName || '', { zone: openZoneId, zoneName: zoneName });
    };

    window.geoDistReload = distLoad;
    window.geoDistSave = distSave;

    document.addEventListener('DOMContentLoaded', function () {
        var flash = null;
        try { flash = sessionStorage.getItem('geoFlash'); } catch (e) { flash = null; }
        if (flash) {
            var box = document.getElementById('geoFlash');
            box.textContent = flash;
            box.style.display = '';
            try { sessionStorage.removeItem('geoFlash'); } catch (e) { /* خاص */ }
        }
    });
})();
</script>
@endsection
