{{-- ═══ مين معاه الصلاحية: اختار مفتاح ⇒ كل الرولز (فعلي + مصدر) والموظفين اللي عليهم استثناء ═══ --}}
<div class="card">
    <h3>🔎 {{ __('perm.who_title') }}
        <span class="side">{{ __('perm.who_hint') }}</span></h3>
    <form method="GET" action="{{ route('erp.perms') }}" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <input type="hidden" name="tab" value="who">
        <label class="fl grow"><span>{{ __('perm.who_pick') }}</span>
            <select name="key" onchange="this.form.submit()">
                <option value="">{{ __('perm.who_pick_ph') }}</option>
                @foreach ($picker as $g => $keys)
                    @continue($keys === [])
                    <optgroup label="{{ __($g) }}">
                        @foreach ($keys as $k => $label)
                            <option value="{{ $k }}" @selected($whoKey === $k)>{{ $label === $k ? $k : $label.' — '.$k }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select></label>
    </form>
</div>

@if ($whoKey !== null)
    @php $info = $whoInfo; $on = collect($cells)->where('effective', true)->count(); @endphp
    <div class="card">
        <h3>{{ $info['title'] }}
            {!! $risk($info['risk']) !!}
            <span class="badge b-gray">{{ __('perm.kind_'.$info['kind']) }}</span>
            <span class="side pm-key">{{ $whoKey }}</span></h3>
        @if ($info['with_what'] || $info['kind'] === 'screen')<p style="font-size:13px;line-height:1.7;margin-bottom:6px">{{ $info['what'] }}</p>@endif
        @if ($info['data'])<p class="pm-muted" style="line-height:1.7"><b>{{ __('perm.data_label') }}:</b> {{ $info['data'] }}</p>@endif
        @if ($info['locked'])
            <div class="alert warn" style="margin-top:10px"><span>🔒</span><span>{{ __('perm.locked_reason') }}</span></div>
        @endif
        <div class="pm-muted" style="margin-top:10px">{{ __('perm.who_summary', ['on' => $on, 'all' => count($cells)]) }}</div>
    </div>

    <div class="card">
        <h3>👥 {{ __('perm.who_roles') }}</h3>
        <div class="tablewrap">
            <table>
                <thead><tr>
                    <th>{{ __('perm.c_role') }}</th><th>{{ __('perm.base') }}</th>
                    <th>{{ __('perm.effective') }}</th><th>{{ __('perm.c_source') }}</th>
                    <th>{{ __('perm.c_setting') }}</th><th>{{ __('perm.c_users') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($cells as $rk => $c)
                    <tr data-who-role="{{ $rk }}" data-eff="{{ $c['effective'] ? '1' : '0' }}" data-src="{{ $c['source'] }}">
                        <td><a href="{{ $tabUrl('matrix', ['role' => $rk]) }}"><b>{{ $roles[$rk]['label'] }}</b></a>
                            @if ($roles[$rk]['custom'])<span class="badge b-blue">{{ __('perm.custom') }}</span>@endif
                            @unless ($roles[$rk]['active'])<span class="badge b-orange">{{ __('common.inactive') }}</span>@endunless</td>
                        <td>{{ __('enums.role.'.$roles[$rk]['base']) }}</td>
                        <td>{!! $state($c['effective']) !!}</td>
                        <td>{!! $src($c['source'], $c['via']) !!}</td>
                        <td>@if ($info['locked'])🔒 <span class="pm-muted">{{ __('perm.locked_short') }}</span>@elseif ($whoKey === \App\Support\Access::PUBLIC_GROUP)<span class="pm-muted">—</span>@else{{ $ov($c['override']) }}@endif</td>
                        <td class="num">{{ $roles[$rk]['active_users'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>🧍 {{ __('perm.who_users') }}
            <span class="side">{{ __('perm.who_users_hint') }}</span></h3>
        @if ($overrides->isEmpty())
            <div class="pm-muted">{{ __('perm.who_no_users') }}</div>
        @else
            <div class="tablewrap">
                <table>
                    <thead><tr>
                        <th>{{ __('ui.l_user') }}</th><th>{{ __('perm.c_role') }}</th>
                        <th>{{ __('perm.c_setting') }}</th><th>{{ __('perm.effective') }}</th><th class="act"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($overrides as $o)
                        <tr data-who-user="{{ $o['user']->id }}" data-override="{{ $o['allow'] ? '1' : '0' }}">
                            <td><b>{{ $o['user']->name }}</b>@if($o['user']->code) <span class="pm-muted">({{ $o['user']->code }})</span>@endif
                                @unless ($o['user']->active)<span class="badge b-orange">{{ __('common.inactive') }}</span>@endunless</td>
                            <td>{{ $o['user']->roleLabel() }}</td>
                            <td>{!! $src('user') !!} {{ $ov($o['allow']) }}</td>
                            <td>{!! $state($o['effective']) !!}</td>
                            <td class="act"><a class="btn sm" href="{{ $tabUrl('matrix', ['user' => $o['user']->id]) }}">🧩 {{ __('perm.open_matrix') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@else
    <div class="alert info"><span>ℹ️</span><span>{{ __('perm.who_empty') }}</span></div>
@endif
