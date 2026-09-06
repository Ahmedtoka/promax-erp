@extends('layouts.system')

@section('title', __('lead.page'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $money = fn ($n) => number_format((float) $n, 2);

    // ⚠️ قوايم الأوبشنز بتتبني هنا في PHP — البليد مابيشتغلش جوه
    // الجافاسكريبت، والدالة اللي بتفتح المودال محتاجة الـ HTML جاهز.
    // ⚠️ مجمّعة بالمحافظة زي باقي السيستم — optgroup لكل محافظة
    // بالترتيب الجغرافي، والـ«بدون» في الآخر.
    $zoneOptions = '<option value="">—</option>';
    $zByGov = $zones->groupBy(fn ($z) => $z->governorate ?: '_none');
    foreach (array_merge(\App\Support\Governorates::keys(), ['_none']) as $gk) {
        $zGroup = $zByGov->get($gk);
        if (! $zGroup || $zGroup->isEmpty()) {
            continue;
        }
        $govLabel = $gk === '_none' ? __('geo.no_governorate') : \App\Support\Governorates::label($gk);
        $zoneOptions .= '<optgroup label="'.e($govLabel).'">';
        foreach ($zGroup->sortBy(fn ($z) => $z->displayName()) as $z) {
            $zoneOptions .= '<option value="'.(int) $z->id.'">'.e($z->displayName()).'</option>';
        }
        $zoneOptions .= '</optgroup>';
    }
    $channelOptions = '<option value="">—</option>';
    foreach ($channels as $c) {
        $channelOptions .= '<option value="'.(int) $c->id.'">'.e($c->displayName()).'</option>';
    }
    $repOptions = '<option value="">—</option>';
    foreach ($reps as $r) {
        $repOptions .= '<option value="'.(int) $r->id.'">'.e($r->displayName()).'</option>';
    }
@endphp

@section('actions')
    @if ($canConvert)
        {{-- فحص الشبيهات (٢٦/٨): هل المحتمل ده عميل عندي فعلاً؟ --}}
        <form method="POST" action="{{ route('erp.leads.dupcheck') }}" style="display:inline"
              onsubmit="this.querySelector('button').disabled = true">
            @csrf
            <button class="btn" type="submit">🔍 {{ __('lead.dup_check_btn') }}</button>
        </form>
        @if ($dupPending > 0)
            <a class="btn" href="{{ route('erp.leads', ['dup' => 1]) }}">
                ⚠️ {{ $dupPending }} {{ __('lead.dup_pending') }}</a>
        @endif
    @endif
    @if (auth()->user()->role === 'admin')
        {{-- تصفير التوزيعات (٢٦/٨) — «خلي الكل بدون مناديب وأبدأ أوزع» --}}
        <form method="POST" action="{{ route('erp.leads.clearassign') }}" style="display:inline"
              onsubmit="return confirm({!! json_encode(__('lead.clear_confirm'), JSON_UNESCAPED_UNICODE) !!})">
            @csrf
            <button class="btn" type="submit">🧹 {{ __('lead.clear_btn') }}</button>
        </form>
    @endif
    <button class="btn gold" onclick="openDlg('dlgNewLead')">➕ {{ __('lead.new_lead') }}</button>
@endsection

@section('content')

<div class="card">
    <h3>🎯 {{ __('lead.page') }} <span class="side">{{ __('lead.page_sub') }}</span></h3>

    <form method="GET" action="{{ route('erp.leads') }}" class="searchbar">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('common.search') }}">
        <select name="status">
            <option value="">{{ __('common.all') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('lead.status_'.$s) }}</option>
            @endforeach
        </select>
        @include('partials._zone_select', [
            'zones' => $zones,
            'name' => 'zone',
            'selected' => $filters['zone'] ?? null,
            'placeholder' => __('common.all'),
        ])
        <select name="rep">
            <option value="">{{ __('common.all') }}</option>
            @foreach ($reps as $r)
                <option value="{{ $r->id }}" @selected(($filters['rep'] ?? '') == $r->id)>{{ $r->displayName() }}</option>
            @endforeach
        </select>
        <select name="source">
            <option value="">{{ __('lead.all_sources') }}</option>
            @foreach ($sources as $s)
                <option value="{{ $s }}" @selected(($filters['source'] ?? '') === $s)>{{ __('lead.source_'.$s) }}</option>
            @endforeach
        </select>
        {{-- فلتر القسم/النشاط (٢٦/٨) — «كل الجيمات في الدقي» --}}
        <select name="cat">
            <option value="">{{ __('lead.all_cats') }}</option>
            @foreach ($cats as $c)
                <option value="{{ $c->category_raw }}" @selected(($filters['cat'] ?? '') === $c->category_raw)>
                    {{ $c->category_raw }} ({{ $c->n }})</option>
            @endforeach
        </select>
        <select name="sort">
            <option value="score" @selected($sort === 'score')>{{ __('lead.sort_score') }}</option>
            <option value="recent" @selected($sort === 'recent')>{{ __('lead.sort_recent') }}</option>
        </select>
        <label style="display:inline-flex;align-items:center;gap:5px;font-size:12px;white-space:nowrap">
            <input type="checkbox" name="unassigned" value="1" @checked($filters['unassigned'] ?? false)>
            {{ __('lead.only_unassigned') }}
        </label>
        <button class="btn">{{ __('common.filter') }}</button>
    </form>
</div>

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('lead.open_leads') }}</div>
        <div class="val">{{ $fmt($stats['open']) }}</div>
        <div class="sub2">{{ __('lead.page') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('lead.top_score') }}</div>
        <div class="val pos">{{ $fmt($stats['strong']) }}</div>
        <div class="sub2">{{ __('lead.top_score_note') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('lead.overdue') }}</div>
        <div class="val {{ $stats['overdue'] > 0 ? 'neg' : 'pos' }}">{{ $fmt($stats['overdue']) }}</div>
        <div class="sub2">{{ __('lead.overdue_note') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('lead.won_leads') }}</div>
        <div class="val pos">{{ $fmt($stats['won']) }}</div>
        <div class="sub2">{{ __('lead.status_won') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('lead.lost_leads') }}</div>
        <div class="val">{{ $fmt($stats['lost']) }}</div>
        <div class="sub2">{{ __('lead.status_lost') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('lead.pipeline') }}</div>
        <div class="val num">{{ $money($stats['pipeline']) }}</div>
        <div class="sub2">{{ __('common.currency') }}</div>
    </div>
    {{-- المحفظة (بايبلاين ٢٦/٨): متوزع على مناديب ولا لأ --}}
    <div class="kpi">
        <div class="lbl">{{ __('lead.k_assigned') }}</div>
        <div class="val pos">{{ $fmt($dist->assigned ?? 0) }}</div>
        <div class="sub2">{{ __('lead.of_total', ['t' => number_format($dist->total ?? 0)]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('lead.k_unassigned') }}</div>
        <div class="val {{ ($dist->total ?? 0) - ($dist->assigned ?? 0) > 0 ? 'mid' : 'pos' }}">
            {{ $fmt(($dist->total ?? 0) - ($dist->assigned ?? 0)) }}</div>
        <div class="sub2">{{ __('lead.k_unassigned_note') }}</div>
    </div>
</div>

{{-- ═══ خريطة المحفظة (بايبلاين ٢٦/٨) — كل النقط ملونة بالحالة،
     وبتسمع في نفس فلاتر الشاشة ═══ --}}
@if ($mapLeads->isNotEmpty())
<div class="card" style="margin-bottom:14px">
    <h3 style="margin:0 0 10px">🗺️ {{ __('lead.map_title') }}
        <span class="side">{{ number_format($mapLeads->count()) }} {{ __('lead.map_pts') }}</span></h3>
    {{-- شيبس المناطق (٢٦/٨) — إخفاء/إظهار نقط كل منطقة بضغطة --}}
    <div id="ldZoneChips" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px"></div>
    <div class="mapbox" id="ldMap" style="height:440px"></div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:11px;color:var(--muted)">
        @foreach (['new' => '#2563EB', 'contacted' => '#6B7280', 'visited' => '#7C3AED',
                   'negotiating' => '#B45309', 'won' => '#0F7A38', 'lost' => '#DC2626'] as $st => $cc)
            <span><i style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $cc }}"></i>
                {{ __('lead.status_'.$st) }}</span>
        @endforeach
        <span style="margin-inline-start:auto">⚪ {{ __('lead.map_unassigned_hint') }}</span>
    </div>
</div>
@endif

{{-- ═══ توزيعة المناطق — «خد N من المنطقة دي» ═══ --}}
@if ($zoneRows->isNotEmpty())
<div class="card" style="margin-bottom:14px">
    <h3 style="margin:0 0 10px">📍 {{ __('lead.zones_title') }}
        <span class="side">{{ __('lead.zones_hint') }}</span></h3>
    <div class="tablewrap" style="max-height:340px;overflow-y:auto">
        <table data-plain>
            <thead>
            <tr>
                <th style="text-align:start">{{ __('client.zone') }}</th>
                <th>{{ __('lead.z_total') }}</th>
                <th>{{ __('lead.z_open') }}</th>
                <th>{{ __('lead.z_unassigned') }}</th>
                <th>{{ __('lead.z_won') }}</th>
                {{-- متسكّن مع مين (٢٦/٨) --}}
                <th data-nosum style="text-align:start">{{ __('lead.z_with') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @php $zoneById = $zones->keyBy('id'); @endphp
            @foreach ($zoneRows as $zr)
                @php $z = $zr->zone_id !== null ? $zoneById->get($zr->zone_id) : null; @endphp
                {{-- الصف كليكابل (٢٦/٨) — بيفلتر الجدول تحت بالمنطقة دي --}}
                <tr class="ld-zrow" @if ($z !== null)
                        onclick="if (event.target.closest('button,form,a')) return; window.location = {{ json_encode(route('erp.leads', ['zone' => $z->id]), JSON_HEX_APOS | JSON_HEX_QUOT) }};"
                        style="cursor:pointer" title="{{ __('lead.zrow_hint') }}"
                    @endif>
                    <td style="text-align:start;font-weight:800">
                        {{ $z?->displayName() ?? __('lead.no_zone') }}
                        @if ($z !== null && ! $z->active)
                            <span class="badge b-gray" style="font-size:9px">{{ __('lead.zone_inactive') }}</span>
                        @endif
                    </td>
                    <td class="num">{{ number_format($zr->total) }}</td>
                    <td class="num">{{ number_format($zr->open_n) }}</td>
                    <td class="num {{ $zr->unassigned > 0 ? 'mid' : '' }}"><b>{{ number_format($zr->unassigned) }}</b></td>
                    <td class="num pos">{{ number_format($zr->won_n) }}</td>
                    {{-- متسكّن مع مين — كل مندوب وعدده --}}
                    <td style="text-align:start">
                        @php $zrReps = $zoneReps->get($zr->zone_id) ?? collect(); @endphp
                        @forelse ($zrReps as $rr)
                            <span class="badge b-blue" style="font-size:9.5px;margin:1px">
                                {{ $rr->rep_name }} ×{{ $rr->n }}</span>
                        @empty
                            <span style="color:var(--muted);font-size:11px">—</span>
                        @endforelse
                    </td>
                    <td>
                        @if ($canConvert && $zr->unassigned > 0 && $z !== null)
                            <button class="btn sm" type="button"
                                    onclick="ldOpenBulk({{ $z->id }}, {{ json_encode($z->displayName(), JSON_UNESCAPED_UNICODE) }}, {{ (int) $zr->unassigned }})">
                                🎯 {{ __('lead.bulk_btn') }}</button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    {{-- ═══ بار التسكين الجماعي (٢٦/٨): علّم على اللي عايزه + مندوب
         + Apply — الفورم منفصل والتشيك بوكسات مربوطة بيه بـform="" ═══ --}}
    @if ($canConvert)
        <form method="POST" action="{{ route('erp.leads.bulkset') }}" id="ldBulkForm"
              style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;
                     background:var(--blue-050,#E8F1FF);border:1px solid var(--royal-blue,#12399B);
                     border-radius:12px;padding:10px 14px">
            @csrf
            {{-- ═══ وضع «كل نتايج الفلتر» (١/٩): الفلاتر الحالية بتتبعت
                 مع الفورم عشان السيرفر يعيد بناء نفس الكويري لما
                 all_filtered=1 — أي فلتر جديد في الشاشة يتضاف هنا ═══ --}}
            <input type="hidden" name="all_filtered" id="ldAllFiltered" value="">
            @foreach (['status', 'zone', 'rep', 'mgr', 'search', 'source', 'cat', 'unassigned', 'dup'] as $fk)
                @if (($filters[$fk] ?? '') !== '' && $filters[$fk] !== null)
                    <input type="hidden" name="{{ $fk }}" value="{{ $filters[$fk] }}">
                @endif
            @endforeach
            <b style="font-size:12.5px">🎯 {{ __('lead.bulkset_title') }}</b>
            <span id="ldCkCount" class="badge b-blue">0</span>
            {{-- ⭐ التوزيع بالرول (٦/٩): الأدمن بينزّل على مدير قناة أو
                 مندوب، والمدير على مناديبه هو بس (القايمة أصلاً مقصوصة) --}}
            <select name="target" required style="flex:0 1 260px">
                <option value="">— {{ __('lead.assign_to') }} —</option>
                @if ($managers->isNotEmpty())
                    <optgroup label="👔 {{ __('lead.grp_managers') }}">
                        @foreach ($managers as $m)
                            <option value="m-{{ $m->id }}">{{ $m->displayName() }}</option>
                        @endforeach
                    </optgroup>
                @endif
                <optgroup label="🧢 {{ __('lead.grp_reps') }}">
                    @foreach ($reps as $r)
                        <option value="r-{{ $r->id }}">{{ $r->displayName() }} ({{ $r->code }})</option>
                    @endforeach
                </optgroup>
            </select>
            <button class="btn gold" type="submit" id="ldApplyBtn" disabled>
                ✅ {{ __('lead.apply_all') }}</button>
            {{-- لافتة «متحدد الكل عبر الصفحات» — بتظهر بس لما المستخدم
                 يختار كل نتايج الفلتر، و✕ بترجّعه للتحديد العادي --}}
            <span id="ldAllBanner" style="display:none;align-items:center;gap:6px;font-size:11.5px;
                   font-weight:800;background:#FFF4D6;border:1px solid #E3C56A;border-radius:999px;
                   padding:4px 12px">
                ⚡ <span id="ldAllBannerTxt"></span>
                <button type="button" class="btn sm" id="ldAllCancel" style="padding:1px 8px">✕</button>
            </span>
        </form>
    @endif
    <div class="tablewrap">
        <table>
            <tr>
                @if ($canConvert)
                    <th data-nosum style="width:32px">
                        <input type="checkbox" id="ldCkAll" title="{{ __('lead.select_all') }}">
                    </th>
                @endif
                <th>{{ __('common.code') }}</th>
                {{-- ⚠️ `data-nosum` — القوة ترتيب مالوش وحدة، ومجموعه في فوتر الجدول مالوش معنى --}}
                <th class="num" data-nosum title="{{ __('lead.score_hint') }}">{{ __('lead.score') }}</th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('common.phone') }}</th>
                <th>{{ __('client.zone') }}</th>
                <th>{{ __('lead.assigned_to') }}</th>
                <th class="num">{{ __('lead.expected_monthly') }}</th>
                <th>{{ __('lead.next_action') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>

            @forelse ($leads as $l)
                <tr>
                    @if ($canConvert)
                        <td>
                            @if (! $l->isConverted() && in_array($l->status, \App\Models\Lead::OPEN_STATUSES, true))
                                <input type="checkbox" class="ld-ck" form="ldBulkForm"
                                       name="ids[]" value="{{ $l->id }}">
                            @endif
                        </td>
                    @endif
                    <td class="num s">{{ $l->number }}</td>
                    <td class="num">
                        <span class="badge {{ $l->scoreClass() }}">{{ (int) $l->score }}</span>
                        @if ($l->reviews_count > 0)
                            <br><span style="font-size:10.5px;color:var(--muted)">
                                {{ $l->rating !== null ? number_format((float) $l->rating, 1).' ★ · ' : '' }}{{ $fmt($l->reviews_count) }}
                            </span>
                        @endif
                    </td>
                    <td>
                        <b>{{ $l->displayName() }}</b>
                        @if ($l->contact_name)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $l->contact_name }}</span>
                        @endif
                        @if ($l->category_raw || $l->sourceLabel())
                            <br><span style="font-size:10.5px;color:var(--muted)">
                                {{ collect([$l->category_raw, $l->sourceLabel()])->filter()->implode(' · ') }}
                            </span>
                        @endif

                        {{-- ═══ الشبيه (٢٦/٨): شبه مين وليه + قرار المالك ═══ --}}
                        @if ($l->dup_client_id !== null && ! $l->dup_dismissed && ! $l->isConverted())
                            <div class="ld-dup">
                                <div style="font-size:11px">
                                    ⚠️ {{ __('lead.dup_like') }}
                                    <a href="{{ route('erp.clients.show', $l->dup_client_id) }}" target="_blank"
                                       style="font-weight:900;color:var(--royal-blue)">
                                        {{ $l->dupClient?->fullName() ?? '—' }} ({{ $l->dupClient?->code }})</a>
                                    <span class="badge b-orange" style="font-size:9px">{{ __('lead.dup_'.$l->dup_reason) }}</span>
                                </div>
                                @if ($l->dup_reason === 'phone')
                                    <div style="font-size:10px;color:var(--muted)" dir="ltr">📞 {{ $l->dupClient?->phone }}</div>
                                @elseif ($l->dup_reason === 'address')
                                    <div style="font-size:10px;color:var(--muted)">📍 {{ $l->dupClient?->address }}</div>
                                @endif
                                @if ($canConvert)
                                    <div style="display:flex;gap:6px;margin-top:5px">
                                        <form method="POST" action="{{ route('erp.leads.dupdecide', $l) }}"
                                              onsubmit="return confirm({!! json_encode(__('lead.dup_same_confirm'), JSON_UNESCAPED_UNICODE) !!})">
                                            @csrf
                                            <input type="hidden" name="verdict" value="same">
                                            <button class="btn sm" type="submit">✅ {{ __('lead.dup_same') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('erp.leads.dupdecide', $l) }}">
                                            @csrf
                                            <input type="hidden" name="verdict" value="different">
                                            <button class="btn sm" type="submit">❌ {{ __('lead.dup_diff') }}</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </td>
                    <td class="num s">{{ $l->phone ?: '—' }}</td>
                    <td class="s">{{ $l->zone?->displayName() ?: '—' }}</td>
                    <td class="s">
                        {{ $l->assignee?->displayName() ?: '—' }}
                        {{-- في محفظة مدير ولسه ماتوزعش لمندوب (٦/٩) --}}
                        @if ($l->manager_id !== null && $l->assigned_to === null)
                            <br><span class="badge b-purple" style="font-size:9px">
                                👔 {{ __('lead.at_manager') }}: {{ $l->manager?->displayName() ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $money($l->expected_monthly) }}</td>
                    <td class="num s {{ $l->isOverdue() ? 'neg' : '' }}">
                        {{ $l->next_action_on?->format('Y-m-d') ?: '—' }}
                    </td>
                    <td>
                        <span class="badge {{ $l->statusClass() }}">{{ $l->statusLabel() }}</span>
                        {{-- تأكيد الميدان (فلو ٢٦/٨) — «تم تأكيد بيانات العميل» --}}
                        @if ($l->confirmed_at !== null)
                            <br><span class="badge b-green" style="font-size:9px"
                                title="{{ $l->confirmed_at->format('Y-m-d h:i A') }}">✓ {{ __('lead.confirmed_badge') }}</span>
                        @endif
                        @if ($l->isConverted())
                            <br><a href="{{ route('erp.clients.show', $l->client_id) }}"
                                   style="font-size:10.5px;color:var(--royal-blue)">{{ $l->client?->code }}</a>
                        @endif
                    </td>
                    <td class="num">
                        @if ($l->isConverted())
                            <span class="s" style="color:var(--muted)">—</span>
                        @else
                            @php
    // ⚠️ ممنوع @json بمصفوفة — بتكسّر بارسر البليد.
                                // فلاجز الـ HEX ضرورية لأن الـ JSON جوه onclick='...'
                                $lJson = json_encode([
                                    'id' => $l->id,
                                    'name' => $l->name,
                                    'name_en' => $l->name_en ?? '',
                                    'phone' => $l->phone ?? '',
                                    'contact_name' => $l->contact_name ?? '',
                                    'address' => $l->address ?? '',
                                    'zone_id' => $l->zone_id,
                                    'channel_id' => $l->channel_id,
                                    'assigned_to' => $l->assigned_to,
                                    'source' => $l->source ?? '',
                                    'status' => $l->status,
                                    'expected_monthly' => (float) $l->expected_monthly,
                                    'next_action_on' => $l->next_action_on?->format('Y-m-d') ?? '',
                                    'lost_reason' => $l->lost_reason ?? '',
                                    'notes' => $l->notes ?? '',
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                            @endphp
                            <button class="btn sm" onclick='editLead({!! $lJson !!})'>{{ __('common.edit') }}</button>

                            @if ($canConvert)
                                <form method="POST" action="{{ route('erp.leads.convert', $l) }}"
                                      style="display:inline" onsubmit="return confirm(CONVERT_CONFIRM)">
                                    @csrf
                                    <button class="btn sm green">{{ __('lead.convert') }}</button>
                                </form>
                                {{-- مسح ليد (٦/٩) — الحارس النهائي جوه الكنترولر --}}
                                <form method="POST" action="{{ route('erp.leads.delete', $l) }}"
                                      style="display:inline" onsubmit="return confirm(LEAD_DEL_CONFIRM)">
                                    @csrf
                                    <button class="btn sm red" title="{{ __('common.delete') }}">🗑</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                {{-- ⚠️ زوّدت عمود القوة ⇒ الـcolspan بقى 10 --}}
                <tr><td colspan="{{ $canConvert ? 11 : 10 }}" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('lead.none') }}
                </td></tr>
            @endforelse
        </table>
    </div>

    {{-- ═══ ترقيم مضبوط (١/٩): البارشال المرقّم بتاع السيستم بدل
         الليّاوت الافتراضي المكسور + اختيار حجم الصفحة ═══ --}}
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px">
        <span style="font-size:11.5px;color:var(--muted);font-weight:800">{{ __('lead.per_page') }}:</span>
        @foreach ([30, 60, 100, 'all'] as $opt)
            @php $lbl = $opt === 'all' ? __('lead.show_all') : $opt; @endphp
            @if ($per === $opt)
                <span class="btn sm gold" style="pointer-events:none">{{ $lbl }}</span>
            @else
                <a class="btn sm" href="{{ request()->fullUrlWithQuery(['per' => $opt, 'page' => null]) }}">{{ $lbl }}</a>
            @endif
        @endforeach
        @if ($per === 'all' && (int) ($dist->total ?? 0) > 2000)
            <span class="badge b-orange">{{ __('lead.all_capped', ['n' => 2000]) }}</span>
        @endif
    </div>

    @include('partials._pagination', ['p' => $leads])
</div>

{{-- ═══════════ إضافة ═══════════ --}}
<dialog id="dlgNewLead">
    <form class="dlg" method="POST" action="{{ route('erp.leads.store') }}" style="max-height:86vh;overflow-y:auto">
        @csrf
        <h4>{{ __('lead.new_lead') }}</h4>

        <div class="frow">
            <div><label class="f">{{ __('common.name') }}</label><input type="text" name="name" maxlength="190" required style="width:100%"></div>
            <div><label class="f">{{ __('client.name_en') }}</label><input type="text" name="name_en" maxlength="190" style="width:100%"></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('common.phone') }}</label><input type="text" name="phone" maxlength="30" style="width:100%"></div>
            <div><label class="f">{{ __('lead.contact_name') }}</label><input type="text" name="contact_name" maxlength="190" style="width:100%"></div>
        </div>
        <div><label class="f">{{ __('client.address') }}</label><input type="text" name="address" maxlength="190" style="width:100%"></div>

        <div class="frow" style="margin-top:12px">
            <div><label class="f">{{ __('client.zone') }}</label>
                <select name="zone_id" style="width:100%">{!! $zoneOptions !!}</select>
            </div>
            <div><label class="f">{{ __('client.channel') }}</label>
                <select name="channel_id" style="width:100%">{!! $channelOptions !!}</select>
            </div>
            <div><label class="f">{{ __('lead.assigned_to') }}</label>
                <select name="assigned_to" style="width:100%">{!! $repOptions !!}</select>
            </div>
        </div>

        <div class="frow">
            <div><label class="f">{{ __('lead.source') }}</label>
                <select name="source" style="width:100%">
                    <option value="">—</option>
                    @foreach ($sources as $s)
                        <option value="{{ $s }}">{{ __('lead.source_'.$s) }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="f">{{ __('lead.expected_monthly') }}</label>
                <input type="number" step="0.01" min="0" name="expected_monthly" value="0" style="width:100%">
            </div>
            <div><label class="f">{{ __('lead.next_action') }}</label>
                <input type="date" name="next_action_on" style="width:100%">
            </div>
        </div>

        <div><label class="f">{{ __('common.notes') }}</label><textarea name="notes" rows="2" style="width:100%"></textarea></div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewLead')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══════════ تعديل ═══════════ --}}
<dialog id="dlgEditLead">
    <form class="dlg" method="POST" id="formEditLead" style="max-height:86vh;overflow-y:auto">
        @csrf @method('PUT')
        <h4>{{ __('lead.edit_lead') }}</h4>

        <div class="frow">
            <div><label class="f">{{ __('common.name') }}</label><input type="text" name="name" id="edLName" maxlength="190" required style="width:100%"></div>
            <div><label class="f">{{ __('client.name_en') }}</label><input type="text" name="name_en" id="edLNameEn" maxlength="190" style="width:100%"></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('common.phone') }}</label><input type="text" name="phone" id="edLPhone" maxlength="30" style="width:100%"></div>
            <div><label class="f">{{ __('lead.contact_name') }}</label><input type="text" name="contact_name" id="edLContact" maxlength="190" style="width:100%"></div>
        </div>
        <div><label class="f">{{ __('client.address') }}</label><input type="text" name="address" id="edLAddr" maxlength="190" style="width:100%"></div>

        <div class="frow" style="margin-top:12px">
            <div><label class="f">{{ __('client.zone') }}</label>
                <select name="zone_id" id="edLZone" style="width:100%">{!! $zoneOptions !!}</select>
            </div>
            <div><label class="f">{{ __('client.channel') }}</label>
                <select name="channel_id" id="edLChannel" style="width:100%">{!! $channelOptions !!}</select>
            </div>
            <div><label class="f">{{ __('lead.assigned_to') }}</label>
                <select name="assigned_to" id="edLRep" style="width:100%">{!! $repOptions !!}</select>
            </div>
        </div>

        <div class="frow">
            <div><label class="f">{{ __('common.status') }}</label>
                <select name="status" id="edLStatus" style="width:100%" onchange="toggleLost()">
                    {{-- ⚠️ «بقى عميل» مش في القايمة — بتتحط من التحويل بس --}}
                    @foreach ($statuses as $s)
                        @if ($s !== 'won')
                            <option value="{{ $s }}">{{ __('lead.status_'.$s) }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div><label class="f">{{ __('lead.expected_monthly') }}</label>
                <input type="number" step="0.01" min="0" name="expected_monthly" id="edLExp" style="width:100%">
            </div>
            <div><label class="f">{{ __('lead.next_action') }}</label>
                <input type="date" name="next_action_on" id="edLNext" style="width:100%">
            </div>
        </div>

        <div id="lostBox" style="display:none">
            <label class="f">{{ __('lead.lost_reason') }}</label>
            <input type="text" name="lost_reason" id="edLLost" maxlength="190" style="width:100%">
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('common.notes') }}</label>
            <textarea name="notes" id="edLNotes" rows="2" style="width:100%"></textarea>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgEditLead')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══ ديالوج التوزيع الجماعي — «خد N من المنطقة دي» (٢٦/٨) ═══ --}}
@if ($canConvert)
<dialog id="dlgBulkAssign">
    <form method="POST" action="{{ route('erp.leads.bulk') }}">
        @csrf
        <h3>🎯 {{ __('lead.bulk_title') }}</h3>
        <div style="font-size:12px;color:var(--muted);margin-bottom:12px" id="blkZoneLine"></div>
        <input type="hidden" name="zone_id" id="blkZone" value="">

        <label class="f">{{ __('lead.assign_to') }}</label>
        <select name="target" required style="width:100%;margin-bottom:10px">
            <option value="">—</option>
            @if ($managers->isNotEmpty())
                <optgroup label="👔 {{ __('lead.grp_managers') }}">
                    @foreach ($managers as $m)
                        <option value="m-{{ $m->id }}">{{ $m->displayName() }}</option>
                    @endforeach
                </optgroup>
            @endif
            <optgroup label="🧢 {{ __('lead.grp_reps') }}">
                @foreach ($reps as $r)
                    <option value="r-{{ $r->id }}">{{ $r->displayName() }} ({{ $r->code }})</option>
                @endforeach
            </optgroup>
        </select>

        <label class="f">{{ __('lead.bulk_count') }}</label>
        <input type="number" name="count" id="blkCount" min="1" max="200" value="5"
               style="width:100%;margin-bottom:6px">
        <div class="dash-hint" style="margin-bottom:12px">{{ __('lead.bulk_hint') }}</div>

        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgBulkAssign')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">🎯 {{ __('lead.bulk_btn') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══ ديالوج نطاق «تحديد الكل» (١/٩): الصفحة دي ولا كل نتايج
     الفلتر؟ بيظهر بس لما فيه نتايج أكتر من المعروض ═══ --}}
<dialog id="dlgCkScope">
    <div class="dlg" style="max-width:420px">
        <h4>☑️ {{ __('lead.sel_scope_title') }}</h4>
        <div style="font-size:12px;color:var(--muted);margin-bottom:14px" id="ckScopeHint"></div>
        <div style="display:flex;flex-direction:column;gap:8px">
            <button class="btn" type="button" id="ckScopePage"></button>
            <button class="btn gold" type="button" id="ckScopeAll"></button>
            <button class="btn" type="button" onclick="closeDlg('dlgCkScope')" style="opacity:.7">
                {{ __('common.cancel') }}</button>
        </div>
    </div>
</dialog>
@endif

@endsection

@section('scripts')
<style>
/* بوكس الشبيه في الجدول (٢٦/٨) */
.ld-dup{margin-top:6px;background:#FFF8EC;border:1px solid #F3E4C2;border-radius:10px;padding:7px 10px}
/* شيبس مناطق الخريطة */
.ld-zchip{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;
    cursor:pointer;border:1.5px solid var(--royal-blue,#12399B);
    background:var(--blue-050,#E8F1FF);font:inherit;font-size:11.5px;font-weight:800}
.ld-zchip.off{opacity:.35;border-style:dashed;background:transparent}
</style>
<script>
    {{-- ⚠️ في ثابت مش جوه onsubmit — الأبوستروف بيكسّر الجافاسكريبت --}}
    const CONVERT_CONFIRM = @js(__('lead.convert_confirm'));
    const LEAD_DEL_CONFIRM = @js(__('lead.del_confirm'));

    function toggleLost() {
        const s = document.getElementById('edLStatus').value;
        document.getElementById('lostBox').style.display = s === 'lost' ? '' : 'none';
    }

    function editLead(l) {
        document.getElementById('formEditLead').action = '{{ url('erp/leads') }}/' + l.id;
        document.getElementById('edLName').value = l.name;
        document.getElementById('edLNameEn').value = l.name_en;
        document.getElementById('edLPhone').value = l.phone;
        document.getElementById('edLContact').value = l.contact_name;
        document.getElementById('edLAddr').value = l.address;
        document.getElementById('edLZone').value = l.zone_id || '';
        document.getElementById('edLChannel').value = l.channel_id || '';
        document.getElementById('edLRep').value = l.assigned_to || '';
        document.getElementById('edLStatus').value = l.status;
        document.getElementById('edLExp').value = l.expected_monthly;
        document.getElementById('edLNext').value = l.next_action_on;
        document.getElementById('edLLost').value = l.lost_reason;
        document.getElementById('edLNotes').value = l.notes;
        toggleLost();
        openDlg('dlgEditLead');
    }

    /* ═══ التحديد المتعدد + Apply (٢٦/٨ → نطاق التحديد ١/٩) ═══
       «تحديد الكل» بيسأل: الصفحة دي بس ولا كل نتايج الفلتر عبر
       الصفحات؟ وضع الكل بيبعت all_filtered=1 والسيرفر بيعيد بناء
       الكويري — أي فك لأي تشيك بوكس بيلغي وضع الكل فوراً. */
    (function () {
        'use strict';

        var all = document.getElementById('ldCkAll');
        var btn = document.getElementById('ldApplyBtn');
        var count = document.getElementById('ldCkCount');
        if (!all || !btn) return;

        var allInput = document.getElementById('ldAllFiltered');
        var banner = document.getElementById('ldAllBanner');
        var bannerTxt = document.getElementById('ldAllBannerTxt');
        var form = document.getElementById('ldBulkForm');

        /* المفتوحين المطابقين للفلتر كله (مش الصفحة) — من نفس عدّادات
           السيرفر؛ التسكين مابيلمسش المكسوب/المخسور/المتحوّل أصلاً */
        var OPEN_TOTAL = {{ (int) ($stats['open'] ?? 0) }};
        var T = {
            page: @js(__('lead.sel_page_only')),
            allF: @js(__('lead.sel_all_filtered')),
            hint: @js(__('lead.sel_scope_hint')),
            on: @js(__('lead.sel_all_on')),
            confirm: @js(__('lead.sel_all_confirm')),
        };

        function boxes() { return document.querySelectorAll('.ld-ck'); }

        function setAllMode(on) {
            allInput.value = on ? '1' : '';
            banner.style.display = on ? 'inline-flex' : 'none';
            if (on) bannerTxt.textContent = T.on.replace(':n', OPEN_TOTAL);
            refresh();
        }

        function refresh() {
            var n = document.querySelectorAll('.ld-ck:checked').length;
            var allMode = allInput.value === '1';
            count.textContent = allMode ? OPEN_TOTAL : n;
            btn.disabled = allMode ? false : n === 0;
        }

        function checkPage(v) {
            boxes().forEach(function (c) { c.checked = v; });
        }

        all.addEventListener('change', function () {
            if (!all.checked) { checkPage(false); setAllMode(false); return; }

            var pageN = boxes().length;

            /* لو الفلتر أوسع من المعروض — اسأل. لو كله قدامك خلاص */
            if (OPEN_TOTAL > pageN) {
                all.checked = false; /* لسه ما اختارش */
                document.getElementById('ckScopeHint').textContent =
                    T.hint.replace(':total', OPEN_TOTAL).replace(':page', pageN);
                document.getElementById('ckScopePage').textContent =
                    '☑️ ' + T.page.replace(':n', pageN);
                document.getElementById('ckScopeAll').textContent =
                    '⚡ ' + T.allF.replace(':n', OPEN_TOTAL);
                openDlg('dlgCkScope');
            } else {
                checkPage(true);
                setAllMode(false);
            }
        });

        document.getElementById('ckScopePage').addEventListener('click', function () {
            closeDlg('dlgCkScope');
            all.checked = true;
            checkPage(true);
            setAllMode(false);
        });
        document.getElementById('ckScopeAll').addEventListener('click', function () {
            closeDlg('dlgCkScope');
            all.checked = true;
            checkPage(true);
            setAllMode(true);
        });
        document.getElementById('ldAllCancel').addEventListener('click', function () {
            setAllMode(false);
        });

        boxes().forEach(function (c) {
            c.addEventListener('change', function () {
                /* فك واحد = مش «الكل» خلاص */
                if (!c.checked && allInput.value === '1') { setAllMode(false); all.checked = false; }
                refresh();
            });
        });

        /* تأكيد قبل تسكين الفلتر كله — دي ضغطة بتحرّك مئات */
        form.addEventListener('submit', function (e) {
            if (allInput.value === '1' && !confirm(T.confirm.replace(':n', OPEN_TOTAL))) {
                e.preventDefault();
            }
        });

        refresh();
    })();

    /* ═══ التوزيع الجماعي (٢٦/٨) ═══ */
    const BULK_MAX_WORD = @js(__('lead.bulk_available'));

    function ldOpenBulk(zoneId, zoneName, available) {
        document.getElementById('blkZone').value = zoneId;
        document.getElementById('blkZoneLine').textContent = zoneName + ' — ' + BULK_MAX_WORD.replace(':n', available);
        const c = document.getElementById('blkCount');
        c.max = Math.min(200, available);
        c.value = Math.min(5, available);
        openDlg('dlgBulkAssign');
    }

    /* ═══ خريطة المحفظة (تطوير ٢٦/٨) — بن لوكيشن SVG ملون بالحالة،
       القلب الأبيض = غير متوزع، وشيبس المناطق بتخفي/تظهر نقط كل
       منطقة بضغطة (كل النقط في طبقة لكل زون). ═══ */
    (function () {
        'use strict';

        const el = document.getElementById('ldMap');
        if (!el) return;

        const PTS = {!! json_encode($mapLeads, JSON_UNESCAPED_UNICODE) !!};
        const ZNAMES = @js($zones->mapWithKeys(fn ($z) => [$z->id => $z->displayName()]));
        const NO_ZONE = @js(__('lead.no_zone'));
        const ST_COLOR = { new: '#2563EB', contacted: '#6B7280', visited: '#7C3AED',
            negotiating: '#B45309', won: '#0F7A38', lost: '#DC2626' };
        const ST_LABEL = @js(collect(\App\Models\Lead::STATUSES)->mapWithKeys(fn ($s) => [$s => __('lead.status_'.$s)]));

        const map = L.map('ldMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap',
        }).addTo(map);

        /* بن لوكيشن — قطرة SVG بحدود بيضا وضل خفيف */
        function pinIcon(c, unassigned) {
            return L.divIcon({
                className: '',
                iconSize: [26, 34],
                iconAnchor: [13, 32],
                popupAnchor: [0, -28],
                html: '<svg width="26" height="34" viewBox="0 0 26 34" '
                    + 'style="filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))">'
                    + '<path d="M13 1C6.4 1 1 6.4 1 13c0 8.8 12 20 12 20s12-11.2 12-20C25 6.4 19.6 1 13 1z" '
                    + 'fill="' + c + '" stroke="#fff" stroke-width="1.6"/>'
                    + '<circle cx="13" cy="13" r="4.6" fill="' + (unassigned ? '#fff' : 'rgba(255,255,255,.4)') + '"/>'
                    + '</svg>',
            });
        }

        /* طبقة لكل زون — الشيبس بتشيل وتحط الطبقة كاملة */
        const layers = {};
        const counts = {};
        const bounds = [];

        PTS.forEach(function (p) {
            const zid = String(p.zone || 0);
            if (!layers[zid]) { layers[zid] = L.layerGroup().addTo(map); counts[zid] = 0; }
            counts[zid]++;

            const c = ST_COLOR[p.st] || '#2563EB';
            const m = L.marker([p.lat, p.lng], { icon: pinIcon(c, !p.rep) });
            m.bindPopup('<div style="font:700 12.5px Cairo,Inter,sans-serif;direction:rtl;text-align:start">'
                + p.name
                + '<div style="font-weight:400;font-size:11px;color:#6B6B66;margin-top:2px">'
                + (ST_LABEL[p.st] || p.st) + ' · ' + Math.round(p.score)
                + ' · ' + (ZNAMES[p.zone] || NO_ZONE) + '</div></div>');
            layers[zid].addLayer(m);
            bounds.push([p.lat, p.lng]);
        });

        if (bounds.length === 1) map.setView(bounds[0], 13);
        else if (bounds.length) map.fitBounds(L.latLngBounds(bounds).pad(0.1));

        map.on('click', function () { map.scrollWheelZoom.enable(); });
        map.on('mouseout', function () { map.scrollWheelZoom.disable(); });

        /* ═══ شيبس المناطق — بالعدد، مترتبة بالأكبر ═══ */
        const chipBox = document.getElementById('ldZoneChips');
        Object.keys(layers)
            .sort(function (a, b) { return counts[b] - counts[a]; })
            .forEach(function (zid) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'ld-zchip';
                b.innerHTML = '📍 ' + (ZNAMES[zid] || NO_ZONE)
                    + ' <span class="badge b-gray" style="font-size:9.5px">' + counts[zid] + '</span>';
                b.addEventListener('click', function () {
                    const off = b.classList.toggle('off');
                    if (off) map.removeLayer(layers[zid]);
                    else layers[zid].addTo(map);
                });
                chipBox.appendChild(b);
            });
    })();
</script>
@endsection
