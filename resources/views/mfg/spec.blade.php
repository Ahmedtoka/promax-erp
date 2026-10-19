@extends('layouts.system')

@section('title', __('mfg.spec_heading', ['product' => $spec->product?->displayName() ?? '—', 'version' => $spec->version]))

@section('content')
@php
    $badge = ['draft' => 'b-gray', 'under_review' => 'b-orange', 'approved' => 'b-green', 'archived' => 'b-purple'];
    $user = auth()->user();
    $canEdit = \App\Support\Access::action($user, 'act.mfg.specs_edit');
    $canApprove = \App\Support\Access::action($user, 'act.mfg.specs_approve');
    $editable = $canEdit && $spec->isDraft();
    // ⚠️ إصدار مفتوح واحد لكل منتج — زرار «إصدار جديد» بيختفي لو فيه مسودة أو مراجعة
    $hasOpen = $versions->contains(fn ($v) => in_array($v->status, ['draft', 'under_review'], true));
    $num = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $nutrition = $spec->nutrition ?? [];
    $limits = old('qc_limits', $spec->qc_limits ?? []);
@endphp

<div class="card">
    <h3>📄 {{ __('mfg.spec_heading', ['product' => $spec->product?->displayName() ?? '—', 'version' => $spec->version]) }}
        <span class="side"><span class="badge {{ $badge[$spec->status] ?? 'b-gray' }}">{{ $spec->statusLabel() }}</span></span>
    </h3>

    <div class="frow" style="margin-bottom:0">
        <div><label class="f">{{ __('mfg.col_created_by') }}</label><div>{{ $spec->creator?->displayName() ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_submitted_at') }}</label><div class="num">{{ $spec->submitted_at?->format('Y-m-d H:i') ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_approved_by') }}</label><div>{{ $spec->approver?->displayName() ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_approved_at') }}</label><div class="num">{{ $spec->approved_at?->format('Y-m-d H:i') ?? '—' }}</div></div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px" data-noprint>
        <a class="btn" href="{{ route('mfg.specs') }}">← {{ __('mfg.back_to_specs') }}</a>

        @if ($canEdit && $spec->isDraft())
            <form method="POST" action="{{ route('mfg.specs.submit', $spec) }}"
                  onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('mfg.confirm_submit')) }})">
                @csrf
                <button class="btn" type="submit">📤 {{ __('mfg.btn_submit_review') }}</button>
            </form>
        @endif

        @if ($canApprove && $spec->status === 'under_review')
            <form method="POST" action="{{ route('mfg.specs.approve', $spec) }}"
                  onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('mfg.confirm_approve')) }})">
                @csrf
                <button class="btn green" type="submit">✔ {{ __('mfg.btn_approve') }}</button>
            </form>
            <button class="btn red" type="button" onclick="openDlg('dlgSendBack')">↩ {{ __('mfg.btn_send_back') }}</button>
        @endif

        @if ($canEdit && in_array($spec->status, ['approved', 'archived'], true) && ! $hasOpen)
            <form method="POST" action="{{ route('mfg.specs.store') }}" title="{{ __('mfg.new_version_hint') }}">
                @csrf
                <input type="hidden" name="product_id" value="{{ $spec->product_id }}">
                <input type="hidden" name="copy_from" value="{{ $spec->id }}">
                <button class="btn" type="submit">➕ {{ __('mfg.new_version') }}</button>
            </form>
        @endif
    </div>

    @if ($spec->status === 'under_review')
        <div class="alert" style="margin-top:12px">{{ __('mfg.locked_review') }}</div>
    @elseif (! $spec->isDraft())
        <div class="alert" style="margin-top:12px">{{ __('mfg.locked_final') }}</div>
    @endif
</div>

{{-- ⚠️ المسودة بس بتتعدل — غير كده الحقول للقراية (`fieldset disabled`) والسيرفر بيرفض برضه --}}
<form method="POST" action="{{ route('mfg.specs.update', $spec) }}" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="qc_present" value="1">
    <fieldset @disabled(! $editable) style="border:0;padding:0;margin:0;min-width:0">

    <div class="card">
        <h3>{{ __('mfg.spec_details') }}
            @if ($editable)<span class="side">{{ __('mfg.submit_needs_hint') }}</span>@endif
        </h3>
        <div class="frow">
            <div>
                <label class="f">{{ __('mfg.f_net_weight') }}</label>
                <input type="number" name="net_weight_g" step="0.001" min="0" value="{{ old('net_weight_g', $num($spec->net_weight_g)) }}">
            </div>
            <div>
                <label class="f">{{ __('mfg.f_shelf_life') }}</label>
                <input type="number" name="shelf_life_days" step="1" min="1" value="{{ old('shelf_life_days', $spec->shelf_life_days) }}">
            </div>
            <div>
                <label class="f">{{ __('mfg.f_attachment') }}</label>
                @if ($editable)
                    <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.docx">
                    <div class="s" style="color:var(--muted)">{{ __('mfg.attachment_hint') }}</div>
                @endif
                <div>
                    @if ($spec->attachment_path)
                        <a href="{{ route('mfg.specs.file', $spec) }}">⬇ {{ $spec->attachment_name ?: __('mfg.attachment_download') }}</a>
                    @else
                        <span style="color:var(--muted)">{{ __('mfg.attachment_none') }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="frow" style="margin-bottom:0">
            <div>
                <label class="f">{{ __('mfg.f_notes') }}</label>
                <textarea name="notes" rows="3">{{ old('notes', $spec->notes) }}</textarea>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>🥣 {{ __('mfg.nutrition_title') }}</h3>
        <div class="frow keep" style="margin-bottom:0">
            @foreach (\App\Models\ProductSpec::NUTRITION_KEYS as $k)
                <div>
                    <label class="f">{{ __('mfg.nut_'.$k) }}</label>
                    <input type="number" name="nutrition[{{ $k }}]" step="0.01" min="0" value="{{ old('nutrition.'.$k, $num($nutrition[$k] ?? null)) }}">
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <h3>🔬 {{ __('mfg.qc_limits_title') }}
            @if ($editable)
                <span class="side"><button class="btn sm" type="button" onclick="specAddLimit()">➕ {{ __('mfg.qc_add') }}</button></span>
            @endif
        </h3>
        <div class="tablewrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('mfg.qc_param') }}</th>
                        <th class="num" data-nosum>{{ __('mfg.qc_min') }}</th>
                        <th class="num" data-nosum>{{ __('mfg.qc_max') }}</th>
                        <th>{{ __('mfg.qc_unit') }}</th>
                        <th data-noexport></th>
                    </tr>
                </thead>
                <tbody id="specLimits">
                    @foreach ($limits as $i => $l)
                        <tr>
                            <td><input type="text" name="qc_limits[{{ $i }}][param]" maxlength="100" value="{{ $l['param'] ?? '' }}"></td>
                            <td class="num"><input type="number" step="any" name="qc_limits[{{ $i }}][min]" value="{{ $num($l['min'] ?? null) }}"></td>
                            <td class="num"><input type="number" step="any" name="qc_limits[{{ $i }}][max]" value="{{ $num($l['max'] ?? null) }}"></td>
                            <td><input type="text" name="qc_limits[{{ $i }}][unit]" maxlength="20" value="{{ $l['unit'] ?? '' }}"></td>
                            <td data-noexport>@if ($editable)<button class="btn sm red" type="button" aria-label="{{ __('mfg.qc_remove') }}" onclick="this.closest('tr').remove()">✕</button>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
                <tbody>
                    <tr id="specLimitsEmpty" @if (count($limits) > 0) hidden @endif>
                        <td colspan="5" style="text-align:center;color:var(--muted);padding:20px">{{ __('mfg.no_qc_limits') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    </fieldset>

    @if ($editable)
        <div style="display:flex;justify-content:flex-end;margin-bottom:16px" data-noprint>
            <button class="btn" type="submit">💾 {{ __('mfg.btn_save_draft') }}</button>
        </div>
    @endif
</form>

{{-- صف حد جودة جديد — `__I__` بيتبدل برقم جديد وقت الإضافة --}}
<template id="specLimitRow">
    <tr>
        <td><input type="text" name="qc_limits[__I__][param]" maxlength="100"></td>
        <td class="num"><input type="number" step="any" name="qc_limits[__I__][min]"></td>
        <td class="num"><input type="number" step="any" name="qc_limits[__I__][max]"></td>
        <td><input type="text" name="qc_limits[__I__][unit]" maxlength="20"></td>
        <td data-noexport><button class="btn sm red" type="button" aria-label="{{ __('mfg.qc_remove') }}" onclick="this.closest('tr').remove()">✕</button></td>
    </tr>
</template>

<div class="card">
    <h3>🗂 {{ __('mfg.spec_versions') }} <span class="side">{{ $spec->product?->displayName() }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th class="num" data-nosum>{{ __('mfg.col_version') }}</th>
                <th>{{ __('mfg.col_status') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_shelf_life') }}</th>
                <th>{{ __('mfg.col_created_by') }}</th>
                <th>{{ __('mfg.col_approved_by') }}</th>
                <th data-nosum>{{ __('mfg.col_updated_at') }}</th>
            </tr>
            @forelse ($versions as $v)
                <tr class="clickable" onclick="location.href='{{ route('mfg.specs.show', $v) }}'" @if ($v->id === $spec->id) style="font-weight:700" @endif>
                    <td class="num">{{ $v->version }}</td>
                    <td>
                        <span class="badge {{ $badge[$v->status] ?? 'b-gray' }}">{{ $v->statusLabel() }}</span>
                        @if ($v->status === 'approved')<span class="badge b-blue">{{ __('mfg.spec_current') }}</span>@endif
                    </td>
                    <td class="num">{{ $v->shelf_life_days ?? '—' }}</td>
                    <td>{{ $v->creator?->displayName() ?? '—' }}</td>
                    <td>{{ $v->approver?->displayName() ?? '—' }}</td>
                    <td class="num s">{{ $v->updated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">{{ __('mfg.no_specs') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($canApprove && $spec->status === 'under_review')
<dialog id="dlgSendBack">
    <form class="dlg" method="POST" action="{{ route('mfg.specs.send_back', $spec) }}">
        @csrf
        <h4>{{ __('mfg.send_back_title') }}</h4>
        <div class="frow"><div>
            <label class="f">{{ __('mfg.send_back_note') }}</label>
            <textarea name="note" rows="3" maxlength="500"></textarea>
        </div></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgSendBack')">{{ __('common.cancel') }}</button>
            <button class="btn red" type="submit">{{ __('mfg.btn_send_back') }}</button>
        </div>
    </form>
</dialog>
@endif

<script>
    // صفوف حدود الجودة — المؤشر بيبدأ بعد آخر صف موجود عشان الأسماء ماتتكررش
    let specLimitSeq = {{ count($limits) + 1000 }};
    function specAddLimit() {
        const tpl = document.getElementById('specLimitRow');
        const html = tpl.innerHTML.replaceAll('__I__', String(specLimitSeq++));
        document.getElementById('specLimits').insertAdjacentHTML('beforeend', html);
        document.getElementById('specLimitsEmpty').hidden = true;
    }
</script>
@endsection
