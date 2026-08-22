@extends('layouts.system')

{{--
    تسكين عملاء التشانل مانجر (2026-08-05) — نفس نمط شاشة تسكين
    المناديب: اختار المدير ← علّم على العملاء ← أساين ← بيختفوا من
    قايمة «من غير مدير» ويبقوا معاه. وده أساس سكوبينج المدير.
--}}

@section('title', __('perm.manager_clients'))

@section('content')

<div class="card">
    <h3>🧑‍💼 {{ __('perm.manager_clients') }}
        <span class="side">{{ __('perm.manager_clients_hint') }}</span></h3>

    @if (session('ok'))
        <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
    @endif

    <form method="GET" class="searchbar">
        <label class="f" style="margin:0">{{ __('perm.pick_manager') }}</label>
        <select name="manager" onchange="this.form.submit()" style="min-width:240px">
            @foreach ($managers as $m)
                <option value="{{ $m->id }}" @selected($manager?->id === $m->id)>
                    {{ $m->name }} @if($m->code) ({{ $m->code }}) @endif
                </option>
            @endforeach
        </select>
        <span class="badge b-purple">{{ __('perm.his_clients') }}: {{ $mine->count() }}</span>
    </form>

    @if ($managers->isEmpty())
        <div class="alert warn"><span>⚠️</span><span>{{ __('perm.no_managers') }}</span></div>
    @endif
</div>

{{-- ═══ «شوف زي المندوب» (٢١/٨) ═══
     بيشغّل نفس كويري الأبلكيشن لكل مندوب ويقول هيشوف كام عميل
     فعلاً — الرقم ده هو الحقيقة، مش التسكين على الورق. --}}
@if ($manager !== null && count($seeAs ?? []) > 0)
    <div class="card">
        <h3>📱 {{ __('perm.sa_title') }} <span class="side">{{ __('perm.sa_sub') }}</span></h3>
        <div class="kpis" style="margin:0">
            @foreach ($seeAs as $s)
                <div class="kpi" style="{{ $s['clients'] < $mine->count() ? 'border-color:var(--red)' : '' }}">
                    <div class="lbl">{{ $s['user']->displayName() }}</div>
                    <div class="val {{ $s['clients'] < $mine->count() ? 'neg' : 'pos' }}">
                        {{ number_format($s['clients']) }}
                        <span style="font-size:12px;color:var(--muted)">/ {{ $mine->count() }}</span>
                    </div>
                    <div class="sub2">
                        {{ __('perm.sa_zones', ['n' => $s['zones']]) }}
                        @if (! $s['linked'])
                            <br><span class="badge b-red" style="font-size:9.5px">⚠️ {{ __('perm.sa_unlinked') }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @php $broken = collect($seeAs)->firstWhere('linked', false); @endphp
        @if ($broken)
            <div class="alert warn" style="margin-top:10px">
                <span>⚠️</span><span>{{ __('perm.sa_fix') }}</span>
            </div>
        @endif

        {{-- ═══ إصلاح التغطية بأثر رجعي — للتسكينات القديمة ═══ --}}
        <form method="POST" action="{{ route('erp.managers.clients.repair') }}"
              style="margin-top:10px" onsubmit="return confirm(@js(__('perm.cov_confirm')))">
            @csrf
            <button class="btn gold" type="submit">🔧 {{ __('perm.cov_btn') }}</button>
            <span class="s" style="margin-inline-start:8px;color:var(--muted)">{{ __('perm.cov_hint') }}</span>
        </form>
    </div>
@endif

{{-- ═══ فاحص الظهور (٢١/٨) ═══
     «العميل عند المدير وشغّال ومع ذلك المندوب مش شايفه» — الفاحص
     بيقول السبب بالحرف بدل ما نفضل نخمّن. --}}
@if ($manager !== null && count($blockers ?? []) > 0)
    @php
        $vizReasons = [
            'status' => __('perm.viz_status'),
            'nozone' => __('perm.viz_nozone'),
            'zoneoff' => __('perm.viz_zoneoff'),
            'noteam' => __('perm.viz_noteam'),
        ];
    @endphp
    <div class="card" style="border:1.5px solid var(--red)">
        <h3 style="color:var(--red)">🚫 {{ __('perm.viz_title', ['n' => count($blockers)]) }}
            <span class="side">{{ __('perm.viz_sub') }}</span>
        </h3>
        <div class="tablewrap" style="max-height:320px;overflow:auto">
            <table>
                <thead>
                <tr>
                    <th>{{ __('common.code') }}</th>
                    <th style="text-align:start">{{ __('perm.client') }}</th>
                    <th>{{ __('nav.zones') }}</th>
                    <th style="text-align:start">{{ __('perm.viz_reason') }}</th>
                    <th class="act"></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($blockers as $b)
                    <tr>
                        <td class="num" dir="ltr">{{ $b['client']->code }}</td>
                        <td style="text-align:start"><b>{{ $b['client']->fullName() }}</b></td>
                        <td>{{ $b['client']->zone?->displayName() ?? '—' }}</td>
                        <td style="text-align:start;color:var(--red);font-weight:800">
                            {{ $vizReasons[$b['why']] ?? $b['why'] }}
                        </td>
                        <td class="act">
                            {{-- الحل في مكانه: الزون الموقوف من شاشة المناطق،
                                 والباقي من كارت العميل --}}
                            @if ($b['why'] === 'zoneoff')
                                <a class="btn sm" href="{{ route('erp.zones') }}">🗺️ {{ __('perm.viz_fix_zone') }}</a>
                            @elseif ($b['why'] === 'noteam')
                                <span class="s">{{ __('perm.viz_fix_team') }}</span>
                            @else
                                <a class="btn sm" href="{{ route('erp.clients.show', $b['client']) }}">✏️ {{ __('perm.viz_fix_client') }}</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($manager !== null)
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start">

    {{-- ═══ عملاؤه ═══ --}}
    <div class="card">
        <h3>✅ {{ __('perm.his_clients') }} — {{ $manager->name }}</h3>
        <div class="tablewrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('client.client') }}</th>
                        <th>{{ __('nav.channels') }}</th>
                        <th style="width:44px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mine as $c)
                        <tr>
                            <td><b>{{ $c->fullName() }}</b>
                                <div style="font-size:10.5px;color:var(--muted)">{{ $c->code }}</div></td>
                            <td>{{ $c->channel?->displayName() ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('erp.managers.unassign', $c) }}"
                                      onsubmit="return confirm(@json(__('perm.unassign_confirm')))">
                                    @csrf @method('DELETE')
                                    <button class="btn sm red" type="submit">✕</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:20px">
                            {{ __('perm.none_assigned') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ═══ من غير مدير ═══ --}}
    <div class="card">
        <h3>📥 {{ __('perm.unassigned_clients') }}
            <span class="side">{{ __('perm.pool_hint') }}</span></h3>

        <form method="GET" class="searchbar">
            <input type="hidden" name="manager" value="{{ $manager->id }}">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="🔍 {{ __('stock.search_item') }}">
            <select name="channel">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($channels as $ch)
                    <option value="{{ $ch->id }}" @selected(request('channel') == $ch->id)>{{ $ch->displayName() }}</option>
                @endforeach
            </select>
            <button class="btn" type="submit">{{ __('common.search') }}</button>
        </form>

        <form method="POST" action="{{ route('erp.managers.assign') }}">
            @csrf
            <input type="hidden" name="manager_id" value="{{ $manager->id }}">
            <div class="tablewrap" style="max-height:56vh;overflow-y:auto">
                <table>
                    <thead>
                        <tr>
                            <th style="width:34px">
                                <input type="checkbox" onchange="document.querySelectorAll('.pickc').forEach(c => c.checked = this.checked)">
                            </th>
                            <th>{{ __('client.client') }}</th>
                            <th>{{ __('nav.channels') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pool as $c)
                            <tr>
                                <td><input type="checkbox" class="pickc" name="client_ids[]" value="{{ $c->id }}"></td>
                                <td><b>{{ $c->fullName() }}</b>
                                    <div style="font-size:10.5px;color:var(--muted)">{{ $c->code }}</div></td>
                                <td>{{ $c->channel?->displayName() ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:20px">
                                {{ __('common.no_results') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:10px">
                <button class="btn gold" type="submit">➕ {{ __('perm.assign_selected') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- ═══ فريق الميدان: مناديب وسواقين وبروموترز (2026-08-05) ═══ --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;margin-top:14px">

    <div class="card">
        <h3>🚚 {{ __('perm.his_team') }} — {{ $manager->name }}
            <span class="side">{{ __('perm.his_team_hint') }}</span></h3>
        <div class="tablewrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('perm.pick_user') }}</th>
                        <th>{{ __('team.role') }}</th>
                        <th style="width:44px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($myTeam as $u2)
                        <tr>
                            <td><b>{{ $u2->displayName() }}</b>
                                <div style="font-size:10.5px;color:var(--muted)">{{ $u2->code }}</div></td>
                            <td><span class="badge b-purple">{{ $u2->roleLabel() }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('erp.managers.team.unassign', $u2) }}"
                                      onsubmit="return confirm(@json(__('perm.team_unassign_confirm')))">
                                    @csrf @method('DELETE')
                                    <button class="btn sm red" type="submit">✕</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:20px">
                            {{ __('perm.no_team_assigned') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>📥 {{ __('perm.unassigned_team') }}
            <span class="side">{{ __('perm.team_pool_hint') }}</span></h3>
        <form method="POST" action="{{ route('erp.managers.team.assign') }}">
            @csrf
            <input type="hidden" name="manager_id" value="{{ $manager->id }}">
            <div class="tablewrap" style="max-height:48vh;overflow-y:auto">
                <table>
                    <thead>
                        <tr>
                            <th style="width:34px">
                                <input type="checkbox" onchange="document.querySelectorAll('.pickt').forEach(c => c.checked = this.checked)">
                            </th>
                            <th>{{ __('perm.pick_user') }}</th>
                            <th>{{ __('team.role') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($teamPool as $u2)
                            <tr>
                                <td><input type="checkbox" class="pickt" name="user_ids[]" value="{{ $u2->id }}"></td>
                                <td><b>{{ $u2->displayName() }}</b>
                                    <div style="font-size:10.5px;color:var(--muted)">{{ $u2->code }}</div></td>
                                <td><span class="badge b-purple">{{ $u2->roleLabel() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:20px">
                                {{ __('common.no_results') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:10px">
                <button class="btn gold" type="submit">➕ {{ __('perm.assign_selected') }}</button>
            </div>
        </form>
    </div>
</div>
@endif

@endsection

@section('scripts')
<style>
/* شغل الحديد (٢٢/٨): هيدرات الجداول ثابتة مع التمرير في كل
   جداول الصفحة — صفحة التسكين فيها ٤ جداول طويلة */
.tablewrap thead th{position:sticky;top:0;z-index:3;background:var(--royal-blue);color:#fff}
.tablewrap tbody tr:hover td{background:var(--blue-050)}
</style>
@endsection
