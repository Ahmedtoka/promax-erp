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
                @php $rJson = json_encode(['id' => $r->id, 'number' => $r->number, 'requested_by' => (int) $r->requested_by], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); @endphp
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
                    <td><span class="badge {{ $r->statusClass() }}">{{ $r->statusLabel() }}</span>
                        @if ($r->purchaseOrder)
                            <br><a style="font-size:10.5px;color:var(--blue)" href="{{ route('ops.pos') }}">{{ $r->purchaseOrder->number }}</a>
                        @endif
                    </td>
                    <td>{{ $r->assignee?->displayName() ?? '—' }}</td>
                    <td style="white-space:normal;max-width:200px;color:var(--muted);font-size:11px">{{ $r->note }}</td>
                    @if ($manager)
                        <td>
                            @if ($r->status === 'pending')
                                <button class="btn sm gold" onclick='assignRpl({!! $rJson !!})'>{{ __('ops.assign') }}</button>
                                <form method="POST" action="{{ route('ops.replenishments.cancel', $r) }}" style="display:inline"
                                      onsubmit="return confirm('{{ __('ops.confirm_cancel_request') }}')">
                                    @csrf<button class="btn sm red" type="submit">{{ __('common.cancel') }}</button>
                                </form>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.no_replenishments') }}</td></tr>
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
</script>
@endsection
