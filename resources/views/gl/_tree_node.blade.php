{{--
    عقدة واحدة في شجرة الحسابات — بتنادي نفسها لأولادها.
    المجموعة (`is_postable = false`) بتتفتح في `<details open>` عشان
    الشجرة كلها مفتوحة من أول نظرة، والورقة لينك لكشف حسابها.
--}}

@php
    $kids = $children[$node->id] ?? [];
    $bal = $balances[$node->id] ?? 0.0;
    $payload = json_encode([
        'id' => $node->id,
        'code' => $node->code,
        'name' => $node->name,
        'name_en' => $node->name_en,
        'parent_id' => $node->parent_id,
        'is_system' => (bool) $node->is_system,
        'active' => (bool) $node->active,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

@if ($kids)
    <details open class="gl-node" style="margin:2px 0">
        <summary style="display:flex;align-items:center;gap:8px;padding:4px 2px;cursor:pointer">
            <b dir="ltr">{{ $node->code }}</b>
            <span>{{ $node->displayName() }}</span>
            @unless ($node->active)
                <span class="badge b-gray">{{ __('gl.inactive') }}</span>
            @endunless
            <span class="num" style="margin-inline-start:auto;font-variant-numeric:tabular-nums">
                {{ number_format((float) $bal, 2) }}
            </span>
            @if ($canPost)
                <button class="btn sm" type="button" title="{{ __('gl.edit_account') }}"
                        onclick='glEditAccount({!! $payload !!}); event.preventDefault()'>✎</button>
            @endif
        </summary>
        <div style="padding-inline-start:18px;border-inline-start:1px dashed var(--line)">
            @foreach ($kids as $kid)
                @include('gl._tree_node', ['node' => $kid, 'children' => $children, 'balances' => $balances, 'canPost' => $canPost])
            @endforeach
        </div>
    </details>
@else
    <div class="gl-leaf" style="display:flex;align-items:center;gap:8px;padding:4px 2px">
        <a href="{{ route('gl.accounts.show', $node) }}" style="display:flex;align-items:center;gap:8px;flex:1">
            <b dir="ltr">{{ $node->code }}</b>
            <span>{{ $node->displayName() }}</span>
        </a>
        @unless ($node->active)
            <span class="badge b-gray">{{ __('gl.inactive') }}</span>
        @endunless
        <span class="num" style="font-variant-numeric:tabular-nums">{{ number_format((float) $bal, 2) }}</span>
        @if ($canPost)
            <button class="btn sm" type="button" title="{{ __('gl.edit_account') }}"
                    onclick='glEditAccount({!! $payload !!})'>✎</button>
        @endif
    </div>
@endif
