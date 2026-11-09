{{-- ═══ مقارنة رولين جنب بعض — الفروق متعلّمة، و«الفروق بس» بتشيل الباقي (سيرفر) ═══ --}}
@php
    $roleOpts = function (string $sel) use ($roles) {
        $html = '';

        foreach ($roles as $k => $r) {
            $html .= '<option value="'.e($k).'"'.($sel === $k ? ' selected' : '').'>'.e($r['label'])
                .($r['custom'] ? ' — '.e(__('enums.role.'.$r['base'])) : '').'</option>';
        }

        return $html;
    };
@endphp

<div class="card">
    <h3>⚖️ {{ __('perm.cmp_title') }}
        <span class="side">{{ __('perm.cmp_hint') }}</span></h3>
    <form method="GET" action="{{ route('erp.perms') }}" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <input type="hidden" name="tab" value="compare">
        <label class="fl wide"><span>{{ __('perm.cmp_a') }}</span>
            <select name="a" onchange="this.form.submit()">{!! $roleOpts($cmpA) !!}</select></label>
        <a class="btn sm" style="margin-bottom:4px" title="{{ __('perm.cmp_swap') }}" aria-label="{{ __('perm.cmp_swap') }}"
           href="{{ $tabUrl('compare', ['a' => $cmpB, 'b' => $cmpA] + ($cmpOnly ? ['only' => 1] : [])) }}">⇄</a>
        <label class="fl wide"><span>{{ __('perm.cmp_b') }}</span>
            <select name="b" onchange="this.form.submit()">{!! $roleOpts($cmpB) !!}</select></label>
        <label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:700;padding-bottom:9px">
            <input type="checkbox" name="only" value="1" @checked($cmpOnly) onchange="this.form.submit()"> {{ __('perm.cmp_only_diff') }}</label>
        <span class="badge {{ $cmpDiffs ? 'b-orange' : 'b-green' }}" style="margin-bottom:9px">{{ __('perm.cmp_diffs_n', ['n' => $cmpDiffs]) }}</span>
    </form>
</div>

<div class="card">
    <h3>{{ $roleName($cmpA) }} ⇄ {{ $roleName($cmpB) }}</h3>
    @if ($cmpRows === [])
        <div class="alert good"><span>✅</span><span>{{ __('perm.cmp_same') }}</span></div>
    @else
        <div class="tablewrap">
            <table class="pm-cmp">
                <thead><tr>
                    <th>{{ __('perm.c_item') }}</th>
                    <th style="width:88px">{{ __('perm.c_risk') }}</th>
                    <th>{{ $roleName($cmpA) }}</th>
                    <th>{{ $roleName($cmpB) }}</th>
                </tr></thead>
                <tbody>
                @foreach ($cmpRows as $x)
                    @php $r = $x['row']; $b = $x['b']; $title = $r['kind'] === 'group' ? $r['label'] : \App\Http\Controllers\PermissionController::rowTitle($r)[0]; @endphp
                    <tr data-cmp-key="{{ $r['key'] }}" @class(['pm-cg' => $r['kind'] === 'group', 'pm-diff' => $x['differs']])>
                        <td>
                            <span style="padding-inline-start:{{ $r['depth'] * 18 }}px;display:inline-block">
                                @if ($r['kind'] === 'group'){{ $r['icon'] }} @endif
                                <b>{{ $title }}</b>
                                <div class="pm-key">{{ $r['key'] }}</div>
                            </span>
                        </td>
                        <td>{!! $risk($r['risk']) !!}</td>
                        <td>{!! $state($r['effective']) !!}<div>{!! $src($r['source'], $r['via'] ?? null) !!}</div></td>
                        <td>@if ($b){!! $state($b['effective']) !!}<div>{!! $src($b['source'], $b['via'] ?? null) !!}</div>@else — @endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<style>
.pm-cmp td{vertical-align:top}
.pm-cmp tr.pm-cg td{background:var(--card2)}
.pm-cmp tr.pm-diff td{background:#FFF8E1}
.pm-cmp tr.pm-diff td:first-child b::after{content:" ≠";color:#9A5A08}
</style>
