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
                    ['id' => $r->id, 'name' => $r->name, 'zone' => $r->zone_id],
                    JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP
                ); @endphp
                <tr>
                    <td class="num">{{ $r->number }}<br><span style="font-size:10.5px;color:var(--muted)">{{ $r->created_at->format('m-d H:i') }}</span></td>
                    <td><b>{{ $r->name }}</b>
                        @if ($r->client)<br><a style="font-size:11px;color:var(--blue)" href="{{ route('erp.clients.show', $r->client) }}">{{ __('client.client_card') }} ←</a>@endif
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
                                <span style="color:var(--muted);font-size:11px">{{ $r->decided_at?->format('m-d H:i') }}</span>
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
    <form class="dlg" method="POST" id="formDecide">
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
            <div id="wrapZone"><label class="f">{{ __('team.zone') }}</label>
                <select name="zone_id" id="dZone" style="width:100%">
                    <option value="">— {{ __('common.none') }} —</option>
                    @foreach ($zones as $z)<option value="{{ $z->id }}">{{ $z->displayName() }}</option>@endforeach
                </select>
            </div>
            <div id="wrapDisc"><label class="f">{{ __('client.discount_pct') }}</label><input type="number" step="0.5" name="discount" value="0" style="width:100%"></div>
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
<script>
function decide(r) {
    const tpl = {!! json_encode(__('ops.decide_on', ['name' => '#N#']), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
    document.getElementById('dTitle').textContent = tpl.replace('#N#', r.name);
    document.getElementById('formDecide').action = '{{ url('ops/requests') }}/' + r.id + '/decide';
    if (r.zone) document.getElementById('dZone').value = r.zone;
    toggleFields();
    openDlg('dlgDecide');
}
function toggleFields() {
    const approved = document.getElementById('dDecision').value === 'approved';
    document.getElementById('wrapZone').style.display = approved ? '' : 'none';
    document.getElementById('wrapDisc').style.display = approved ? '' : 'none';
}
</script>
@endsection
