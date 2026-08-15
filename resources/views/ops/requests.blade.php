@extends('layouts.system')

@section('title', __('ops.client_approvals'))

@php         // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`،
    // و`isManager()` بترجّع له true — فكان بيشوف الزرار ويترمي على
    // 403 بعد ما يملا الفورم.
    $manager = auth()->user()->canDecideOps(); @endphp

@section('content')

<div class="card" style="padding:10px 12px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn {{ ! ($filters['status'] ?? null) ? 'gold' : '' }}" href="{{ route('ops.requests') }}">{{ __('common.all') }}</a>
        @foreach (array_keys(\App\Models\ClientRequest::STATUSES) as $k)
            <a class="btn {{ ($filters['status'] ?? '') === $k ? 'gold' : '' }}" href="{{ route('ops.requests', ['status' => $k]) }}">{{ __('enums.request_status.'.$k) }}</a>
        @endforeach
    </div>
</div>

<div class="card">
    <h3>📝 {{ __('ops.requests') }} <span class="side">{{ __('ops.request_countable', ['count' => $requests->total()]) }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('ops.request') }}</th><th>{{ __('ops.place') }}</th><th>{{ __('ops.submitted_by') }}</th>
                <th>{{ __('team.zone') }}</th><th>{{ __('common.address') }}</th>
                <th>{{ __('common.phone') }}</th><th>{{ __('ops.photo') }}</th><th>{{ __('ops.documents') }}</th>
                <th>{{ __('common.status') }}</th>@if ($manager)<th>{{ __('ops.decision') }}</th>@endif
            </tr>
            @forelse ($requests as $r)
                {{-- ملحوظة: ممنوع دايركتيف json بمصفوفة جوه الـ Blade — بيكسّر الـ parser --}}
                @php $rJson = json_encode(
                    [
                        'id' => $r->id,
                        'name' => $r->name,
                        'zone' => $r->zone_id,
                        'address' => $r->address,
                        'address_ar' => $r->address_ar,
                        'lat' => $r->lat,
                        'lng' => $r->lng,
                        // القناة المبدئية من قناة المندوب صاحب الطلب
                        'channel' => $r->rep?->channel_id,
                        // التشابه بيتعرض جوه المودال كمان — المعتمِد
                        // بيقرر من هناك، ولازم التحذير يكون قدام عينه
                        // في نفس اللحظة مش في صف الجدول ورا المودال.
                        'dupes' => array_map(fn ($d) => [
                            'name' => $d['name'],
                            'code' => $d['code'],
                            'by' => $d['by_label'],
                            'conf' => $d['confidence_label'],
                            'sure' => $d['confidence'] === 'sure',
                        ], $dupes[$r->id] ?? []),
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
                ); @endphp
                <tr>
                    <td class="num">{{ $r->number }}<br><span style="font-size:10.5px;color:var(--muted)">{{ $r->created_at->format('m-d h:i A') }}</span></td>
                    <td style="white-space:normal;max-width:260px"><b>{{ $r->name }}</b>
                        @if ($r->client)<br><a style="font-size:11px;color:var(--blue)" href="{{ route('erp.clients.show', $r->client) }}">{{ __('client.client_card') }} ←</a>@endif
                        {{-- ⚠️ **التشابه بيتعرض هنا مش في عمود لوحده**
                             (١٥ أغسطس ٢٠٢٦): المعتمِد بيقرا الاسم وبيقرر،
                             فالتحذير لازم يكون **تحت الاسم** مش في آخر
                             الجدول اللي محتاج سكرول أفقي عشان يشوفه. --}}
                        @foreach ($dupes[$r->id] ?? [] as $d)
                            <br><span style="font-size:10.5px;color:{{ $d['confidence'] === 'sure' ? 'var(--red)' : 'var(--orange)' }}">
                                ⚠️ {{ __('ops.dup_similar_to') }}
                                @if (! empty($d['url']))
                                    <a href="{{ $d['url'] }}" target="_blank" rel="noopener" style="color:inherit;font-weight:800">{{ $d['name'] }}</a>
                                @else
                                    <b>{{ $d['name'] }}</b>
                                @endif
                                ({{ $d['code'] }}) · {{ $d['by_label'] }} · {{ $d['confidence_label'] }}
                            </span>
                        @endforeach
                    </td>
                    <td>{{ $r->rep->displayName() }}</td>
                    <td style="color:var(--muted)">{{ $r->zone?->displayName() ?? '—' }}</td>
                    <td style="white-space:normal;max-width:220px;color:var(--muted);font-size:11.5px">{{ $r->address }}</td>
                    <td class="num">{{ $r->phone }}</td>
                    <td>
                        @if ($r->hasPhoto())
                            <a class="btn sm" href="{{ $r->photoUrl() }}" target="_blank">🖼️ {{ __('common.view') }}</a>
                        @else ❌ @endif
                    </td>
                    <td>
                        @if ($r->hasDocsFile())
                            <a class="btn sm" href="{{ $r->docsUrl() }}" target="_blank">
                                {{ $r->docs_type === 'pdf' ? '📄 PDF' : '🖼️ '.__('ops.photo') }}
                            </a>
                        @elseif ($r->has_docs)
                            <span class="badge b-orange">{{ __('ops.claimed_yes') }}</span>
                        @else ❌ @endif
                    </td>
                    <td><span class="badge {{ $r->statusClass() }}">{{ $r->statusLabel() }}</span></td>
                    @if ($manager)
                        <td>
                            @if ($r->isOpen())
                                <button class="btn sm gold" onclick='decide({!! $rJson !!})'>{{ __('ops.decision') }}</button>
                            @else
                                <span style="color:var(--muted);font-size:11px">{{ $r->decided_at?->format('m-d h:i A') }}</span>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.no_requests') }}</td></tr>
            @endforelse
        </table>
    </div>
    <div class="pag">{{ $requests->links('pagination::simple-default') }}</div>
</div>

@if ($manager)
<dialog id="dlgDecide">
    {{-- ⚠️ فورم طويل — بيتعمله سكرول جوه المودال (max-height + overflow). --}}
    <form class="dlg" method="POST" id="formDecide" style="max-width:660px;max-height:86vh;overflow:auto">
        @csrf
        <h4 id="dTitle">{{ __('ops.decide_on', ['name' => __('ops.request')]) }}</h4>

        <div class="frow">
            <div><label class="f">{{ __('ops.decision') }}</label>
                <select name="decision" id="dDecision" style="width:100%" onchange="toggleFields()">
                    <option value="approved">{{ __('ops.approve_and_add') }}</option>
                    <option value="review">{{ __('ops.send_to_review') }}</option>
                    <option value="rejected">{{ __('ops.reject') }}</option>
                </select>
            </div>
        </div>

        {{-- ═══ حقول الاعتماد — بتبان لـ«اعتماد» بس ═══ --}}
        <div id="approveFields">
            {{-- النقطة اللي المندوب لقّطها + زرار كشف العنوان منها --}}
            <div style="border:1px solid var(--border);border-radius:10px;padding:10px 12px;margin:12px 0;background:var(--card2)">
                <div style="font-size:12px;font-weight:800;color:var(--royal-blue);margin-bottom:8px">{{ __('ops.captured_location') }}</div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <button type="button" class="btn sm" id="dGeoFill" onclick="detectFromLocation()">
                        🌐 {{ __('ops.detect_from_location') }}
                    </button>
                    <a id="dMapLink" href="#" target="_blank" rel="noopener" class="btn sm" style="display:none">📍 {{ __('ops.open_in_maps') }}</a>
                    <span id="dGeoMsg" style="font-size:11.5px;color:var(--muted)"></span>
                </div>
                <div id="dNoPoint" style="font-size:11.5px;color:var(--muted);margin-top:6px;display:none">{{ __('ops.no_location_captured') }}</div>
            </div>

            <div class="frow">
                <div>
                    <label class="f">{{ __('geo.address_en') }}</label>
                    <input type="text" name="address" id="dAddr" dir="ltr" maxlength="190" style="width:100%">
                </div>
                <div>
                    <label class="f">{{ __('geo.address_ar') }}</label>
                    <input type="text" name="address_ar" id="dAddrAr" maxlength="190" style="width:100%">
                </div>
            </div>

            <div class="frow">
                <div>
                    <label class="f">{{ __('geo.governorate') }}</label>
                    <select name="governorate" id="dGov" style="width:100%" onchange="filterZones()">
                        <option value="">{{ __('geo.pick_governorate') }}</option>
                        @foreach ($governorates as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="f">{{ __('geo.zone') }}</label>
                    {{-- ⚠️ سيلكت مسطّح بـ`data-gov` عشان الفلترة في المتصفح
                         (نفس نمط client_form) مش البارشال المجمّع. --}}
                    <select name="zone_id" id="dZone" style="width:100%">
                        <option value="">{{ __('geo.pick_zone') }}</option>
                        @foreach ($zones as $z)
                            <option value="{{ $z->id }}" data-gov="{{ $z->governorate }}">{{ $z->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="frow">
                <div>
                    <label class="f">{{ __('client.channel') }}</label>
                    <select name="channel_id" id="dChannel" style="width:100%" onchange="syncSubChannel()">
                        <option value="">— {{ __('client.pick_channel') }} —</option>
                        @foreach ($channels as $ch)
                            <option value="{{ $ch->id }}" data-code="{{ $ch->code }}">{{ $ch->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div id="dSubChannelBox" style="display:none">
                    <label class="f">{{ __('client.key_account_segment') }}</label>
                    <select name="sub_channel" style="width:100%">
                        <option value="">— {{ __('client.pick_segment') }} —</option>
                        @foreach (array_keys(\App\Models\Channel::SUB_CHANNELS) as $k)
                            <option value="{{ $k }}">{{ __('enums.sub_channel.'.$k) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="frow">
                <div>
                    <label class="f">{{ __('client.price_list') }}</label>
                    <select name="price_list_id" style="width:100%">
                        <option value="">— {{ __('client.pick_price_list') }} —</option>
                        @foreach ($priceLists as $pl)
                            <option value="{{ $pl->id }}">{{ $pl->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="f">{{ __('client.custom_discount') }} %</label>
                    <input type="number" step="0.5" min="0" max="100" name="discount" value="0" style="width:100%">
                </div>
            </div>

            <div class="frow">
                <div>
                    <label class="f">{{ __('client.chain') }}</label>
                    <select name="group_id" style="width:100%">
                        <option value="">— {{ __('client.independent') }} —</option>
                        @foreach ($groups as $grp)
                            <option value="{{ $grp->id }}">{{ $grp->displayName() }}</option>
                        @endforeach
                    </select>
                    <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('ops.chain_inherits_note') }}</div>
                </div>
                <div style="display:flex;align-items:flex-end;padding-bottom:6px">
                    {{-- المخفي يسبق: لو مش متعلّم بيوصل 0 بدل ما مايوصلش خالص --}}
                    <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:800;cursor:pointer">
                        <input type="hidden" name="has_contract" value="0">
                        <input type="checkbox" name="has_contract" value="1"> {{ __('client.has_contract') }}
                    </label>
                </div>
            </div>
            <div style="font-size:11px;color:var(--muted);margin:-4px 0 4px">{{ __('ops.contract_finish_hint') }}</div>

            {{-- ⚠️ **تجاوز واعٍ لحارس التكرار.** السيرفر بيرفض الاعتماد
                 لو فيه عميل شبيه إلا لو دي متعلّمة — الرفض القاطع كان
                 هيقفل فروع حقيقية بنفس الاسم ونفس رقم الإدارة. --}}
            <div id="dDupeBox" style="display:none;margin:8px 0 4px">
                <div class="alert warn" style="align-items:flex-start">
                    <span>⚠️</span>
                    <div style="flex:1">
                        <div id="dDupeList" style="font-size:11.5px"></div>
                        <label style="display:flex;gap:8px;align-items:center;margin-top:8px;font-size:12.5px;font-weight:800;cursor:pointer">
                            <input type="checkbox" name="confirm_duplicate" value="1" id="dDupeConfirm">
                            {{ __('client.dup_confirm_label') }}
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div><label class="f">{{ __('ops.note_to_rep') }}</label><input type="text" name="note" placeholder="{{ __('common.optional') }}" style="width:100%"></div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgDecide')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('ops.record_decision') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
@php
    // ⚠️ ممنوع دايركتيف @json بمصفوفة — بيكسّر بارسر بليد. json_encode في @php.
    $decideData = json_encode([
        'suggest' => route('erp.client_locations.suggest'),
        'decideBase' => url('ops/requests'),
        'titleTpl' => __('ops.decide_on', ['name' => '#N#']),
        'wait' => __('geo.fetching'),
        'fail' => __('geo.reverse_failed'),
        'fAddr' => __('geo.address'),
        'fGov' => __('geo.governorate'),
        'fZone' => __('geo.zone'),
        'dupSimilar' => __('ops.dup_similar_to'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
<script>
const DEC = {!! $decideData !!};
// النقطة الملتقطة للطلب المفتوح حالياً
let CUR = { lat: null, lng: null };

function decide(r) {
    document.getElementById('dTitle').textContent = DEC.titleTpl.replace('#N#', r.name);
    document.getElementById('formDecide').action = DEC.decideBase + '/' + r.id + '/decide';
    document.getElementById('dDecision').value = 'approved';

    document.getElementById('dAddr').value = r.address || '';
    document.getElementById('dAddrAr').value = r.address_ar || '';
    document.getElementById('dGov').value = '';
    document.getElementById('dZone').value = r.zone || '';
    document.getElementById('dChannel').value = r.channel || '';
    document.querySelector('#formDecide select[name="price_list_id"]').value = '';
    document.querySelector('#formDecide select[name="group_id"]').value = '';
    document.querySelector('#formDecide input[name="discount"]').value = '0';
    document.getElementById('dGeoMsg').textContent = '';

    renderRequestDupes(r.dupes || []);

    CUR = { lat: r.lat != null ? r.lat : null, lng: r.lng != null ? r.lng : null };
    updateCaptured();
    syncSubChannel();
    filterZones();
    toggleFields();
    openDlg('dlgDecide');
}

function toggleFields() {
    const approved = document.getElementById('dDecision').value === 'approved';
    document.getElementById('approveFields').style.display = approved ? '' : 'none';
}

/* ⚠️ **التشيك بوكس بيتفك مع كل فتحة للمودال.** المودال واحد لكل
   الطلبات — لو فضل متعلّم من طلب فات، الطلب اللي بعده كان بيعدّي
   حارس التكرار من غير ما حد يقرأه. */
function renderRequestDupes(list) {
    const box = document.getElementById('dDupeBox');
    const out = document.getElementById('dDupeList');
    const cb = document.getElementById('dDupeConfirm');
    if (!box || !out) return;

    if (cb) cb.checked = false;
    out.textContent = '';

    if (!list.length) { box.style.display = 'none'; return; }

    list.forEach(function (d) {
        const line = document.createElement('div');
        line.style.color = d.sure ? 'var(--red)' : 'var(--orange)';
        line.style.fontWeight = '700';
        line.textContent = '⚠️ ' + DEC.dupSimilar + ' ' + d.name + ' (' + d.code + ') · ' + d.by + ' · ' + d.conf;
        out.appendChild(line);
    });

    box.style.display = '';
}

// النقطة موجودة؟ نوري لينك الخريطة ونفعّل زرار الكشف — وإلا نعطّله
function updateCaptured() {
    const has = CUR.lat != null && CUR.lng != null;
    const link = document.getElementById('dMapLink');
    const btn = document.getElementById('dGeoFill');
    const noPt = document.getElementById('dNoPoint');
    if (has) {
        link.style.display = '';
        link.href = 'https://www.google.com/maps?q=' + CUR.lat + ',' + CUR.lng;
        btn.disabled = false;
        noPt.style.display = 'none';
    } else {
        link.style.display = 'none';
        btn.disabled = true;
        noPt.style.display = '';
    }
}

// فلترة المناطق بالمحافظة — نفس نمط client_form
function filterZones() {
    const gov = document.getElementById('dGov').value;
    const sel = document.getElementById('dZone');
    Array.from(sel.options).forEach(function (opt) {
        if (!opt.value) return;
        const ok = !gov || !opt.dataset.gov || opt.dataset.gov === gov || opt.selected;
        opt.hidden = !ok;
    });
    if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) sel.value = '';
}

// قسم الكي أكاونت بيبان للكي أكاونت بس — نفس نمط client_form
function syncSubChannel() {
    const sel = document.getElementById('dChannel');
    const box = document.getElementById('dSubChannelBox');
    const sub = box.querySelector('select');
    const code = sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.code : '';
    const allowed = (code === 'key_account');
    box.style.display = allowed ? '' : 'none';
    if (!allowed && sub) sub.value = '';
}

// كشف العنوان AR/EN + المحافظة + المنطقة من النقطة — نفس fillFromMap
async function detectFromLocation() {
    if (CUR.lat == null || CUR.lng == null) return;
    const msg = document.getElementById('dGeoMsg');
    const btn = document.getElementById('dGeoFill');
    btn.disabled = true;
    msg.textContent = DEC.wait;
    try {
        const r = await fetch(DEC.suggest, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ lat: CUR.lat, lng: CUR.lng }),
        });
        const j = await r.json();
        if (!r.ok) { msg.textContent = j.message || DEC.fail; return; }

        if (j.ar) document.getElementById('dAddrAr').value = j.ar;
        if (j.en) document.getElementById('dAddr').value = j.en;

        const gov = document.getElementById('dGov');
        if (j.governorate && !gov.value) gov.value = j.governorate;
        // نعيد الفلترة بعد المحافظة عشان منطقة الاقتراح تبقى ظاهرة
        filterZones();
        const zone = document.getElementById('dZone');
        if (j.zone_id && !zone.value) zone.value = j.zone_id;

        const filled = [];
        if (j.en || j.ar) filled.push(DEC.fAddr);
        if (j.governorate) filled.push(DEC.fGov);
        if (j.zone_id) filled.push(DEC.fZone);
        msg.textContent = filled.length ? '✔ ' + filled.join(' · ') : DEC.fail;
    } catch (e) {
        msg.textContent = DEC.fail;
    } finally {
        btn.disabled = false;
    }
}
</script>
@endsection
