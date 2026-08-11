@extends('layouts.system')

@section('title', __('journey.assignments'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $myZoneIds = $rep?->zones->pluck('id')->all() ?? [];
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.journeys') }}">🗺️ {{ __('journey.page') }}</a>
    <a class="btn" href="{{ route('ops.live') }}">📡 {{ __('journey.live') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>👥 {{ __('journey.assignments') }} <span class="side">{{ __('journey.assignments_sub') }}</span></h3>

    @if ($orphanTotal > 0)
        <div class="alert warn">
            {{ __('journey.orphans_hint') }} — <b>{{ $fmt($orphanTotal) }}</b>
        </div>
    @else
        <div class="alert good">{{ __('journey.no_orphans') }}</div>
    @endif

    @if ($rep === null)
        <div class="alert warn">{{ __('journey.no_reps') }}</div>
    @else
        {{-- ⚠️ فورم بحث واحد GET بيحمل المندوب + الزون + الفلتر + النص.
             تغيير أي واحدة بيبعت الفورم وبيحافظ على الباقي. --}}
        <form method="GET" action="{{ route('ops.assignments') }}" class="searchbar">
            <div>
                <label class="f">{{ __('journey.rep') }}</label>
                <select name="rep" onchange="this.form.submit()">
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected($rep->id === $r->id)>
                            {{ $r->displayName() }} — {{ $r->roleLabel() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('client.zone') }}</label>
                @include('partials._zone_select', [
                    'zones' => $zones,
                    'name' => 'zone',
                    'selected' => $filters['zone'] ?? null,
                    'placeholder' => __('common.all'),
                    'attrs' => 'onchange="this.form.submit()"',
                ])
            </div>
            <div>
                <label class="f">{{ __('journey.filter') }}</label>
                <select name="only" onchange="this.form.submit()">
                    <option value="" @selected(($filters['only'] ?? '') === '')>{{ __('journey.show_all') }}</option>
                    <option value="orphans" @selected(($filters['only'] ?? '') === 'orphans')>{{ __('journey.only_orphans') }}</option>
                    <option value="mine" @selected(($filters['only'] ?? '') === 'mine')>{{ __('journey.only_mine') }}</option>
                </select>
            </div>
            <div>
                <label class="f">{{ __('common.search') }}</label>
                {{-- ⚠️ سيرفر-سايد مش متصفح — القايمة مقصوصة على 500 صف،
                     فالفلترة في المتصفح بتدوّر في المقصوص بس. --}}
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}"
                       placeholder="{{ __('common.search') }}…">
            </div>
        </form>
    @endif
</div>

{{-- ═══════════ بول الفريق — كارت لكل مدير (١١ أغسطس مساءً) ═══════════
     الفصل الأساسي بقى على مستوى مدير القناة: عملاؤه بول مشترك لكل
     مناديبه. كله معلّم بالقاعدة — مفيش حاجة تتحفظ من هنا. --}}
<div class="card">
    <h3>🤝 {{ __('journey.pools_title') }}</h3>
    <div class="alert info">{{ __('journey.pools_hint') }}</div>

    @if (empty($pools['cards']))
        <div class="alert warn">{{ __('journey.pools_none') }}</div>
    @else
        {{-- سطر الجمع: مجاميع الفرق + بدون فريق = الإجمالي (١١/٨ مساءً) --}}
        <div class="pool-sum">
            @foreach ($pools['cards'] as $p)
                <span class="badge b-blue">{{ $p['manager']->displayName() }} · {{ $fmt($p['client_count']) }}</span>
                <span style="color:var(--muted)">+</span>
            @endforeach
            <span class="badge {{ $pools['orphans'] > 0 ? 'b-orange' : 'b-gray' }}">
                {{ __('journey.pool_orphans') }} · {{ $fmt($pools['orphans']) }}
            </span>
            <span style="color:var(--muted)">=</span>
            <span class="badge b-green"><b>{{ $fmt($pools['total']) }}</b> {{ __('journey.pool_clients') }}</span>
        </div>
        @if ($pools['orphans'] > 0)
            <div class="alert warn" style="margin-top:8px">
                {{ __('journey.pool_orphans_hint', ['count' => $fmt($pools['orphans'])]) }}
            </div>
        @endif

        <div class="poolgrid">
            @foreach ($pools['cards'] as $p)
                <div class="poolcard">
                    <div class="pool-head">
                        @include('partials._avatar', ['u' => $p['manager'], 'size' => 38, 'ring' => '#602D90'])
                        <div>
                            <b>{{ $p['manager']->displayName() }}</b>
                            <div class="s" style="color:var(--muted)">{{ $p['manager']->roleLabel() }}</div>
                        </div>
                        <span class="badge b-purple" style="margin-inline-start:auto">
                            {{ __('journey.pool_shared') }}
                        </span>
                    </div>

                    <div class="pool-kpis">
                        <span class="badge b-blue">👥 {{ $p['reps']->count() }} {{ __('journey.pool_reps') }}</span>
                        <span class="badge b-green">🏪 {{ $fmt($p['client_count']) }} {{ __('journey.pool_clients') }}</span>
                        <span class="badge b-gold">📍 {{ count($p['zones']) }} {{ __('journey.pool_zones') }}</span>
                    </div>

                    {{-- مناديب الفريق — كلهم شايفين كل البول --}}
                    @if ($p['reps']->isEmpty())
                        <div class="s" style="color:var(--muted);padding:6px 0">{{ __('journey.pool_no_reps') }}</div>
                    @else
                        <div class="pool-reps">
                            @foreach ($p['reps'] as $tr)
                                <span class="pool-rep" title="{{ $tr->roleLabel() }}">
                                    @include('partials._avatar', ['u' => $tr, 'size' => 24])
                                    <span>{{ $tr->displayName() }}</span>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    {{-- مناطق فيها عملاء البول — بعدد العملاء، وكلها «✓» --}}
                    @if ($p['zones'] !== [])
                        <div class="pool-zones">
                            @foreach ($p['zones'] as $pz)
                                <span class="badge b-green">✓ {{ $pz['name'] }} · {{ $fmt($pz['count']) }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

@if ($rep !== null)

{{-- ═══════════ كل العملاء — المسؤول الأساسي بضغطة ═══════════ --}}
<div class="card">
    <h3>👥 {{ __('journey.all_clients') }} <span class="side">{{ $clients->count() }}</span></h3>

    {{-- التسكين الفردي اتنزّل درجة: بيحدد المسؤول الأساسي بس --}}
    <div class="alert info">{{ __('journey.primary_hint') }}</div>

    <div class="alert">{{ __('journey.flow_hint') }}</div>

    {{-- ⚠️ فورم النقل الجماعي منفصل — الشيك بوكسات في الجدول بتنتمي له
         عبر `form="bulkForm"` عشان مايبقاش فيه فورم جوه فورم. مفيش
         `zones_form` هنا فمناطق المندوب مابتتلمسش خالص. --}}
    <form id="bulkForm" method="POST" action="{{ route('ops.assignments.assign') }}">
        @csrf
        <input type="hidden" name="user_id" value="{{ $rep->id }}">
    </form>

    <div style="display:flex;align-items:center;gap:14px;margin-bottom:9px;flex-wrap:wrap">
        <label style="font-size:12.5px;display:inline-flex;align-items:center;gap:7px">
            <input type="checkbox" onchange="toggleAll(this)"> {{ __('journey.select_all') }}
        </label>
        <button class="btn gold" form="bulkForm" type="submit">
            {{ __('journey.move_selected', ['rep' => $rep->displayName()]) }}
        </button>
    </div>

    <div class="tablewrap" style="max-height:540px;overflow-y:auto">
        <table>
            <tr>
                <th style="width:34px"></th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('client.zone') }}</th>
                <th>{{ __('journey.current_rep') }}</th>
                <th></th>
            </tr>
            @forelse ($clients as $c)
                <tr @class(['orphan-row' => $c->rep_id === null])>
                    <td><input type="checkbox" class="pick" form="bulkForm" name="client_ids[]" value="{{ $c->id }}"></td>
                    {{-- الاسم الكامل: السلسلة الأول وبعدين الفرع — زي صفحة العملاء --}}
                    <td><a href="{{ route('erp.clients.show', $c) }}"><b>{{ $c->fullName() }}</b></a></td>
                    <td class="s">{{ $c->zone?->displayName() ?: '—' }}</td>
                    <td class="s">
                        @if ($c->rep_id === null && $c->manager_id !== null)
                            {{-- في بول فريق — كل مناديب المدير شايفينه، مفيش «مسؤول أساسي» بس --}}
                            <span class="badge b-purple">🤝 {{ __('journey.in_pool_of', ['name' => $c->manager?->displayName() ?? '—']) }}</span>
                        @elseif ($c->rep_id === null)
                            <span class="badge b-orange">{{ __('journey.no_rep') }}</span>
                        @elseif ($c->rep_id === $rep->id)
                            <span class="badge b-green">{{ $c->rep?->displayName() }}</span>
                        @else
                            <span class="badge b-gray">{{ $c->rep?->displayName() }}</span>
                        @endif
                    </td>
                    <td class="num">
                        @if ($c->rep_id === $rep->id)
                            <form method="POST" action="{{ route('ops.assignments.unassign', $c) }}">
                                @csrf @method('DELETE')
                                <button class="btn sm">{{ __('journey.remove_this') }}</button>
                            </form>
                        @else
                            {{-- تخصيص عميل واحد للمندوب المختار — نفس إندبوينت
                                 النقل الجماعي بعميل واحد. الحارس بيتفحص في السيرفر. --}}
                            <form method="POST" action="{{ route('ops.assignments.assign') }}">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $rep->id }}">
                                <input type="hidden" name="client_ids[]" value="{{ $c->id }}">
                                <button class="btn sm gold">{{ __('journey.assign_to', ['rep' => $rep->displayName()]) }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('journey.no_clients') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- ═══════════ مناطق المندوب (مستقلة — متتلمسش من التخصيص) ═══════════ --}}
<div class="card">
    <h3>📍 {{ __('journey.my_zones') }}</h3>

    {{-- ⚠️ **خانة البحث بره فورم الحفظ عن قصد** — Enter جوه فورم POST
         كان هيحفظ المناطق والمستخدم لسه بيدوّر. برّه الفورم Enter
         مابيعملش حاجة. البحث بيطابق الاسم العربي والإنجليزي والكود،
         وبيفتح المحافظات اللي فيها نتايج بس — ومسح الخانة بيرجّع
         حالة الطي اللي كانت قبل البحث. --}}
    <input type="search" id="zoneSearch" placeholder="🔍 {{ __('common.search') }}…"
           style="width:100%;margin-bottom:9px" oninput="zoneFilter(this.value)">

    <form method="POST" action="{{ route('ops.assignments.assign') }}">
        @csrf
        <input type="hidden" name="user_id" value="{{ $rep->id }}">
        {{-- ⚠️ العلم ده بيقول للكنترولر «الفورم ده بيحفظ المناطق».
             المتصفح مابيبعتش `zone_ids[]` خالص لو كلها متشالة،
             فمن غير العلم مسح كل المناطق مابيتنفذش. --}}
        <input type="hidden" name="zones_form" value="1">

        {{-- ⚠️ **المحافظة رأس مطوي والمناطق جواها.** القايمة المسطّحة
             كانت بتحط 49 منطقة ورا بعض واللي بيسكّن مندوب على
             «العاشر» مش شايف إنه ساب باقي مناطق الشرقية من غير
             حد. تشيك بوكس المحافظة بيعلّم على كل مناطقها مرة واحدة.

             ⚠️ **كل المحافظات مقفولة افتراضياً** (طلب المالك ١١/٨) —
             `<details>` بعلامة «＋»، والرأس بيقول كام منطقة وكام
             متعلّم جواه عشان المحافظة المقفولة ماتخبّيش اختيارات. --}}
        <div style="max-height:330px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;padding:9px">
            @php $byGov = $zones->groupBy(fn ($z) => $z->governorate ?: '_none'); @endphp
            @foreach (array_merge(\App\Support\Governorates::keys(), ['_none']) as $gk)
                @continue(! ($group = $byGov->get($gk)) || $group->isEmpty())
                <details class="zgov"
                         data-txt="{{ mb_strtolower(($gk === '_none' ? __('geo.no_governorate') : \App\Support\Governorates::label($gk)).' '.$gk) }}">
                    <summary>
                        <span class="zg-plus" aria-hidden="true"></span>
                        {{-- ⚠️ stopPropagation — من غيرها في متصفحات الضغط على
                             التشيك بوكس بيفتح/يقفل المحافظة مع التعليم --}}
                        <input type="checkbox" class="govBox" data-gov="{{ $gk }}"
                               onclick="event.stopPropagation()" onchange="toggleGov(this)">
                        <span>{{ $gk === '_none' ? __('geo.no_governorate') : \App\Support\Governorates::label($gk) }}</span>
                        <span class="badge b-green zg-sel" data-gov-sel="{{ $gk }}" style="display:none"></span>
                        <span class="s zg-tot">{{ $group->count() }} {{ __('journey.zone_countable') }}</span>
                    </summary>
                    @foreach ($group->sortBy(fn ($z) => $z->displayName()) as $z)
                        <label class="zrow"
                               data-txt="{{ mb_strtolower(trim(($z->name ?? '').' '.($z->name_en ?? '').' '.($z->code ?? ''))) }}"
                               style="display:flex;align-items:center;gap:8px;padding:5px 3px;padding-inline-start:29px;font-size:12.5px">
                            <input type="checkbox" name="zone_ids[]" value="{{ $z->id }}"
                                   class="zoneBox" data-gov="{{ $gk }}"
                                   @checked(in_array($z->id, $myZoneIds, true))
                                   onchange="syncGov('{{ $gk }}')">
                            <span>{{ $z->displayName() }}</span>
                            <span class="s" style="margin-inline-start:auto;color:var(--muted)">
                                {{ $fmt($z->clients_count) }}
                            </span>
                        </label>
                    @endforeach
                </details>
            @endforeach
        </div>

        <button class="btn gold" style="width:100%;margin-top:10px">{{ __('common.save') }}</button>
    </form>
</div>
@endif

@endsection

@section('scripts')
<style>
    tr.orphan-row td { background: rgba(234, 140, 28, .08); }

    /* ═══ كروت بول الفريق ═══ */
    .pool-sum{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:10px}
    .poolgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px}
    .poolcard{border:1px solid var(--border);border-radius:12px;padding:12px;background:var(--card)}
    .pool-head{display:flex;align-items:center;gap:10px;margin-bottom:9px}
    .pool-kpis{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:9px}
    .pool-reps{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:9px}
    .pool-rep{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:20px;padding:2px 9px 2px 3px;font-size:12px;background:var(--card2)}
    [dir=rtl] .pool-rep{padding:2px 3px 2px 9px}
    .pool-zones{display:flex;gap:5px;flex-wrap:wrap;max-height:96px;overflow-y:auto}

    /* ═══ محافظات مطوية — <details> بعلامة ＋/− ═══
       نفس أسلوب أكورديون السايدبار: list-style:none + إخفاء الماركر
       عشان المثلث الافتراضي مايبوّظش المحاذاة في العربي. */
    .zgov{border-bottom:1px solid var(--border)}
    .zgov:last-child{border-bottom:none}
    .zgov>summary{display:flex;align-items:center;gap:8px;padding:6px 3px;font-size:12px;font-weight:900;color:var(--royal-blue);cursor:pointer;list-style:none;user-select:none;border-radius:7px}
    .zgov>summary::-webkit-details-marker{display:none}
    .zgov>summary:hover{background:var(--card2)}
    .zg-plus{width:18px;height:18px;flex-shrink:0;display:grid;place-items:center;border:1px solid var(--border);border-radius:6px;background:var(--card);font-size:13px;line-height:1}
    .zg-plus::before{content:'+'}
    .zgov[open] .zg-plus::before{content:'−'}
    .zg-tot{margin-inline-start:auto;color:var(--muted);font-weight:400}
</style>
<script>
    // اختار/شيل كل العملاء في القايمة للنقل الجماعي
    function toggleAll(box) {
        document.querySelectorAll('.pick').forEach(el => { el.checked = box.checked; });
    }

    // تشيك بوكس المحافظة بيعلّم/يشيل كل مناطقها
    function toggleGov(box) {
        document.querySelectorAll('.zoneBox[data-gov="' + box.dataset.gov + '"]')
            .forEach(el => { el.checked = box.checked; });
        syncGov(box.dataset.gov);
    }

    // والعكس: حالة رأس المحافظة بتتبع مناطقها + بادج «✓ ن» على الرأس
    // ⚠️ البادج هو اللي بيخلي المحافظة المقفولة تقول إن جواها متعلّم —
    //    من غيره الطي كان هيخبّي الاختيارات واللي بيحفظ مش شايف
    //    هو سايب إيه معلّم فين.
    // ⚠️ style.display مش hidden — كلاس .badge بـdisplay:inline-block
    //    بييجي بعد قاعدة [hidden] بتاعة المتصفح فبيغلبها.
    function syncGov(gov) {
        const boxes = [...document.querySelectorAll('.zoneBox[data-gov="' + gov + '"]')];
        const head = document.querySelector('.govBox[data-gov="' + gov + '"]');
        if (!head) return;
        const on = boxes.filter(b => b.checked).length;
        head.checked = on > 0 && on === boxes.length;
        head.indeterminate = on > 0 && on < boxes.length;
        const badge = document.querySelector('.zg-sel[data-gov-sel="' + gov + '"]');
        if (badge) {
            badge.textContent = '✓ ' + on;
            badge.style.display = on === 0 ? 'none' : '';
        }
    }

    // ═══ بحث المناطق (١١/٨) ═══
    // بيطابق data-txt (عربي + إنجليزي + كود) على مستوى المنطقة،
    // واسم المحافظة على مستوى الرأس (كتابة «الشرقية» بتفتحها كلها).
    // أول حرف بحث بيحفظ حالة الطي في dataset.wasOpen، والمسح بيرجّعها.
    function zoneFilter(raw) {
        const q = raw.trim().toLowerCase();
        document.querySelectorAll('.zgov').forEach(function (d) {
            if (q === '') {
                d.querySelectorAll('.zrow').forEach(r => { r.style.display = ''; });
                d.style.display = '';
                if ('wasOpen' in d.dataset) {
                    d.open = d.dataset.wasOpen === '1';
                    delete d.dataset.wasOpen;
                }
                return;
            }
            if (!('wasOpen' in d.dataset)) d.dataset.wasOpen = d.open ? '1' : '0';
            const govHit = (d.dataset.txt || '').includes(q);
            let any = false;
            d.querySelectorAll('.zrow').forEach(function (r) {
                const hit = govHit || (r.dataset.txt || '').includes(q);
                r.style.display = hit ? '' : 'none';
                if (hit) any = true;
            });
            d.style.display = any ? '' : 'none';
            d.open = any;
        });
    }

    document.querySelectorAll('.govBox').forEach(b => syncGov(b.dataset.gov));
</script>
@endsection
