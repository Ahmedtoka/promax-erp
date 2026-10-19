{{-- ٣ — مراكز التكلفة: الشجرة + إضافة خط/فرع + البذرة (معاينة الأول) --}}
@php $seed = session('seed'); @endphp

<div class="tablewrap">
    <table>
        <tr>
            <th data-nosum style="text-align:start">{{ __('golive.s3_center') }}</th>
            <th data-nosum>{{ __('golive.s3_kind') }}</th>
            <th data-nosum>{{ __('golive.s3_active') }}</th>
        </tr>
        @forelse ($tree as $row)
            <tr>
                <td style="text-align:start;padding-inline-start:{{ 10 + $row['depth'] * 18 }}px">
                    <b class="num">{{ $row['center']->code }}</b> — {{ $row['center']->displayName() }}</td>
                <td><span class="badge b-blue">{{ __('golive.kind_'.$row['center']->kind) }}</span></td>
                <td>{{ $row['center']->active ? '✓' : '✕' }}</td>
            </tr>
        @empty
            <tr><td colspan="3" style="color:var(--muted)">{{ __('golive.s3_empty') }}</td></tr>
        @endforelse
    </table>
</div>

@if (is_array($seed))
    <div class="alert info" style="margin-top:10px"><span>🌱</span><span>
        <b>{{ $seed['dry'] ? __('golive.s3_seed_dry') : __('golive.s3_seeded') }}</b>
        @foreach (['created', 'linked', 'unlinked'] as $k)
            <div>{{ __('golive.s3_seed_'.$k) }}: {{ count($seed[$k] ?? []) }}
                @foreach ($seed[$k] ?? [] as $line)<div style="font-size:11.5px">• {{ $line }}</div>@endforeach
            </div>
        @endforeach
    </span></div>
@endif

@if ($canAdmin)
    <div style="display:flex;gap:8px;margin:12px 0;flex-wrap:wrap">
        <form method="POST" action="{{ route('gl.golive.centers.seed') }}">@csrf
            <button class="btn" type="submit">🌱 {{ __('golive.s3_seed_preview') }}</button></form>
        <form method="POST" action="{{ route('gl.golive.centers.seed') }}">@csrf
            <input type="hidden" name="apply" value="1">
            <button class="btn gold" type="submit">🌱 {{ __('golive.s3_seed_apply') }}</button></form>
    </div>

    <form method="POST" action="{{ route('gl.golive.centers.add') }}" class="frow">
        @csrf
        <label class="fl"><span>{{ __('golive.s3_kind') }}</span>
            <select name="kind" required>
                <option value="line">{{ __('golive.kind_line') }}</option>
                <option value="branch">{{ __('golive.kind_branch') }}</option>
            </select></label>
        <label class="fl"><span>{{ __('golive.s3_parent') }}</span>
            <select name="parent_id" required>
                @foreach ($parents as $p)
                    <option value="{{ $p->id }}">{{ $p->code }} — {{ $p->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('golive.s3_code') }}</span><input name="code" maxlength="30" required></label>
        <label class="fl"><span>{{ __('golive.s3_name') }}</span><input name="name" maxlength="120" required></label>
        <label class="fl"><span>{{ __('golive.s3_name_en') }}</span><input name="name_en" maxlength="120"></label>
        <button class="btn gold" type="submit">＋ {{ __('golive.s3_add') }}</button>
    </form>
@endif
