@extends('layouts.system')

@section('title', $g->displayName())

@php
    use App\Models\Channel;
    use App\Models\Client;

    // ⚠️ نصوص العقد الحرة (terms / note) عربي من العقد الأصلي ومالهاش
    // مقابل إنجليزي — بنعرضها في الواجهة العربية بس.
    $isRtl = app()->getLocale() === 'ar';

    $fmt = fn ($n) => number_format((float) $n);
    $manager = auth()->user()->isManager();
    $purchases = $branches->sum('purchases');
    $collections = $branches->sum('collections');
    $balance = $branches->sum('balance');
    $returns = $branches->sum('returns');

    // سامري إضافي (2026-08-06) — كله من الكولكشن المحمّل، صفر استعلامات زيادة
    $zonesCovered = $branches->pluck('zone_id')->filter()->unique()->count();
    $govsCovered = $branches->pluck('governorate')->filter()->unique()->count();
    $avgPurchases = $branches->count() > 0 ? $purchases / $branches->count() : 0;
    $topBranch = $branches->sortByDesc('purchases')->first();
    $govCounts = $branches->groupBy(fn ($b) => $b->governorateLabel())->map->count()->sortDesc();

    // التصنيفات: الليبل المترجم من الموديل نفسه + لون البادج بتاعه
    $catColorMap = ['danger' => 'red', 'watch' => 'orange', 'grow' => 'green', 'ok' => 'blue', 'idle' => 'muted', 'internal' => 'purple', 'credit' => 'violet'];
    $catData = $branches->groupBy('category')->map(fn ($grp, $key) => [
        'label' => $grp->first()->categoryLabel(),
        'count' => $grp->count(),
        'color' => $catColorMap[$key] ?? 'muted',
    ])->values();
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.groups') }}">← {{ __('client.all_chains') }}</a>
    @if ($manager)
        <button class="btn" onclick="openDlg('dlgEditG')">{{ __('client.edit_chain') }}</button>
        <button class="btn gold" onclick="openDlg('dlgAttach')">+ {{ __('client.attach_branches') }}</button>
    @endif
@endsection

@section('content')

<div style="margin-bottom:14px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    @if ($g->channel)
        <span class="badge {{ $g->channel->badgeClass() }}">🎯 {{ $g->channel->displayName() }}</span>
    @endif
    @if ($g->sub_channel)<span class="badge b-gray">{{ $g->subChannelLabel() }}</span>@endif
    {{-- ⚠️ **مفيش خصم ولا مسؤول على السلسلة.** قرار 2026-08-01:
         السلسلة مكان بنجمع فيه الفروع تحت اسم واحد عشان نشوف
         إجمالياتها. كل فرع عميل مستقل بعقده وخصمه ومسؤوله. --}}
    <span class="badge b-gray">{{ __('client.branch_count') }}: {{ $branches->count() }}</span>
    @if (! $g->active)<span class="badge b-red">{{ __('client.suspended') }}</span>@endif
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('client.branch_count') }}</div><div class="val">{{ $branches->count() }}</div>
        <div class="sub2">{{ __('client.branches_with_contract', ['count' => $contracts->count()]) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('report.total_purchases') }}</div>
        <div class="val" style="color:var(--primary)">{{ $fmt($purchases) }} {{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('client.collected') }}</div><div class="val pos">{{ $fmt($collections) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $purchases > 0 ? number_format($collections / $purchases * 100, 1) : 0 }}% {{ __('client.collection_rate') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('client.outstanding') }}</div>
        <div class="val {{ $balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($balance) }} {{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('client.returns') }}</div><div class="val mid">{{ $fmt($returns) }} {{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('report.sales_today') }}</div><div class="val pos">{{ $fmt($todaySales) }} {{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('client.zones_covered') }}</div><div class="val">{{ $zonesCovered }}</div>
        <div class="sub2">{{ __('client.governorates_covered', ['count' => $govsCovered]) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('client.avg_branch_purchases') }}</div>
        <div class="val" style="color:var(--primary)">{{ $fmt($avgPurchases) }} {{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('client.biggest_branch') }}</div>
        <div class="val" style="font-size:15px">{{ $topBranch?->displayName() ?? '—' }}</div>
        <div class="sub2">{{ $fmt($topBranch?->purchases ?? 0) }} {{ __('common.currency') }}</div></div>
</div>

<div class="grid2">
    <div class="card">
        <h3>🗺️ {{ __('client.chain_branches_map') }}
            <span class="side">{{ __('client.branch_located', ['count' => $branches->filter(fn ($b) => $b->hasLocation())->count()]) }}</span></h3>
        <div class="mapbox" id="mapGroup"></div>
    </div>
    <div class="card">
        <h3>{{ __('client.chain_monthly_movement') }}</h3>
        <div class="chartbox"><canvas id="chG"></canvas></div>
    </div>
</div>

<div class="grid2">
    <div class="card">
        <h3>🗾 {{ __('client.by_governorate') }}</h3>
        <div class="chartbox"><canvas id="chGov"></canvas></div>
    </div>
    <div class="card">
        <h3>🏷️ {{ __('client.category_split') }}</h3>
        <div class="chartbox"><canvas id="chCat"></canvas></div>
    </div>
</div>

<div class="card">
    <h3>🏆 {{ __('client.top_branches') }}</h3>
    <div class="chartbox"><canvas id="chTop"></canvas></div>
</div>

<div class="card">
    <h3>🏬 {{ __('client.branches') }} <span class="side">{{ __('client.branch_countable', ['count' => $branches->count()]) }}</span></h3>
    {{-- فلاتر + فريز + سورت (2026-08-06) — كله client-side، الداتا محمّلة أصلاً --}}
    <div class="searchbar">
        <input type="text" id="qBr" placeholder="🔍 {{ __('client.search_branch') }}" oninput="filterBranches()">
        <select id="fZone" onchange="filterBranches()">
            <option value="">{{ __('client.all_zones') }}</option>
            @foreach ($branches->map(fn ($b) => $b->zone?->displayName())->filter()->unique()->sort() as $zn)
                <option value="{{ $zn }}">{{ $zn }}</option>
            @endforeach
        </select>
        <select id="fCat" onchange="filterBranches()">
            <option value="">{{ __('client.all_categories') }}</option>
            @foreach ($branches->map(fn ($b) => $b->categoryLabel())->filter()->unique()->sort() as $cl)
                <option value="{{ $cl }}">{{ $cl }}</option>
            @endforeach
        </select>
        <select id="fCon" onchange="filterBranches()">
            <option value="">{{ __('client.contract') }}: {{ __('common.all') }}</option>
            <option value="1">{{ __('client.with_contract') }}</option>
            <option value="0">{{ __('client.without_contract') }}</option>
        </select>
        <span class="badge b-gray" id="brCount">{{ $branches->count() }}</span>
    </div>
    <div class="tablewrap" style="max-height:66vh;overflow-y:auto">
        <table id="brTbl">
            <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
            <tr>
                <th class="srt" data-k="name" data-t="s">{{ __('client.branch') }}<span class="arw"></span></th>
                <th class="srt" data-k="zone" data-t="s">{{ __('client.zone') }}<span class="arw"></span></th>
                <th class="srt" data-k="cat" data-t="s">{{ __('client.category') }}<span class="arw"></span></th>
                <th class="srt" data-k="disc" data-t="n">{{ __('client.discount') }}<span class="arw"></span></th>
                <th class="srt" data-k="con" data-t="n">{{ __('client.contract') }}<span class="arw"></span></th>
                <th class="srt" data-k="pur" data-t="n">{{ __('client.purchases') }}<span class="arw"></span></th>
                <th class="srt" data-k="col" data-t="n">{{ __('client.collected') }}<span class="arw"></span></th>
                <th class="srt" data-k="bal" data-t="n">{{ __('client.balance') }}<span class="arw"></span></th>
                <th class="srt" data-k="act" data-t="s">{{ __('client.last_activity') }}<span class="arw"></span></th>
                @if ($manager)<th></th>@endif
            </tr>
            </thead>
            <tbody>
            @forelse ($branches as $b)
                <tr data-txt="{{ $b->displayName() }} {{ $b->address }}"
                    data-name="{{ mb_strtolower($b->displayName()) }}"
                    data-zone="{{ $b->zone?->displayName() ?? '' }}"
                    data-cat="{{ $b->categoryLabel() }}"
                    data-disc="{{ round($b->effectiveDiscount() * 100, 2) }}"
                    data-con="{{ $b->contract ? 1 : 0 }}"
                    data-pur="{{ (float) $b->purchases }}" data-col="{{ (float) $b->collections }}"
                    data-bal="{{ (float) $b->balance }}"
                    data-act="{{ $b->last_activity_at?->format('Y-m-d') ?? '' }}">
                    <td onclick="location.href='{{ route('erp.clients.show', $b) }}'" style="cursor:pointer">
                        {{-- ⚠️ اسم السلسلة من `$g` مش من `$b->fullName()` —
                             الـ199 صف كلهم نفس السلسلة، و`fullName()` كانت
                             هتعمل lazy load للمجموعة لكل صف. --}}
                        <b><span style="color:var(--muted);font-weight:600">{{ $g->displayName() }} — </span>{{ $b->displayName() }}</b>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $b->address }}</span>
                    </td>
                    <td style="color:var(--muted)">{{ $b->zone?->displayName() ?? '—' }}</td>
                    <td><span class="badge {{ $b->categoryClass() }}">{{ $b->categoryLabel() }}</span></td>
                    <td class="num">{{ number_format($b->effectiveDiscount() * 100, 1) }}%
                        <br><span style="font-size:10px;color:var(--muted)">{{ $b->discountSource() }}</span></td>
                    <td>
                        @if ($b->contract)
                            <span class="badge b-green">{{ $b->contract->typeLabel() ?: __('client.contract') }}</span>
                            @if ($isRtl && $b->contract->terms)
                                <br><span style="font-size:10px;color:var(--muted)">{{ $b->contract->terms }}</span>
                            @elseif ($b->contract->paymentDays() !== null)
                                <br><span style="font-size:10px;color:var(--muted)">
                                    {{ __('client.days_countable', ['count' => $b->contract->paymentDays()]) }}
                                </span>
                            @endif
                        @else <span class="badge b-gray">—</span> @endif
                    </td>
                    <td class="num">{{ $fmt($b->purchases) }}</td>
                    <td class="num pos">{{ $fmt($b->collections) }}</td>
                    <td class="num {{ $b->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($b->balance) }}</td>
                    <td class="num">{{ $b->last_activity_at?->format('Y-m-d') ?? '—' }}</td>
                    @if ($manager)
                        <td>
                            <form method="POST" action="{{ route('erp.groups.attach', $g) }}" style="display:inline"
                                  onsubmit="return confirm('{{ __('client.confirm_detach') }}')">
                                @csrf
                                <input type="hidden" name="action" value="detach">
                                <input type="hidden" name="client_ids[]" value="{{ $b->id }}">
                                <button class="btn sm red" type="submit">{{ __('client.detach') }}</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $manager ? 10 : 9 }}" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('client.no_branches') }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<style>
    #brTbl th.srt{cursor:pointer;user-select:none;white-space:nowrap}
    #brTbl th.srt:hover{color:var(--primary)}
    #brTbl th.srt .arw{font-size:9px;margin-inline-start:4px;color:var(--primary)}
</style>

@if ($contracts->isNotEmpty())
<div class="card">
    <h3>📜 {{ __('client.chain_contracts') }} <span class="side">{{ __('client.contract_countable', ['count' => $contracts->count()]) }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr><th>{{ __('client.branch') }}</th><th>{{ __('client.chain_in_contract') }}</th><th>{{ __('client.type') }}</th><th>{{ __('client.discount') }}</th><th>{{ __('client.payment_terms') }}</th><th>{{ __('client.expires_on') }}</th><th>{{ __('common.notes') }}</th></tr>
            @foreach ($contracts as $b)
                <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $b) }}'">
                    <td><b><span style="color:var(--muted);font-weight:600">{{ $g->displayName() }} — </span>{{ $b->displayName() }}</b></td>
                    <td>{{ $b->contract->displayChain() ?: '—' }}</td>
                    <td><span class="badge b-blue">{{ $b->contract->typeLabel() ?: '—' }}</span></td>
                    <td class="num">{{ number_format($b->contract->discount * 100, 1) }}%</td>
                    <td>
                        @if ($isRtl)
                            {{ $b->contract->terms ?: '—' }}
                        @else
                            {{ $b->contract->paymentDays() !== null
                                ? __('client.days_countable', ['count' => $b->contract->paymentDays()])
                                : '—' }}
                        @endif
                    </td>
                    <td class="num">
                        @if ($b->contract->ends_at)
                            <span class="badge {{ $b->contract->isExpiring() ? 'b-orange' : 'b-gray' }}">
                                {{ $b->contract->ends_at->format('Y-m-d') }}
                            </span>
                        @else — @endif
                    </td>
                    <td style="white-space:normal;max-width:240px;color:var(--muted);font-size:11px">
                        {{ $isRtl ? ($b->contract->note ?: '—') : '—' }}
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

@if ($manager)
<dialog id="dlgEditG">
    <form class="dlg" method="POST" action="{{ route('erp.groups.update', $g) }}">
        @csrf @method('PUT')
        <h4>{{ __('common.edit_named', ['name' => $g->displayName()]) }}</h4>
        <div class="frow">
            {{-- ⚠️ **الإنجليزي الأول — زي فورم العميل بالظبط.**
                 اللي بيدخل الداتا بيتعلّم مكان الخانة بعينه، وقلب
                 الترتيب بين شاشة وشاشة بيخلّيه يكتب العربي في خانة
                 الإنجليزي وميلاحظش. --}}
            <div>
                <label class="f">{{ __('client.chain_name_en') }}</label>
                <input type="text" name="name_en" dir="ltr" maxlength="120" style="width:100%"
                       value="{{ old('name_en', $g->name_en) }}" placeholder="{{ __('client.chain_name_en_ph') }}">
            </div>
            <div>
                <label class="f">{{ __('client.chain_name_ar') }} <b class="req-star">*</b></label>
                <input type="text" name="name" required maxlength="120" style="width:100%"
                       class="{{ $errors->has('name') ? 'bad' : '' }}"
                       value="{{ old('name', $g->name) }}" placeholder="{{ __('client.chain_name_ar_ph') }}">
                @error('name')<div class="errline">{{ $message }}</div>@enderror
            </div>
            <div><label class="f">{{ __('client.channel') }}</label>
                <select name="channel_id" style="width:100%">
                    <option value="">— {{ __('common.none') }} —</option>
                    @foreach (\App\Models\Channel::orderBy('id')->get() as $ch)
                        <option value="{{ $ch->id }}" @selected($g->channel_id === $ch->id)>{{ $ch->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('client.segment') }}</label>
                <select name="sub_channel" style="width:100%">
                    <option value="">— {{ __('client.not_applicable') }} —</option>
                    @foreach (Channel::SUB_CHANNELS as $k => $lbl)
                        <option value="{{ $k }}" @selected($g->sub_channel === $k)>{{ __('enums.sub_channel.'.$k) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        {{-- ═════ خصم على كل الفروع ═════ --}}
        <div style="border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:12px;background:var(--card2)">
            <label class="f">{{ __('client.chain_apply_discount') }} %</label>
            <input type="number" name="apply_discount" step="0.5" min="0" max="100"
                   style="width:100%" value="" placeholder="{{ __('client.chain_apply_discount_ph') }}">
            <div style="font-size:11px;color:var(--muted);margin-top:5px">
                {{ __('client.chain_apply_discount_hint', ['count' => $g->clients()->count()]) }}
            </div>
        </div>

        <div class="alert info" style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('client.chain_is_a_grouping') }}</span>
        </div>
        <div><label class="f">{{ __('common.notes') }}</label><textarea name="notes" rows="2" style="width:100%">{{ $g->notes }}</textarea></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;margin-top:8px">
            <input type="checkbox" name="active" value="1" @checked($g->active)> {{ __('client.chain_active') }}
        </label>
        <div style="display:flex;gap:8px;justify-content:space-between;margin-top:14px">
            <button class="btn red" type="button" onclick="document.getElementById('formDelG').submit()">{{ __('client.delete_chain') }}</button>
            <div style="display:flex;gap:8px">
                <button class="btn" type="button" onclick="closeDlg('dlgEditG')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('common.save') }}</button>
            </div>
        </div>
    </form>
</dialog>

<form id="formDelG" method="POST" action="{{ route('erp.groups.destroy', $g) }}"
      onsubmit="return confirm('{{ __('client.confirm_delete_chain') }}')">
    @csrf @method('DELETE')
</form>

<dialog id="dlgAttach">
    <form class="dlg" method="POST" action="{{ route('erp.groups.attach', $g) }}">
        @csrf
        <input type="hidden" name="action" value="attach">
        <h4>{{ __('client.attach_branches_to', ['chain' => $g->displayName()]) }}</h4>
        <input type="text" id="qAtt" placeholder="🔍 {{ __('client.find_client') }}" oninput="filterAttach()" style="width:100%;margin-bottom:10px">
        <div style="max-height:46vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
            <table>
                @foreach (Client::whereNull('group_id')->where('category', '!=', 'internal')->orderBy('name')->get() as $c)
                    <tr data-txt="{{ $c->displayName() }}">
                        <td style="width:34px"><input type="checkbox" name="client_ids[]" value="{{ $c->id }}"></td>
                        <td>{{ $c->displayName() }}<br><span style="font-size:10px;color:var(--muted)">{{ $c->address }}</span></td>
                        <td class="num" style="font-size:11px">{{ $fmt($c->purchases) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgAttach')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('client.attach_selected') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
@php
    $pins = $branches->filter(fn ($b) => $b->lat && $b->lng)->map(fn ($b) => [
        'lat' => (float) $b->lat,
        'lng' => (float) $b->lng,
        'title' => $b->displayName(),
        'subtitle' => ($b->zone?->displayName() ?? '').' • '.__('client.balance').' '.number_format((float) $b->balance).' '.__('common.currency'),
        'type' => 'client',
    ])->values();

    $mLabels = $monthly->pluck('m')->all();
    $mSales = $monthly->map(fn ($r) => round((float) $r->sales))->all();
    $mColl = $monthly->map(fn ($r) => round((float) $r->coll))->all();
@endphp
<script>
promaxMap('mapGroup', {!! json_encode($pins, JSON_UNESCAPED_UNICODE) !!});

new Chart(document.getElementById('chG'), {
    type: 'bar',
    data: {
        labels: {!! json_encode($mLabels) !!},
        datasets: [
            { label:{!! json_encode(__('report.sales'), JSON_UNESCAPED_UNICODE) !!}, data:{!! json_encode($mSales) !!}, backgroundColor: BRAND.royal, borderRadius:6, maxBarThickness:30 },
            { label:{!! json_encode(__('report.collections'), JSON_UNESCAPED_UNICODE) !!}, data:{!! json_encode($mColl) !!}, backgroundColor: BRAND.green, borderRadius:6, maxBarThickness:30 },
        ],
    },
    options: { plugins:{ legend:{ position:'bottom' } }, scales: AXES },
});

// ═══ شارتات السامري (2026-08-06) ═══
@php
    $top = $branches->sortByDesc('purchases')->take(10)->values();
@endphp
new Chart(document.getElementById('chGov'), {
    type: 'bar',
    data: {
        labels: {!! json_encode($govCounts->keys(), JSON_UNESCAPED_UNICODE) !!},
        datasets: [{ label: {!! json_encode(__('client.branch_count'), JSON_UNESCAPED_UNICODE) !!},
                     data: {!! json_encode($govCounts->values()) !!},
                     backgroundColor: BRAND.royal, borderRadius: 6, maxBarThickness: 34 }],
    },
    options: { plugins: { legend: { display: false } }, scales: AXES },
});

new Chart(document.getElementById('chCat'), {
    type: 'doughnut',
    data: {
        labels: {!! json_encode($catData->pluck('label'), JSON_UNESCAPED_UNICODE) !!},
        datasets: [{ data: {!! json_encode($catData->pluck('count')) !!},
                     backgroundColor: {!! json_encode($catData->pluck('color')) !!}.map(c => BRAND[c]) }],
    },
    options: { plugins: { legend: { position: 'bottom' } } },
});

new Chart(document.getElementById('chTop'), {
    type: 'bar',
    data: {
        labels: {!! json_encode($top->map(fn ($b) => $b->displayName())->values(), JSON_UNESCAPED_UNICODE) !!},
        datasets: [{ label: {!! json_encode(__('client.purchases'), JSON_UNESCAPED_UNICODE) !!},
                     data: {!! json_encode($top->map(fn ($b) => round((float) $b->purchases))->values()) !!},
                     backgroundColor: BRAND.purple, borderRadius: 6, maxBarThickness: 22 }],
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: AXES },
});

// ═══ فلترة مركبة: بحث + زون + تصنيف + عقد ═══
function filterBranches() {
    const q = document.getElementById('qBr').value.trim().toLowerCase();
    const zn = document.getElementById('fZone').value;
    const ct = document.getElementById('fCat').value;
    const cn = document.getElementById('fCon').value;
    let shown = 0;

    document.querySelectorAll('#brTbl tbody tr[data-txt]').forEach(tr => {
        const ok = (!q || tr.dataset.txt.toLowerCase().includes(q))
            && (!zn || tr.dataset.zone === zn)
            && (!ct || tr.dataset.cat === ct)
            && (cn === '' || tr.dataset.con === cn);
        tr.style.display = ok ? '' : 'none';
        if (ok) shown++;
    });

    document.getElementById('brCount').textContent = shown;
}

// ═══ سورت بالضغط على العمود — نفس نمط صفحة السلاسل ═══
(function () {
    const tbl = document.getElementById('brTbl');
    const tbody = tbl.tBodies[0];
    let cur = null, dir = 1;

    tbl.querySelectorAll('th.srt').forEach(function (th) {
        th.addEventListener('click', function () {
            const k = th.dataset.k, numeric = th.dataset.t === 'n';
            dir = (k === cur) ? -dir : (numeric ? -1 : 1);
            cur = k;

            const rows = Array.from(tbody.rows).filter(r => r.dataset[k] !== undefined);
            rows.sort((a, b) => dir * (numeric
                ? (parseFloat(a.dataset[k]) || 0) - (parseFloat(b.dataset[k]) || 0)
                : a.dataset[k].localeCompare(b.dataset[k], ['ar', 'en'])));
            rows.forEach(r => tbody.appendChild(r));

            tbl.querySelectorAll('th.srt .arw').forEach(s => s.textContent = '');
            th.querySelector('.arw').textContent = dir === -1 ? '▼' : '▲';
        });
    });
})();
function filterAttach() {
    const q = document.getElementById('qAtt').value.trim().toLowerCase();
    document.querySelectorAll('#dlgAttach tr[data-txt]').forEach(tr => {
        tr.style.display = (!q || tr.dataset.txt.toLowerCase().includes(q)) ? '' : 'none';
    });
}
</script>
@endsection
