@extends('layouts.system')

@section('title', __('ops.replenishments'))

@php
    use App\Models\ReplenishmentRequest;
    // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`،
    // و`isManager()` بترجّع له true — فكان بيشوف الزرار ويترمي على
    // 403 بعد ما يملا الفورم.
    $manager = auth()->user()->canDecideOps();
@endphp

@section('content')

<div class="card" style="padding:10px 12px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn {{ ! ($filters['status'] ?? null) ? 'gold' : '' }}" href="{{ route('ops.replenishments') }}">{{ __('common.all') }}</a>
        @foreach (ReplenishmentRequest::STATUSES as $k => [$lbl, $cls])
            <a class="btn {{ ($filters['status'] ?? '') === $k ? 'gold' : '' }}"
               href="{{ route('ops.replenishments', ['status' => $k]) }}">{{ __('enums.replenishment_status.'.$k) }}</a>
        @endforeach
    </div>
</div>

<div class="card">
    <h3>📦 {{ __('ops.replenishments_from_merch') }} <span class="side">{{ __('ops.request_countable', ['count' => $requests->total()]) }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('ops.request') }}</th><th>{{ __('client.branch') }}</th><th>{{ __('ops.requester') }}</th><th>{{ __('ops.items') }}</th>
                <th>{{ __('common.qty') }}</th><th>{{ __('common.status') }}</th><th>{{ __('ops.rep') }}</th><th>{{ __('common.notes') }}</th>
                @if ($manager)<th></th>@endif
            </tr>
            @forelse ($requests as $r)
                {{-- ملحوظة: ممنوع دايركتيف json بمصفوفة جوه الـ Blade — بيكسّر الـ parser --}}
                {{-- requested_by بيتبعت للمودال عشان «مين يوصّله؟» يفتح على الطالب نفسه --}}
                {{-- items بتتبعت لديالوج التعديل (١٢/٨) — الصفوف بتتبني منها --}}
                @php $rJson = json_encode([
                    'id' => $r->id,
                    'number' => $r->number,
                    'requested_by' => (int) $r->requested_by,
                    'items' => $r->items->map(fn ($i) => [
                        'product_id' => (int) $i->product_id,
                        'qty' => (int) $i->qty,
                        'name' => $i->product?->displayName() ?? '#'.$i->product_id,
                        'code' => $i->product?->code ?? '',
                    ])->values()->all(),
                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); @endphp
                <tr>
                    <td class="num"><b>{{ $r->number }}</b><br>
                        <span style="font-size:10.5px;color:var(--muted)">{{ $r->created_at->format('m-d h:i A') }}</span></td>
                    <td><b>{{ $r->client->displayName() }}</b></td>
                    <td>
                        {{ $r->promoter?->displayName() ?? '—' }}
                        {{-- مصدر الطلب (2026-08-09): مندوب واقف عند
                             العميل ولا بروموتر من زيارة رف --}}
                        <br><span class="badge {{ $r->origin() === 'rep' ? 'b-blue' : 'b-gray' }}"
                              style="font-size:9.5px">{{ $r->originLabel() }}</span>
                    </td>
                    <td style="white-space:normal;max-width:260px;font-size:11.5px">
                        @foreach ($r->items as $i)
                            <div>{{ $i->product->displayName() }} — <b>{{ $i->qty }}</b></div>
                        @endforeach
                    </td>
                    <td class="num"><b>{{ $r->qtyTotal() }}</b></td>
                    {{-- ⚠️ **عمود أمر التوريد اتشال** (قرار المالك ١٥/٨):
                         الريفيل مابقاش ياخد PO ولا يدخل الحسابات. مكانه
                         بقى **أمر التجهيز** — ده المسار الحقيقي دلوقتي:
                         موافقة ← تجهيز في المخزن ← عهدة المندوب.
                         الأوامر القديمة المتسلّمة بتفضل ظاهرة لأنها
                         حقيقة تاريخية (قرار المالك: سيبهم زي ما هم). --}}
                    <td><span class="badge {{ $r->statusClass() }}">{{ $r->statusLabel() }}</span>
                        @if ($r->pickOrder)
                            <br><a style="font-size:10.5px;font-weight:800" dir="ltr"
                                   href="{{ route('wh.picks.show', $r->pickOrder) }}"
                                   target="_blank" rel="noopener">{{ $r->pickOrder->number }}</a>
                        @elseif ($r->purchaseOrder)
                            <br><a style="font-size:10.5px;color:var(--muted)" dir="ltr"
                                   href="{{ route('ops.pos.show', $r->purchaseOrder) }}"
                                   target="_blank" rel="noopener"
                                   title="{{ __('ops.rpl_legacy_po') }}">{{ $r->purchaseOrder->number }}</a>
                        @endif
                    </td>
                    <td>{{ $r->assignee?->displayName() ?? '—' }}
                        {{-- مين وافق — كان مابيتسجّلش خالص قبل ١٥/٨ --}}
                        @if ($r->approver)
                            <div style="font-size:9.5px;color:var(--muted)">🔏 {{ $r->approver->displayName() }}</div>
                        @elseif ($r->status !== 'pending' && $r->status !== 'cancelled')
                            <div style="font-size:9.5px;color:var(--muted)">🔏 {{ __('ops.po_creator_unknown') }}</div>
                        @endif
                    </td>
                    <td style="white-space:normal;max-width:200px;color:var(--muted);font-size:11px">{{ $r->note }}</td>
                    @if ($manager)
                        <td>
                            @if ($r->status === 'pending')
                                <button class="btn sm gold" onclick='assignRpl({!! $rJson !!})'>{{ __('ops.assign') }}</button>
                                {{-- تعديل الطلب المستني (١٢/٨) — قبل التنزيل بس --}}
                                <button class="btn sm" onclick='editRpl({!! $rJson !!})'>✏️ {{ __('common.edit') }}</button>
                                <form method="POST" action="{{ route('ops.replenishments.cancel', $r) }}" style="display:inline"
                                      onsubmit="return confirm('{{ __('ops.confirm_cancel_request') }}')">
                                    @csrf<button class="btn sm red" type="submit">{{ __('common.cancel') }}</button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $manager ? 9 : 8 }}" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.no_replenishments') }}</td></tr>
            @endforelse
        </table>
    </div>
    <div class="pag">{{ $requests->links('pagination::simple-default') }}</div>
</div>

@if ($manager)
<dialog id="dlgRpl">
    <form class="dlg" method="POST" id="formRpl">
        @csrf
        <h4 id="rplTitle">{{ __('ops.assign_replenishment') }}</h4>
        <p style="font-size:12px;color:var(--muted);margin-bottom:12px">
            {{ __('ops.replenishment_note') }}
        </p>
        <div class="frow">
            <div><label class="f">{{ __('ops.who_delivers') }}</label>
                {{-- بحث + سيلكت: القايمة كل رولز الشغل الميداني وممكن تطول --}}
                <input type="text" id="rplWho" placeholder="{{ __('common.search') }}"
                       autocomplete="off" style="width:100%;margin-bottom:6px">
                <select name="assigned_to" id="rplAssignee" required size="5" style="width:100%">
                    @foreach ($drivers as $d)
                        <option value="{{ $d->id }}">{{ $d->displayName() }} ({{ $d->roleLabel() }})</option>
                    @endforeach
                </select>
            </div>
            <div><label class="f">{{ __('ops.pricing') }}</label>
                <select name="price_mode" style="width:100%">
                    <option value="client">{{ __('enums.price_mode.client') }}</option>
                    <option value="new">{{ __('enums.price_mode.new') }}</option>
                    <option value="old">{{ __('enums.price_mode.old') }}</option>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgRpl')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('ops.assign') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══ تعديل طلب مستني (١٢ أغسطس ٢٠٢٦) — كميات بالقطع + ضيف/شيل صنف ═══
     مفيش تسعير هنا — البنود كميات، والتسعير كله وقت التنزيل (assignTo). --}}
<dialog id="dlgRplEdit" class="wide">
    <form class="dlg" method="POST" id="formRplEdit" style="width:min(680px,96vw);max-height:88vh;overflow-y:auto">
        @csrf
        <h4 id="rplEditTitle">✏️ {{ __('ops.replenishment_edit') }}</h4>
        <div class="alert info" style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('ops.replenishment_edit_hint') }}</span>
        </div>

        {{-- منتقي الأصناف المشترك — نفس ليست تعديل إذن الصرف --}}
        @php
            $rplCatalog = $products->map(fn ($p) => [
                'id' => $p->id, 'code' => $p->code,
                'name' => $p->displayName(), 'name_ar' => $p->name,
                'name_en' => $p->name_en, 'image' => null,
            ])->values()->all();
        @endphp
        <label class="f">{{ __('stock.pick_add_item') }}</label>
        @include('partials._item_picker', [
            'id' => 'rpladd',
            'catalog' => $rplCatalog,
            'onPick' => 'rplEditAdd',
        ])

        <div class="tablewrap" style="margin-top:12px;max-height:44vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
            <table>
                <thead>
                    <tr>
                        <th style="text-align:start">{{ __('stock.item') }}</th>
                        <th>{{ __('common.qty') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="rplEditRows"></tbody>
            </table>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgRplEdit')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<script>
function assignRpl(r) {
    document.getElementById('rplTitle').textContent = {!! json_encode(__('ops.assign'), JSON_UNESCAPED_UNICODE) !!} + ' ' + r.number;
    document.getElementById('formRpl').action = '{{ url('ops/replenishments') }}/' + r.id + '/assign';

    // «مين يوصّله؟» بيفتح على الطالب نفسه — «يرجع تاني للمندوب» زي
    // ما المالك طلب. لو الطالب مش في القايمة (اتوقف/بره الفريق)
    // بيفضل أول واحد والمدير يختار بإيده.
    var sel = document.getElementById('rplAssignee');
    var box = document.getElementById('rplWho');
    box.value = '';
    filterAssignees('');
    sel.selectedIndex = -1;
    if (r.requested_by) {
        for (var i = 0; i < sel.options.length; i++) {
            if (parseInt(sel.options[i].value, 10) === r.requested_by) {
                sel.selectedIndex = i;
                break;
            }
        }
    }
    // الطالب مش في القايمة؟ أول واحد — عشان `required` مايعلّقش الفورم
    if (sel.selectedIndex < 0 && sel.options.length > 0) {
        sel.selectedIndex = 0;
    }
    openDlg('dlgRpl');
}

// فلترة قايمة المستلمين بالاسم — إخفاء بس، الاختيار المظلل بيفضل
function filterAssignees(q) {
    q = (q || '').trim().toLowerCase();
    var sel = document.getElementById('rplAssignee');
    for (var i = 0; i < sel.options.length; i++) {
        var opt = sel.options[i];
        opt.hidden = q !== '' && opt.textContent.toLowerCase().indexOf(q) === -1;
    }
}
document.getElementById('rplWho') &&
    document.getElementById('rplWho').addEventListener('input', function () {
        filterAssignees(this.value);
    });

// ═══ تعديل الطلب المستني (١٢/٨) — الصفوف بتتبني من داتا الصف ═══
var RPL_EDIT_TITLE = {!! json_encode(__('ops.replenishment_edit'), JSON_UNESCAPED_UNICODE) !!};

function rplEditRow(pid, qty, name, code) {
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    };
    var tr = document.createElement('tr');
    tr.setAttribute('data-pid', pid);
    tr.innerHTML =
        '<td style="text-align:start"><b>' + esc(name) + '</b>' +
        '<div style="font-size:10.5px;color:var(--muted)">' + esc(code) + '</div></td>' +
        '<td><input type="number" name="qty[' + pid + ']" min="0" max="9999" step="1" value="' + qty + '" style="width:96px"></td>' +
        '<td><button type="button" class="btn sm red" onclick="this.closest(\'tr\').remove()">✕</button></td>';
    document.getElementById('rplEditRows').appendChild(tr);
}

function editRpl(r) {
    document.getElementById('rplEditTitle').textContent = '✏️ ' + RPL_EDIT_TITLE + ' ' + r.number;
    document.getElementById('formRplEdit').action = '{{ url('ops/replenishments') }}/' + r.id + '/update';

    var body = document.getElementById('rplEditRows');
    body.innerHTML = '';
    (r.items || []).forEach(function (i) {
        rplEditRow(i.product_id, i.qty, i.name, i.code);
    });

    openDlg('dlgRplEdit');
}

// صنف جديد من الليست المشتركة — لو موجود بيركّز على خانته
window.rplEditAdd = function (id) {
    var prod = (window.PICKER_RPLADD || []).find(function (p) { return p.id === id; });
    if (!prod) return;

    var existing = document.querySelector('#rplEditRows tr[data-pid="' + id + '"]');
    if (existing) {
        var q = existing.querySelector('input[type=number]');
        if (q) { q.value = (parseInt(q.value || '0', 10) || 0) + 1; q.focus(); }
    } else {
        rplEditRow(id, 1, prod.name || '', prod.code || '');
    }
    window.rpladdPickerReset();
};
</script>
@endsection
