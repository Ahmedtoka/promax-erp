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
        <select name="sort">
            <option value="score" @selected($sort === 'score')>{{ __('lead.sort_score') }}</option>
            <option value="recent" @selected($sort === 'recent')>{{ __('lead.sort_recent') }}</option>
        </select>
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
    <div class="mapbox" id="ldMap" style="height:420px"></div>
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
                <th></th>
            </tr>
            </thead>
            <tbody>
            @php $zoneById = $zones->keyBy('id'); @endphp
            @foreach ($zoneRows as $zr)
                @php $z = $zr->zone_id !== null ? $zoneById->get($zr->zone_id) : null; @endphp
                <tr>
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
    <div class="tablewrap">
        <table>
            <tr>
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
                    </td>
                    <td class="num s">{{ $l->phone ?: '—' }}</td>
                    <td class="s">{{ $l->zone?->displayName() ?: '—' }}</td>
                    <td class="s">{{ $l->assignee?->displayName() ?: '—' }}</td>
                    <td class="num">{{ $money($l->expected_monthly) }}</td>
                    <td class="num s {{ $l->isOverdue() ? 'neg' : '' }}">
                        {{ $l->next_action_on?->format('Y-m-d') ?: '—' }}
                    </td>
                    <td>
                        <span class="badge {{ $l->statusClass() }}">{{ $l->statusLabel() }}</span>
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
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                {{-- ⚠️ زوّدت عمود القوة ⇒ الـcolspan بقى 10 --}}
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('lead.none') }}
                </td></tr>
            @endforelse
        </table>
    </div>

    <div class="pag">{{ $leads->links() }}</div>
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

        <label class="f">{{ __('ops.rep') }}</label>
        <select name="rep_id" required style="width:100%;margin-bottom:10px">
            <option value="">—</option>
            @foreach ($reps as $r)
                <option value="{{ $r->id }}">{{ $r->displayName() }} ({{ $r->code }})</option>
            @endforeach
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
@endif

@endsection

@section('scripts')
<script>
    {{-- ⚠️ في ثابت مش جوه onsubmit — الأبوستروف بيكسّر الجافاسكريبت --}}
    const CONVERT_CONFIRM = @js(__('lead.convert_confirm'));

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

    /* ═══ خريطة المحفظة (٢٦/٨) — نقط ملونة بالحالة، والغير متوزع
       بحلقة بيضا جواه. البوب أب: الاسم + الحالة + السكور. ═══ */
    (function () {
        'use strict';

        const el = document.getElementById('ldMap');
        if (!el) return;

        const PTS = {!! json_encode($mapLeads, JSON_UNESCAPED_UNICODE) !!};
        const ST_COLOR = { new: '#2563EB', contacted: '#6B7280', visited: '#7C3AED',
            negotiating: '#B45309', won: '#0F7A38', lost: '#DC2626' };
        const ST_LABEL = @js(collect(\App\Models\Lead::STATUSES)->mapWithKeys(fn ($s) => [$s => __('lead.status_'.$s)]));

        const map = L.map('ldMap', { scrollWheelZoom: false, preferCanvas: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap',
        }).addTo(map);

        const bounds = [];

        PTS.forEach(function (p) {
            const c = ST_COLOR[p.st] || '#2563EB';
            const m = L.circleMarker([p.lat, p.lng], {
                radius: 6,
                color: c,
                weight: 2,
                // الغير متوزع: قلب أبيض — باين من فوق الخريطة على طول
                fillColor: p.rep ? c : '#FFFFFF',
                fillOpacity: p.rep ? 0.85 : 1,
            }).addTo(map);
            m.bindPopup('<div style="font:700 12.5px Cairo,Inter,sans-serif;direction:rtl;text-align:start">'
                + p.name
                + '<div style="font-weight:400;font-size:11px;color:#6B6B66;margin-top:2px">'
                + (ST_LABEL[p.st] || p.st) + ' · ' + Math.round(p.score) + '</div></div>');
            bounds.push([p.lat, p.lng]);
        });

        if (bounds.length === 1) map.setView(bounds[0], 13);
        else if (bounds.length) map.fitBounds(L.latLngBounds(bounds).pad(0.1));

        map.on('click', function () { map.scrollWheelZoom.enable(); });
        map.on('mouseout', function () { map.scrollWheelZoom.disable(); });
    })();
</script>
@endsection
