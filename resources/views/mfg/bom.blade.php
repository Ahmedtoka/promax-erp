@extends('layouts.system')

@section('title', __('mfg.bom_heading', ['product' => $bom->product?->displayName() ?? '—', 'version' => $bom->version]))

@section('content')
@php
    $badge = ['draft' => 'b-gray', 'approved' => 'b-green', 'archived' => 'b-purple'];
    $user = auth()->user();
    $canEdit = \App\Support\Access::action($user, 'act.mfg.boms_edit');
    $canApprove = \App\Support\Access::action($user, 'act.mfg.boms_approve');
    $isDraft = $bom->status === 'draft';
    $editable = $canEdit && $isDraft;
    // ⚠️ مسودة واحدة لكل منتج — زرار «إصدار جديد» بيختفي لو فيه مسودة
    $hasDraft = $versions->contains(fn ($v) => $v->status === 'draft');
    $num = fn ($v, $dp = 4) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, $dp, '.', ''), '0'), '.');
    $rows = old('lines', $bom->lines->map(fn ($l) => [
        'product_id' => $l->product_id, 'qty' => $num($l->qty), 'basis' => $l->basis,
    ])->all());
    // سطر خامته اتقفلت بعد ما اتحطت لسه لازم يبان باسمه في الاختيار
    $options = collect($materials)->keyBy('id');
    foreach ($bom->lines as $l) {
        if (! $options->has($l->product_id) && $l->product) {
            $options->put($l->product_id, [
                'id' => $l->product_id, 'label' => $l->product->code.' — '.$l->product->displayName(),
                'unit' => $l->product->unitLabel(), 'kind' => $l->product->kind,
            ]);
        }
    }
@endphp

<div class="card">
    <h3>🧾 {{ __('mfg.bom_heading', ['product' => $bom->product?->displayName() ?? '—', 'version' => $bom->version]) }}
        <span class="side">
            <span class="badge {{ $badge[$bom->status] ?? 'b-gray' }}">{{ $bom->statusLabel() }}</span>
            @if ($bom->status === 'approved')<span class="badge b-blue">{{ __('mfg.bom_current') }}</span>@endif
        </span>
    </h3>

    <div class="frow" style="margin-bottom:0">
        <div><label class="f">{{ __('mfg.col_created_by') }}</label><div>{{ $bom->creator?->displayName() ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_approved_by') }}</label><div>{{ $bom->approver?->displayName() ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_approved_at') }}</label><div class="num">{{ $bom->approved_at?->format('Y-m-d H:i') ?? '—' }}</div></div>
        <div><label class="f">{{ __('mfg.col_updated_at') }}</label><div class="num">{{ $bom->updated_at?->format('Y-m-d H:i') ?? '—' }}</div></div>
    </div>

    {{-- معاينة بس — برّه الفورم (مش بتتبعت) وبتشتغل حتى على المعتمدة المقفولة --}}
    <div class="frow" style="margin:12px 0 0" data-noprint>
        <div>
            <label class="f">{{ __('mfg.f_preview_output') }}</label>
            <input type="number" id="bomPreview" step="1" min="1" value="1000">
            <div class="s" style="color:var(--muted)">{{ __('mfg.preview_hint') }}</div>
        </div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px" data-noprint>
        <a class="btn" href="{{ route('mfg.boms') }}">← {{ __('mfg.back_to_boms') }}</a>

        @if ($canApprove && $isDraft)
            <form method="POST" action="{{ route('mfg.boms.approve', $bom) }}"
                  onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('mfg.confirm_bom_approve')) }})">
                @csrf
                <button class="btn green" type="submit">✔ {{ __('mfg.btn_approve') }}</button>
            </form>
        @endif

        @if ($canEdit && ! $isDraft && ! $hasDraft)
            <form method="POST" action="{{ route('mfg.boms.store') }}" title="{{ __('mfg.bom_new_version_hint') }}">
                @csrf
                <input type="hidden" name="product_id" value="{{ $bom->product_id }}">
                <input type="hidden" name="copy_from" value="{{ $bom->id }}">
                <button class="btn" type="submit">➕ {{ __('mfg.new_version') }}</button>
            </form>
        @endif
    </div>

    @if (! $isDraft)
        <div class="alert" style="margin-top:12px">{{ __('mfg.locked_final') }}</div>
    @elseif ($editable)
        <div class="s" style="margin-top:12px;color:var(--muted)">{{ __('mfg.bom_draft_hint') }}</div>
    @endif
</div>

{{-- ⚠️ المسودة بس بتتعدل — غير كده الحقول للقراية (`fieldset disabled`) والسيرفر بيرفض برضه --}}
<form method="POST" action="{{ route('mfg.boms.lines', $bom) }}" id="bomForm">
    @csrf
    <fieldset @disabled(! $editable) style="border:0;padding:0;margin:0;min-width:0">

    <div class="card">
        <h3>🥣 {{ __('mfg.bom_lines_title') }}
            @if ($editable && $options->isNotEmpty())
                <span class="side"><button class="btn sm" type="button" onclick="bomAddLine()">➕ {{ __('mfg.bom_add_line') }}</button></span>
            @endif
        </h3>

        @if ($editable && $options->isEmpty())
            <div class="alert">{{ __('mfg.no_materials') }}</div>
        @endif

        <div class="frow">
            <div>
                <label class="f">{{ __('mfg.f_waste_pct') }} *</label>
                <input type="number" name="waste_pct" id="bomWaste" step="0.01" min="0" max="100" required
                       value="{{ old('waste_pct', $num($bom->waste_pct, 2) ?: '0') }}">
            </div>
        </div>

        <div class="tablewrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('mfg.col_material') }}</th>
                        <th class="num" data-nosum>{{ __('mfg.col_qty') }}</th>
                        <th>{{ __('mfg.col_basis') }}</th>
                        <th class="num" data-nosum>{{ __('mfg.col_std_qty') }}</th>
                        <th data-noexport></th>
                    </tr>
                </thead>
                <tbody id="bomLines">
                    @foreach ($rows as $i => $r)
                        <tr>
                            <td>
                                <select name="lines[{{ $i }}][product_id]" required>
                                    <option value="">—</option>
                                    @foreach ($options as $m)
                                        <option value="{{ $m['id'] }}" data-unit="{{ $m['unit'] }}" @selected((string) ($r['product_id'] ?? '') === (string) $m['id'])>{{ $m['label'] }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="num"><input type="number" step="0.0001" min="0.0001" name="lines[{{ $i }}][qty]" required value="{{ $r['qty'] ?? '' }}"></td>
                            <td>
                                <select name="lines[{{ $i }}][basis]" required>
                                    @foreach (\App\Models\BomLine::BASES as $basis)
                                        <option value="{{ $basis }}" @selected(($r['basis'] ?? 'per_unit') === $basis)>{{ __('mfg.basis_'.$basis) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="num" data-std>—</td>
                            <td data-noexport>@if ($editable)<button class="btn sm red" type="button" aria-label="{{ __('mfg.bom_remove_line') }}" onclick="bomRemoveLine(this)">✕</button>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
                <tbody>
                    <tr id="bomLinesEmpty" @if (count($rows) > 0) hidden @endif>
                        <td colspan="5" style="text-align:center;color:var(--muted);padding:20px">{{ __('mfg.no_bom_lines') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="frow" style="margin-bottom:0;margin-top:12px">
            <div>
                <label class="f">{{ __('mfg.f_notes') }}</label>
                <textarea name="notes" rows="3" maxlength="5000">{{ old('notes', $bom->notes) }}</textarea>
            </div>
        </div>
    </div>

    </fieldset>

    @if ($editable)
        <div style="display:flex;justify-content:flex-end;margin-bottom:16px" data-noprint>
            <button class="btn" type="submit">💾 {{ __('mfg.btn_save_bom') }}</button>
        </div>
    @endif
</form>

{{-- سطر جديد — `__I__` بيتبدل برقم جديد وقت الإضافة --}}
<template id="bomLineRow">
    <tr>
        <td>
            <select name="lines[__I__][product_id]" required>
                <option value="">—</option>
                @foreach ($options as $m)
                    <option value="{{ $m['id'] }}" data-unit="{{ $m['unit'] }}">{{ $m['label'] }}</option>
                @endforeach
            </select>
        </td>
        <td class="num"><input type="number" step="0.0001" min="0.0001" name="lines[__I__][qty]" required></td>
        <td>
            <select name="lines[__I__][basis]" required>
                @foreach (\App\Models\BomLine::BASES as $basis)
                    <option value="{{ $basis }}">{{ __('mfg.basis_'.$basis) }}</option>
                @endforeach
            </select>
        </td>
        <td class="num" data-std>—</td>
        <td data-noexport><button class="btn sm red" type="button" aria-label="{{ __('mfg.bom_remove_line') }}" onclick="bomRemoveLine(this)">✕</button></td>
    </tr>
</template>

<div class="card">
    <h3>🗂 {{ __('mfg.bom_versions') }} <span class="side">{{ $bom->product?->displayName() }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th class="num" data-nosum>{{ __('mfg.col_version') }}</th>
                <th>{{ __('mfg.col_status') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_lines') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_waste_pct') }}</th>
                <th>{{ __('mfg.col_created_by') }}</th>
                <th>{{ __('mfg.col_approved_by') }}</th>
                <th data-nosum>{{ __('mfg.col_updated_at') }}</th>
            </tr>
            @foreach ($versions as $v)
                <tr class="clickable" onclick="location.href='{{ route('mfg.boms.show', $v) }}'" @if ($v->id === $bom->id) style="font-weight:700" @endif>
                    <td class="num">{{ $v->version }}</td>
                    <td>
                        <span class="badge {{ $badge[$v->status] ?? 'b-gray' }}">{{ $v->statusLabel() }}</span>
                        @if ($v->status === 'approved')<span class="badge b-blue">{{ __('mfg.bom_current') }}</span>@endif
                    </td>
                    <td class="num">{{ $v->lines_count }}</td>
                    <td class="num">{{ $num($v->waste_pct, 2) ?: '0' }}</td>
                    <td>{{ $v->creator?->displayName() ?? '—' }}</td>
                    <td>{{ $v->approver?->displayName() ?? '—' }}</td>
                    <td class="num s">{{ $v->updated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

<script>
    // سطور التركيبة — المؤشر بيبدأ بعد آخر سطر موجود عشان الأسماء ماتتكررش
    let bomLineSeq = {{ count($rows) + 1000 }};
    function bomAddLine() {
        const tpl = document.getElementById('bomLineRow');
        const html = tpl.innerHTML.replaceAll('__I__', String(bomLineSeq++));
        document.getElementById('bomLines').insertAdjacentHTML('beforeend', html);
        document.getElementById('bomLinesEmpty').hidden = true;
        bomPreview();
    }
    function bomRemoveLine(btn) {
        btn.closest('tr').remove();
        document.getElementById('bomLinesEmpty').hidden = document.querySelectorAll('#bomLines tr').length > 0;
    }
    // نفس معادلة `Bom::stdFor`: الأساس × (١ + الهالك ÷ ١٠٠)، مقرّب لـ٣ أرقام
    function bomPreview() {
        const out = parseInt(document.getElementById('bomPreview').value, 10) || 0;
        const waste = parseFloat(document.getElementById('bomWaste').value) || 0;
        document.querySelectorAll('#bomLines tr').forEach(tr => {
            const qty = parseFloat(tr.querySelector('input[name$="[qty]"]').value) || 0;
            const basis = tr.querySelector('select[name$="[basis]"]').value;
            const opt = tr.querySelector('select[name$="[product_id]"]').selectedOptions[0];
            const base = basis === 'per_batch' ? qty : qty * out;
            const std = Math.round(base * (1 + waste / 100) * 1000) / 1000;
            const unit = opt && opt.dataset.unit ? ' ' + opt.dataset.unit : '';
            tr.querySelector('[data-std]').textContent = qty > 0 ? std.toLocaleString(undefined, {maximumFractionDigits: 3}) + unit : '—';
        });
    }
    document.getElementById('bomForm').addEventListener('input', bomPreview);
    document.getElementById('bomForm').addEventListener('change', bomPreview);
    document.getElementById('bomPreview').addEventListener('input', bomPreview);
    bomPreview();
</script>
@endsection
