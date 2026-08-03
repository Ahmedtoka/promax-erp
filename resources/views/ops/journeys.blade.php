@extends('layouts.system')

@section('title', __('journey.page'))

@php
    $fmt = fn ($n) => number_format((float) $n);
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.assignments') }}">👥 {{ __('journey.assignments') }}</a>
    <a class="btn" href="{{ route('ops.live') }}">📡 {{ __('journey.live') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>🗺️ {{ __('journey.page') }} <span class="side">{{ __('journey.page_sub') }}</span></h3>

    @if ($rep === null)
        <div class="alert warn">{{ __('journey.no_reps') }}</div>
    @else
        <form method="GET" action="{{ route('ops.journeys') }}" class="searchbar">
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
        </form>
    @endif
</div>

@if ($rep !== null)

{{-- ═══════════ الأسبوع ═══════════ --}}
<div class="card">
    <h3>📅 {{ __('journey.week_plan') }} <span class="side">{{ $rep->displayName() }}</span></h3>

    <div class="weekgrid">
        @foreach ($weekdays as $day)
            @php $plans = $week[$day] ?? collect(); @endphp
            <div class="daycol {{ $day === $today ? 'istoday' : '' }}">
                <div class="dayhead">
                    {{ __('journey.day_'.$day) }}
                    <span class="cnt">{{ $plans->count() }}</span>
                </div>

                @forelse ($plans as $p)
                    {{-- ⚠️ الترتيب هو خط السير الفعلي — المندوب بيمشي
                         بالقايمة من فوق لتحت، فالأسهم دي هي اللي بتحدد
                         مشوار عربيته. الراوت `journeys.reorder` كان
                         موجود من غير أي واجهة تناديه. --}}
                    <div class="planrow" data-plan="{{ $p->id }}">
                        <span class="ordnum">{{ $loop->iteration }}</span>
                        <div style="min-width:0;flex:1">
                            {{-- الاسم الكامل: السلسلة — الفرع --}}
                            <b style="font-size:12px">{{ $p->client->fullName() }}</b>
                            @if ($p->every_weeks > 1)
                                <br><span class="s">{{ $p->frequencyLabel() }}</span>
                            @endif
                        </div>
                        <span class="ordbtns">
                            @unless ($loop->first)
                                <button type="button" class="xbtn" onclick="movePlan(this, -1)" title="↑">▲</button>
                            @endunless
                            @unless ($loop->last)
                                <button type="button" class="xbtn" onclick="movePlan(this, 1)" title="↓">▼</button>
                            @endunless
                        </span>
                        <form method="POST" action="{{ route('ops.journeys.destroy', $p) }}">
                            @csrf @method('DELETE')
                            <button class="xbtn" title="{{ __('journey.removed') }}">✕</button>
                        </form>
                    </div>
                @empty
                    <div class="s" style="padding:10px 4px;color:var(--muted)">{{ __('journey.no_plan_day') }}</div>
                @endforelse

                <button class="btn sm" style="width:100%;margin-top:6px"
                        onclick="openAdd({{ $day }})">➕</button>
            </div>
        @endforeach
    </div>
</div>

{{-- ═══════════ خطة الشهر — النمط مفرود على التواريخ ═══════════ --}}
@php
    $firstCol = $monthStart->dayOfWeek; // 0 = الأحد = أول عمود
    $daysInMonth = $monthStart->daysInMonth;
    $todayKey = today()->toDateString();
    $prevMonth = $monthStart->copy()->subMonth()->format('Y-m');
    $nextMonth = $monthStart->copy()->addMonth()->format('Y-m');
    $mLink = fn ($m) => route('ops.journeys', ['rep' => $rep->id, 'month' => $m]);
@endphp
<div class="card">
    <h3>📅 {{ __('journey.month_plan') }} — {{ $monthStart->format('m / Y') }}
        <span class="side">
            <a class="btn sm" href="{{ $mLink($prevMonth) }}">◀</a>
            <a class="btn sm" href="{{ $mLink(today()->format('Y-m')) }}">{{ __('journey.this_month') }}</a>
            <a class="btn sm" href="{{ $mLink($nextMonth) }}">▶</a>
        </span>
    </h3>
    <div style="font-size:11.5px;color:var(--muted);margin-bottom:10px">{{ __('journey.month_hint') }}</div>

    <div class="tablewrap">
        <table style="table-layout:fixed">
            <tr>
                @foreach ($weekdays as $d)
                    <th style="text-align:center">{{ __('journey.day_'.$d) }}</th>
                @endforeach
            </tr>
            @for ($cell = 0; $cell < $firstCol + $daysInMonth; $cell += 7)
                <tr>
                    @for ($col = 0; $col < 7; $col++)
                        @php
                            $dayNum = $cell + $col - $firstCol + 1;
                            $inMonth = $dayNum >= 1 && $dayNum <= $daysInMonth;
                            $date = $inMonth ? $monthStart->copy()->addDays($dayNum - 1) : null;
                            $key = $date?->toDateString();
                            $rows = $inMonth ? ($calendar[$key] ?? []) : [];
                            $isToday = $key === $todayKey;
                            $isPast = $date && $date->lt(today());
                        @endphp
                        <td style="vertical-align:top;height:96px;padding:6px 7px;
                                   {{ $isToday ? 'background:var(--blue-050);box-shadow:inset 0 0 0 2px var(--royal-blue);border-radius:8px;' : '' }}
                                   {{ ! $inMonth ? 'background:var(--card2);' : '' }}">
                            @if ($inMonth)
                                <div style="font-size:12px;font-weight:900;margin-bottom:4px;
                                            color:{{ $isToday ? 'var(--royal-blue)' : 'var(--muted)' }}">
                                    {{ $dayNum }}
                                </div>
                                {{-- أول ٤ عملاء + عدّاد للباقي — الخلية مش صفحة --}}
                                @foreach (array_slice($rows, 0, 4) as $r)
                                    <div style="font-size:10.5px;line-height:1.7;white-space:nowrap;
                                                overflow:hidden;text-overflow:ellipsis;
                                                color:{{ $r['done'] ? 'var(--green)' : ($isPast ? 'var(--red)' : 'var(--ink)') }}">
                                        {{ $r['done'] ? '✓' : ($isPast ? '✗' : '•') }} {{ $r['name'] }}
                                    </div>
                                @endforeach
                                @if (count($rows) > 4)
                                    <div style="font-size:10px;color:var(--muted)">+{{ count($rows) - 4 }}</div>
                                @endif
                            @endif
                        </td>
                    @endfor
                </tr>
            @endfor
        </table>
    </div>
</div>

{{-- ═══════════ إضافة عملاء ═══════════ --}}
<dialog id="dlgAddPlan">
    <form class="dlg" method="POST" action="{{ route('ops.journeys.store') }}" style="max-height:86vh;overflow-y:auto">
        @csrf
        <input type="hidden" name="user_id" value="{{ $rep->id }}">
        <input type="hidden" name="weekday" id="addDay" value="0">

        <h4>{{ __('journey.add_to_day') }} — <span id="addDayLabel"></span></h4>

        <div>
            <label class="f">{{ __('journey.frequency') }}</label>
            <select name="every_weeks" style="width:100%">
                @foreach ($frequencies as $f)
                    <option value="{{ $f }}">{{ __('journey.freq_'.$f) }}</option>
                @endforeach
            </select>
        </div>

        <div style="margin-top:12px">
            <label class="f">{{ __('journey.available_clients') }} ({{ $available->count() }})</label>

            @if ($available->isEmpty())
                <div class="alert info">{{ __('journey.no_available') }}</div>
            @else
                {{-- ⚠️ بعد استيراد الـ455 عميل القايمة دي طوّلت —
                     الفلترة في المتصفح عشان الديالوج مايتقفلش ويضيع
                     اللي المستخدم علّم عليه. المعلّم بيفضل معلّم حتى
                     لو اتخفى بالفلتر، وبيتبعت عادي. --}}
                {{-- ⚠️ Enter جوه فورم بيعمل submit ضمني — يعني اللي
                     بيكتب في البحث ويدوس Enter كان بيحفظ الخطة بدل
                     ما يفلتر. --}}
                <input type="search" id="availFilter" style="width:100%;margin-bottom:7px"
                       placeholder="{{ __('common.search') }}…"
                       onkeydown="if (event.key === 'Enter') event.preventDefault()"
                       oninput="filterAvail(this.value)">
                <div style="max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;padding:8px">
                    @foreach ($available as $c)
                        {{-- البحث بيشمل اسم السلسلة كمان — الاسم المعروض بيبدأ بيها --}}
                        <label class="availrow" data-t="{{ mb_strtolower($c->fullName().' '.$c->name_en.' '.($c->group?->name_en ?? '').' '.$c->zone?->displayName()) }}"
                               style="display:flex;align-items:center;gap:8px;padding:5px 3px;font-size:12.5px">
                            <input type="checkbox" name="client_ids[]" value="{{ $c->id }}">
                            <span>{{ $c->fullName() }}</span>
                            <span class="s" style="margin-inline-start:auto">{{ $c->zone?->displayName() }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgAddPlan')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit" @disabled($available->isEmpty())>{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- فورم واحد مخفي بيتملى بالجافاسكربت ويتبعت — مش فورم لكل سهم --}}
<form method="POST" action="{{ route('ops.journeys.reorder') }}" id="reorderForm" style="display:none">
    @csrf
    <div id="reorderFields"></div>
</form>

@endif

@endsection

@section('scripts')
@php
    // أسماء الأيام جاهزة للجافاسكريبت — البليد مابيشتغلش جوه السكريبت
    $dayNames = [];
    foreach ($weekdays as $d) {
        $dayNames[$d] = __('journey.day_'.$d);
    }
@endphp
<script>
    const DAY_NAMES = {!! json_encode($dayNames, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};

    function openAdd(day) {
        document.getElementById('addDay').value = day;
        document.getElementById('addDayLabel').textContent = DAY_NAMES[day] || '';
        openDlg('dlgAddPlan');
    }

    function filterAvail(q) {
        q = q.trim().toLowerCase();
        document.querySelectorAll('.availrow').forEach(function (row) {
            row.style.display = (!q || row.dataset.t.includes(q)) ? '' : 'none';
        });
    }

    // ⚠️ **بيبعت اليوم كله بترتيبه الجديد.** الكنترولر بيكتب `sort`
    // بمكان كل عنصر في المصفوفة، فإرسال عنصرين بس كان هيديهم 1 و2
    // ويخبط في ترتيب باقي اليوم.
    function movePlan(btn, dir) {
        const row = btn.closest('.planrow');
        const col = row.parentElement;
        const sib = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
        if (!sib || !sib.classList.contains('planrow')) return;

        dir < 0 ? col.insertBefore(row, sib) : col.insertBefore(sib, row);

        const fields = document.getElementById('reorderFields');
        fields.innerHTML = '';
        col.querySelectorAll('.planrow').forEach(function (r) {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'order[]';
            i.value = r.dataset.plan;
            fields.appendChild(i);
        });
        document.getElementById('reorderForm').submit();
    }
</script>
<style>
.weekgrid{display:grid;grid-template-columns:repeat(7,minmax(150px,1fr));gap:10px;overflow-x:auto}
@media(max-width:1200px){.weekgrid{grid-template-columns:repeat(4,minmax(150px,1fr))}}
@media(max-width:700px){.weekgrid{grid-template-columns:repeat(2,minmax(140px,1fr))}}
.daycol{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:10px;min-width:0}
.daycol.istoday{border-color:var(--royal-blue);box-shadow:0 0 0 2px rgba(18,57,155,.12)}
.dayhead{font-size:12px;font-weight:800;color:var(--royal-blue);display:flex;align-items:center;gap:6px;padding-bottom:7px;margin-bottom:7px;border-bottom:1px solid var(--border)}
.dayhead .cnt{margin-inline-start:auto;background:var(--royal-blue);color:#fff;border-radius:20px;padding:1px 8px;font-size:10.5px}
.planrow{display:flex;align-items:center;gap:6px;background:var(--card);border-radius:8px;padding:6px 8px;margin-bottom:5px}
.planrow .s{font-size:10px;color:var(--muted)}
.xbtn{margin-inline-start:auto;background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px;padding:2px 4px;font-family:inherit}
.xbtn:hover{color:var(--red)}
.ordnum{font-size:10px;font-weight:800;color:#fff;background:var(--royal-blue);border-radius:50%;min-width:17px;height:17px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
.ordbtns{display:flex;flex-direction:column;line-height:1}
/* ⚠️ margin-inline-start:auto موروثة من .xbtn بتزق الأسهم على طرف
   العمود وبتقلب مكانهم بين العربي والإنجليزي — بنصفّرها */
.ordbtns .xbtn{font-size:9px;padding:2px 5px;margin-inline-start:0}
</style>
@endsection
