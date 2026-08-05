@extends('layouts.system')

{{--
    تفعيل العملاء المستوردين.

    ⚠️ **الشاشة دي هي البوابة بين الداتا المستوردة والشغل.** كل فرع
    جاي من الشيتات بيوصل هنا موقوف، ومن هنا بس بيبقى شغّال.
--}}

@php
    use App\Support\Governorates;

    $fmt = fn ($n) => number_format((float) $n);
    $f = $filters;

    /**
     * رأس عمود قابل للترتيب.
     *
     * ⚠️ بيحافظ على كل الفلاتر — ترتيب بيمسح البحث والسلسلة كان
     * هيخلّي المستخدم يعيد فلترته بعد كل ضغطة. الضغط على نفس العمود
     * بيقلب الاتجاه.
     */
    $sortLink = function (string $key, string $label) use ($f) {
        $current = ($f['sort'] ?? '') ?: 'code';
        $active = $current === $key;
        $dir = $active && ($f['dir'] ?? 'asc') !== 'desc' ? 'desc' : 'asc';
        // ⚠️ `page => 1` — الترتيب الجديد ترقيمه مختلف، والفضل على
        // صفحة 7 بتاعة الترتيب القديم بيوري صفحة فاضية.
        $url = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => 1]);
        $arrow = $active ? (($f['dir'] ?? 'asc') === 'desc' ? ' ↓' : ' ↑') : '';

        return '<a href="'.e($url).'" style="color:inherit;text-decoration:none">'
            .e($label).$arrow.'</a>';
    };

    $canStop = auth()->user()->canDecideOps();
    $showingActive = ($f['status'] ?? 'waiting') !== 'waiting';
@endphp

@section('title', __('client.activate_clients'))

@section('content')

{{-- الكروت نفسها فلاتر — «مستني» و«شغّال» بضغطة --}}
<div class="kpis">
    <a class="kpi" href="{{ route('erp.clients.activate', ['status' => 'waiting']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['status'] ?? 'waiting') === 'waiting' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">{{ __('client.waiting_activation') }}</div>
        <div class="val mid">{{ $fmt($waiting) }}</div>
        <div class="sub2">{{ __('client.waiting_hint') }}</div>
    </a>
    <a class="kpi" href="{{ route('erp.clients.activate', ['status' => 'active']) }}"
       style="text-decoration:none;color:inherit;{{ ($f['status'] ?? '') === 'active' ? 'outline:2px solid var(--royal-blue)' : '' }}">
        <div class="lbl">{{ __('client.live_clients') }}</div>
        <div class="val pos">{{ $fmt($live) }}</div>
        <div class="sub2">{{ __('client.tap_to_review') }}</div>
    </a>
</div>

<div class="card">
    {{-- ترتيب الفلاتر (2026-08-05): بحث ← الحالة ← السلسلة ← المحافظة ← الناقص --}}
    <form class="searchbar" method="GET">
        <input type="text" name="q" value="{{ $f['q'] ?? '' }}"
               placeholder="{{ __('client.search_ph') }}" style="flex:1;min-width:200px">

        <select name="status" style="min-width:130px">
            <option value="waiting" @selected(($f['status'] ?? 'waiting') === 'waiting')>{{ __('client.status_waiting') }}</option>
            <option value="active" @selected(($f['status'] ?? '') === 'active')>{{ __('client.status_active') }}</option>
            <option value="all" @selected(($f['status'] ?? '') === 'all')>{{ __('client.status_all') }}</option>
        </select>

        <select name="group" style="min-width:180px">
            <option value="">— {{ __('nav.chains') }} —</option>
            @foreach ($groups as $g)
                <option value="{{ $g->id }}" @selected((int) ($f['group'] ?? 0) === $g->id)>
                    {{ $g->displayName() }} ({{ $g->off_count }})
                </option>
            @endforeach
        </select>

        <select name="gov" style="min-width:150px">
            <option value="">— {{ __('geo.governorate') }} —</option>
            @foreach (Governorates::KEYS as $k)
                <option value="{{ $k }}" @selected(($f['gov'] ?? '') === $k)>{{ Governorates::label($k) }}</option>
            @endforeach
        </select>

        <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;white-space:nowrap">
            <input type="checkbox" name="incomplete" value="1" @checked($f['incomplete'] ?? false)>
            {{ __('client.incomplete_only') }}
        </label>

        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.clients.activate') }}">{{ __('common.clear') }}</a>
    </form>

    {{-- ⚠️ **عدادات المناطق بنفس فلاتر القايمة** — كل بادج فلتر بضغطة.
         محافظة مختارة؟ بتظهر مناطقها بعدادها تحتها. --}}
    @if ($govCounts->isNotEmpty())
        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:12px">
            <span style="font-size:11.5px;font-weight:800;color:var(--muted)">{{ __('client.geo_counts') }}:</span>
            @foreach ($govCounts as $g => $n)
                <a class="badge {{ ($f['gov'] ?? '') === $g ? 'b-blue' : 'b-gray' }}"
                   style="text-decoration:none"
                   href="{{ request()->fullUrlWithQuery(['gov' => ($f['gov'] ?? '') === $g ? null : $g, 'page' => 1]) }}">
                    {{ $g ? Governorates::label($g) : '—' }} · <b>{{ $fmt($n) }}</b>
                </a>
            @endforeach
        </div>
    @endif
    @if ($zoneCounts->isNotEmpty())
        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:12px">
            <span style="font-size:11.5px;font-weight:800;color:var(--muted)">{{ __('client.zone') }}:</span>
            @foreach ($zoneCounts as $z)
                <span class="badge b-purple">{{ $z['zone']?->displayName() ?? '—' }} · <b>{{ $fmt($z['n']) }}</b></span>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('erp.clients.activate.do') }}" id="actForm">
        @csrf
        @if ($errors->any())
            <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
                @foreach ($errors->all() as $msg)
                    <div class="errline" style="margin:0">{{ $msg }}</div>
                @endforeach
            </div>
        @endif

        {{-- ⚠️ **القيم دي بتتطبّق على المعلّم عليهم كلهم.** التفعيل
             واحد واحد بـ455 فرع شغل شهر — والفلترة بسلسلة ومحافظة
             بتخلّي الدفعة متجانسة فعلاً. --}}
        <div class="card" style="background:var(--card2);margin-bottom:12px">
            <h3 style="font-size:13px">⚡ {{ __('client.apply_to_selected') }}</h3>
            <div class="frow">
                <div>
                    <label class="f">{{ __('client.zone') }} <b class="req-star">*</b></label>
                    @include('partials._zone_select', [
                        'zones' => $zones,
                        'name' => 'zone_id',
                        'selected' => old('zone_id'),
                        'placeholder' => '— '.__('client.keep_current').' —',
                        'style' => 'width:100%',
                    ])
                    <div style="font-size:11px;color:var(--muted);margin-top:4px">
                        {{ __('client.zone_required_hint') }}
                    </div>
                </div>
                <div>
                    <label class="f">{{ __('ops.rep') }}</label>
                    <select name="rep_id" style="width:100%">
                        <option value="">— {{ __('client.keep_current') }} —</option>
                        @foreach ($reps as $r)
                            <option value="{{ $r->id }}">{{ $r->displayName() }} · {{ $r->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="f">{{ __('client.account_manager') }}</label>
                    <select name="manager_id" style="width:100%">
                        <option value="">— {{ __('client.keep_current') }} —</option>
                        @foreach ($managers as $m)
                            <option value="{{ $m->id }}">{{ $m->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="f">{{ __('client.price_list') }}</label>
                    <select name="price_list" style="width:100%">
                        <option value="">— {{ __('client.keep_current') }} —</option>
                        @foreach ($lists as $k => $lbl)
                            <option value="{{ $k }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:12px">
                <span style="font-size:12.5px;color:var(--muted)">
                    <b id="selCount">0</b> {{ __('client.selected') }}
                </span>
                @if (\App\Support\Access::action(auth()->user(), 'act.clients.activate'))<button class="btn gold" type="submit" id="actBtn" disabled>
                    ✅ {{ __('client.activate_apply') }}
                </button>@endif
            </div>
        </div>

        <div class="tablewrap">
            <table>
                <tr>
                    <th style="width:34px">
                        {{-- الشغّال بقى بيتعلّم عليه برضو — للتوزيع
                             (منطقة/مندوب/مدير) من غير ما حالته تتلمس --}}
                        <input type="checkbox" id="allBox" onchange="toggleAll(this)">
                    </th>
                    <th>{!! $sortLink('code', __('common.code')) !!}</th>
                    <th>{!! $sortLink('name', __('client.client')) !!}</th>
                    <th>{!! $sortLink('group', __('nav.chains')) !!}</th>
                    <th>{!! $sortLink('gov', __('geo.governorate')) !!}</th>
                    <th>{!! $sortLink('zone', __('client.zone')) !!}</th>
                    <th>{{ __('common.address') }}</th>
                    <th>{{ __('common.phone') }}</th>
                    @if ($showingActive)
                        <th>{!! $sortLink('status', __('common.status')) !!}</th>
                    @endif
                    <th>{{ __('client.missing') }}</th>
                </tr>
                @forelse ($clients as $c)
                    @php
                        // ⚠️ الناقص بيتعرض صريح — الفرع اللي هيتفعّل
                        // من غير عنوان المندوب مش هيلاقيه، ومن غير
                        // تليفون محدش هيقدر يتصل قبل ما يروح.
                        $miss = [];
                        if (! $c->name_en) $miss[] = __('common.name_en');
                        if (! $c->address && ! $c->location_url) $miss[] = __('common.address');
                        if (! $c->phone) $miss[] = __('common.phone');
                        if (! $c->governorate) $miss[] = __('geo.governorate');
                        if (! $c->zone_id) $miss[] = __('client.zone');
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" name="ids[]" value="{{ $c->id }}"
                                   class="rowBox" onchange="syncCount()">
                        </td>
                        <td class="num"><b>{{ $c->code }}</b></td>
                        <td>
                            <a href="{{ route('erp.clients.show', $c) }}"><b>{{ $c->displayName() }}</b></a>
                            @if ($c->name_en && $c->name !== $c->name_en)
                                <div style="font-size:10.5px;color:var(--muted)">{{ $c->name }}</div>
                            @endif
                        </td>
                        <td><span class="badge b-purple">{{ $c->group?->displayName() ?? '—' }}</span></td>
                        <td style="font-size:11.5px">{{ $c->governorateLabel() ?: '—' }}</td>
                        <td style="font-size:11.5px">{{ $c->zone?->displayName() ?? '—' }}</td>
                        <td style="font-size:11px;color:var(--muted);max-width:280px">
                            {{ Str::limit($c->address ?? '', 60) ?: ($c->location_url ? '🗺️' : '—') }}
                        </td>
                        <td class="num" dir="ltr" style="font-size:11.5px">{{ $c->phone ?: '—' }}</td>
                        @if ($showingActive)
                            <td>
                                @if ($c->status === 'active')
                                    <span class="badge b-green">{{ __('client.status_active') }}</span>
                                    @if ($canStop)
                                        {{-- ⚠️ الزرار بيبعت الفورم الصغير بتاعه —
                                             مش جوه فورم التفعيل عشان مايبعتوش مع
                                             بعض. `form` attribute بيربطه بفورم
                                             مستقل معرّف تحت الجدول. --}}
                                        @if (\App\Support\Access::action(auth()->user(), 'act.clients.activate'))<button class="btn sm" type="submit"
                                                form="deact-{{ $c->id }}"
                                                onclick="return confirm(DEACT_CONFIRM)">⏸ {{ __('client.deactivate') }}</button>@endif
                                    @endif
                                @else
                                    <span class="badge b-orange">{{ __('client.status_waiting') }}</span>
                                @endif
                            </td>
                        @endif
                        <td>
                            @if ($miss === [])
                                <span class="badge b-green">{{ __('client.complete') }}</span>
                            @else
                                <span class="badge b-orange" style="font-size:10px">
                                    {{ implode(' · ', $miss) }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $showingActive ? 10 : 9 }}" style="text-align:center;color:var(--muted);padding:28px">
                        {{ __('client.no_waiting') }}
                    </td></tr>
                @endforelse
            </table>
        </div>
    </form>

    {{-- ⚠️ **فورمات الإيقاف بره فورم التفعيل.** فورم جوه فورم HTML
         مش صحيح والمتصفح بيبعت الغلط — كل زرار مربوط بفورمه بخاصية
         `form`. --}}
    @if ($canStop && $showingActive)
        @foreach ($clients as $c)
            @if ($c->status === 'active')
                <form id="deact-{{ $c->id }}" method="POST"
                      action="{{ route('erp.clients.deactivate', $c) }}">
                    @csrf
                </form>
            @endif
        @endforeach
    @endif

    @include('partials._pagination', ['p' => $clients])
</div>

@endsection

@section('scripts')
<script>
const DEACT_CONFIRM = @json(__('client.deactivate_confirm'));

/**
 * ⚠️ **«علّم على الكل» بتعلّم على الصفحة دي بس.** الجدول مقسّم 50
 * صف؛ لو التعليم شمل اللي مش ظاهر، ضغطة واحدة كانت هتفعّل 455 فرع
 * محدش راجعهم — وهو بالظبط اللي الشاشة دي اتعملت تمنعه.
 */
function toggleAll(box) {
    document.querySelectorAll('.rowBox').forEach(b => { b.checked = box.checked; });
    syncCount();
}

function syncCount() {
    const n = document.querySelectorAll('.rowBox:checked').length;

    document.getElementById('selCount').textContent = n;
    document.getElementById('actBtn').disabled = n === 0;

    const all = document.getElementById('allBox');
    const total = document.querySelectorAll('.rowBox').length;

    if (all) {
        all.checked = n > 0 && n === total;
        all.indeterminate = n > 0 && n < total;
    }
}

syncCount();
</script>
@endsection
