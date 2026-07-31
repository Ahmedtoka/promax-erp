@extends('layouts.system')

@section('title', __('branch.vehicles'))

@php
    // ⚠️ القوايم بتتبني في PHP — البليد مابيشتغلش جوه الجافاسكريبت
    $branchOptions = '<option value="">'.e(__('branch.central')).'</option>';
    foreach ($branches as $b) {
        $branchOptions .= '<option value="'.(int) $b->id.'">'.e($b->displayName()).'</option>';
    }

    // ⚠️ نفس القايمة للمندوب والسواق: في عربيات المندوب فيها بيسوق
    // بنفسه، وقصر القايمة على رول واحد بيمنع الحالة دي.
    $crewOptions = '<option value="">—</option>';
    foreach ($crew as $c) {
        $crewOptions .= '<option value="'.(int) $c->id.'">'
            .e($c->displayName().' — '.$c->roleLabel()).'</option>';
    }
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.branches') }}">🏢 {{ __('branch.branches') }}</a>
    @if ($canEdit)
        <button class="btn gold" onclick="openDlg('dlgNewVehicle')">➕ {{ __('branch.new_vehicle') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🚚 {{ __('branch.vehicles') }} <span class="side">{{ __('branch.vehicles_sub') }}</span></h3>
</div>

<div class="card">
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('branch.plate') }}</th>
                <th>{{ __('branch.kind') }}</th>
                <th>{{ __('branch.branch') }}</th>
                <th>{{ __('branch.rep') }}</th>
                <th>{{ __('branch.driver') }}</th>
                <th>{{ __('common.status') }}</th>
                @if ($canEdit)<th></th>@endif
            </tr>

            @forelse ($vehicles as $v)
                <tr>
                    <td class="num"><b>{{ $v->plate }}</b></td>
                    <td>
                        {{ $v->kindLabel() ?: '—' }}
                        <br><span class="badge {{ $v->is_fridge ? 'b-blue' : 'b-gray' }}">
                            {{ $v->is_fridge ? __('branch.fridge') : __('branch.dry') }}
                        </span>
                    </td>
                    <td class="s">{{ $v->branch?->displayName() ?: __('branch.central') }}</td>
                    <td class="s">{{ $v->rep?->displayName() ?: '—' }}</td>
                    <td class="s">
                        @if ($v->driver_id && $v->driver_id === $v->rep_id)
                            <span style="color:var(--muted)">{{ __('branch.same_person') }}</span>
                        @else
                            {{ $v->driver?->displayName() ?: '—' }}
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $v->active ? 'b-green' : 'b-gray' }}">
                            {{ $v->active ? __('common.active') : __('common.inactive') }}
                        </span>
                    </td>
                    @if ($canEdit)
                        <td class="num">
                            @php
                                $vJson = json_encode([
                                    'id' => $v->id,
                                    'plate' => $v->plate,
                                    'kind' => $v->kind ?? '',
                                    'kind_en' => $v->kind_en ?? '',
                                    'is_fridge' => (bool) $v->is_fridge,
                                    'branch_id' => $v->branch_id,
                                    'rep_id' => $v->rep_id,
                                    'driver_id' => $v->driver_id,
                                    'active' => (bool) $v->active,
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                            @endphp
                            <button class="btn sm" onclick='editVehicle({!! $vJson !!})'>{{ __('common.edit') }}</button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canEdit ? 7 : 6 }}" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('branch.no_vehicles') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($canEdit)
<dialog id="dlgNewVehicle">
    <form class="dlg" method="POST" action="{{ route('erp.vehicles.store') }}">
        @csrf
        <h4>{{ __('branch.new_vehicle') }}</h4>

        <div class="frow">
            <div><label class="f">{{ __('branch.plate') }}</label><input type="text" name="plate" maxlength="30" required style="width:100%"></div>
            <div><label class="f">{{ __('branch.kind') }}</label><input type="text" name="kind" maxlength="190" style="width:100%"></div>
            <div><label class="f">{{ __('branch.kind_en') }}</label><input type="text" name="kind_en" maxlength="190" style="width:100%"></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('branch.branch') }}</label>
                <select name="branch_id" style="width:100%">{!! $branchOptions !!}</select>
            </div>
            <div><label class="f">{{ __('branch.rep') }}</label>
                <select name="rep_id" style="width:100%">{!! $crewOptions !!}</select>
            </div>
            <div><label class="f">{{ __('branch.driver') }}</label>
                <select name="driver_id" style="width:100%">{!! $crewOptions !!}</select>
            </div>
        </div>

        <label style="display:flex;align-items:center;gap:7px;margin-top:8px;font-size:12.5px">
            <input type="hidden" name="is_fridge" value="0">
            <input type="checkbox" name="is_fridge" value="1"> {{ __('branch.is_fridge') }}
        </label>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewVehicle')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

<dialog id="dlgEditVehicle">
    <form class="dlg" method="POST" id="formEditVehicle">
        @csrf @method('PUT')
        <h4>{{ __('branch.edit_vehicle') }}</h4>

        <div class="frow">
            <div><label class="f">{{ __('branch.plate') }}</label><input type="text" name="plate" id="edVPlate" maxlength="30" required style="width:100%"></div>
            <div><label class="f">{{ __('branch.kind') }}</label><input type="text" name="kind" id="edVKind" maxlength="190" style="width:100%"></div>
            <div><label class="f">{{ __('branch.kind_en') }}</label><input type="text" name="kind_en" id="edVKindEn" maxlength="190" style="width:100%"></div>
        </div>
        <div class="frow">
            <div><label class="f">{{ __('branch.branch') }}</label>
                <select name="branch_id" id="edVBranch" style="width:100%">{!! $branchOptions !!}</select>
            </div>
            <div><label class="f">{{ __('branch.rep') }}</label>
                <select name="rep_id" id="edVRep" style="width:100%">{!! $crewOptions !!}</select>
            </div>
            <div><label class="f">{{ __('branch.driver') }}</label>
                <select name="driver_id" id="edVDriver" style="width:100%">{!! $crewOptions !!}</select>
            </div>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('branch.is_fridge') }}</label>
                <label style="display:flex;align-items:center;gap:7px;padding-top:8px;font-size:12.5px">
                    <input type="hidden" name="is_fridge" value="0">
                    <input type="checkbox" name="is_fridge" id="edVFridge" value="1"> {{ __('branch.fridge') }}
                </label>
            </div>
            <div>
                <label class="f">{{ __('common.status') }}</label>
                <label style="display:flex;align-items:center;gap:7px;padding-top:8px;font-size:12.5px">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" name="active" id="edVActive" value="1"> {{ __('common.active') }}
                </label>
            </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgEditVehicle')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<script>
    function editVehicle(v) {
        document.getElementById('formEditVehicle').action = '{{ url('erp/vehicles') }}/' + v.id;
        document.getElementById('edVPlate').value = v.plate;
        document.getElementById('edVKind').value = v.kind;
        document.getElementById('edVKindEn').value = v.kind_en;
        document.getElementById('edVBranch').value = v.branch_id || '';
        document.getElementById('edVRep').value = v.rep_id || '';
        document.getElementById('edVDriver').value = v.driver_id || '';
        document.getElementById('edVFridge').checked = v.is_fridge;
        document.getElementById('edVActive').checked = v.active;
        openDlg('dlgEditVehicle');
    }
</script>
@endsection
