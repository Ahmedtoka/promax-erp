@extends('layouts.system')

@section('title', __('branch.page'))

@php
    $fmt = fn ($n) => number_format((float) $n);

    // ⚠️ قايمة المديرين بتتبني هنا — البليد مابيشتغلش جوه الجافاسكريبت
    $managerOptions = '<option value="">—</option>';
    foreach ($managers as $m) {
        $managerOptions .= '<option value="'.(int) $m->id.'">'.e($m->displayName()).'</option>';
    }
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.vehicles') }}">🚚 {{ __('branch.vehicles') }}</a>
    @if ($canEdit)
        <button class="btn gold" onclick="openDlg('dlgNewBranch')">➕ {{ __('branch.new_branch') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🏢 {{ __('branch.page') }} <span class="side">{{ __('branch.page_sub') }}</span></h3>

    @unless ($canEdit)
        <div class="alert info">{{ __('branch.readonly_note') }}</div>
    @endunless

    {{-- ⚠️ الداتا المركزية لازم تبان. اليوزر اللي بيشوف «5 فروع» وبيلاقي
         عملاء مش تابعين لأي فرع بيفتكر إن فيه بيانات ضايعة. --}}
    <div class="alert info">
        <span>ℹ️</span>
        <span>
            {{ __('branch.central_note') }} —
            {{ __('branch.central_clients', ['count' => $fmt($unassigned['clients'])]) }} ·
            {{ __('branch.central_users', ['count' => $fmt($unassigned['users'])]) }} ·
            {{ __('branch.central_zones', ['count' => $fmt($unassigned['zones'])]) }} ·
            {{ __('branch.central_warehouses', ['count' => $fmt($unassigned['warehouses'])]) }}
        </span>
    </div>
</div>

<div class="card">
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.code') }}</th>
                <th>{{ __('branch.branch') }}</th>
                <th>{{ __('branch.manager') }}</th>
                <th class="num">{{ __('branch.team') }}</th>
                <th class="num">{{ __('branch.clients') }}</th>
                <th class="num">{{ __('branch.zones') }}</th>
                <th class="num">{{ __('branch.warehouses') }}</th>
                <th class="num">{{ __('branch.vehicles_count') }}</th>
                <th>{{ __('common.status') }}</th>
                @if ($canEdit)<th></th>@endif
            </tr>

            @forelse ($branches as $b)
                <tr>
                    <td class="num"><b>{{ $b->code }}</b></td>
                    <td>
                        {{ $b->displayName() }}
                        @if ($b->address)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $b->address }}</span>
                        @endif
                    </td>
                    <td class="s">{{ $b->manager?->displayName() ?: '—' }}</td>
                    <td class="num">{{ $fmt($b->users_count) }}</td>
                    <td class="num">{{ $fmt($b->clients_count) }}</td>
                    <td class="num">{{ $fmt($b->zones_count) }}</td>
                    <td class="num">{{ $fmt($b->warehouses_count) }}</td>
                    <td class="num">{{ $fmt($b->vehicles_count) }}</td>
                    <td>
                        <span class="badge {{ $b->active ? 'b-green' : 'b-gray' }}">
                            {{ $b->active ? __('common.active') : __('common.inactive') }}
                        </span>
                    </td>
                    @if ($canEdit)
                        <td class="num">
                            @php
    // ⚠️ ممنوع @json بمصفوفة — بتكسّر بارسر البليد.
                                // فلاجز الـ HEX ضرورية لأن الـ JSON جوه onclick='...'
                                $bJson = json_encode([
                                    'id' => $b->id,
                                    'code' => $b->code,
                                    'name' => $b->name,
                                    'name_en' => $b->name_en ?? '',
                                    'address' => $b->address ?? '',
                                    'phone' => $b->phone ?? '',
                                    'manager_id' => $b->manager_id,
                                    'lat' => $b->lat,
                                    'lng' => $b->lng,
                                    'active' => (bool) $b->active,
                                    'notes' => $b->notes ?? '',
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                            @endphp
                            <button class="btn sm" onclick='editBranch({!! $bJson !!})'>{{ __('common.edit') }}</button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canEdit ? 10 : 9 }}" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('branch.no_branches') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($canEdit)
{{-- ═══════════ إضافة ═══════════ --}}
<dialog id="dlgNewBranch">
    <form class="dlg" method="POST" action="{{ route('erp.branches.store') }}">
        @csrf
        <h4>{{ __('branch.new_branch') }}</h4>

        <div class="frow">
            <div><label class="f">{{ __('common.code') }}</label><input type="text" name="code" maxlength="20" placeholder="MAADI" style="width:100%"></div>
            <div><label class="f">{{ __('common.name') }}</label><input type="text" name="name" maxlength="190" required style="width:100%"></div>
            <div><label class="f">{{ __('client.name_en') }}</label><input type="text" name="name_en" maxlength="190" style="width:100%"></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('client.address') }}</label><input type="text" name="address" maxlength="190" style="width:100%"></div>
            <div><label class="f">{{ __('common.phone') }}</label><input type="text" name="phone" maxlength="30" style="width:100%"></div>
            <div><label class="f">{{ __('branch.manager') }}</label>
                <select name="manager_id" style="width:100%">{!! $managerOptions !!}</select>
            </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewBranch')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══════════ تعديل ═══════════ --}}
<dialog id="dlgEditBranch">
    <form class="dlg" method="POST" id="formEditBranch">
        @csrf @method('PUT')
        <h4>{{ __('branch.edit_branch') }}</h4>

        <div class="frow">
            <div><label class="f">{{ __('common.code') }}</label><input type="text" name="code" id="edBCode" maxlength="20" style="width:100%"></div>
            <div><label class="f">{{ __('common.name') }}</label><input type="text" name="name" id="edBName" maxlength="190" required style="width:100%"></div>
            <div><label class="f">{{ __('client.name_en') }}</label><input type="text" name="name_en" id="edBNameEn" maxlength="190" style="width:100%"></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('client.address') }}</label><input type="text" name="address" id="edBAddr" maxlength="190" style="width:100%"></div>
            <div><label class="f">{{ __('common.phone') }}</label><input type="text" name="phone" id="edBPhone" maxlength="30" style="width:100%"></div>
            <div><label class="f">{{ __('branch.manager') }}</label>
                <select name="manager_id" id="edBManager" style="width:100%">{!! $managerOptions !!}</select>
            </div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('client.lat') }}</label><input type="number" step="0.0000001" name="lat" id="edBLat" style="width:100%"></div>
            <div><label class="f">{{ __('client.lng') }}</label><input type="number" step="0.0000001" name="lng" id="edBLng" style="width:100%"></div>
            <div>
                <label class="f">{{ __('common.status') }}</label>
                <label style="display:flex;align-items:center;gap:7px;padding-top:8px;font-size:12.5px">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" name="active" id="edBActive" value="1"> {{ __('common.active') }}
                </label>
            </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgEditBranch')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<script>
    function editBranch(b) {
        document.getElementById('formEditBranch').action = '{{ url('erp/branches') }}/' + b.id;
        document.getElementById('edBCode').value = b.code;
        document.getElementById('edBName').value = b.name;
        document.getElementById('edBNameEn').value = b.name_en;
        document.getElementById('edBAddr').value = b.address;
        document.getElementById('edBPhone').value = b.phone;
        document.getElementById('edBManager').value = b.manager_id || '';
        document.getElementById('edBLat').value = b.lat || '';
        document.getElementById('edBLng').value = b.lng || '';
        document.getElementById('edBActive').checked = b.active;
        openDlg('dlgEditBranch');
    }
</script>
@endsection
