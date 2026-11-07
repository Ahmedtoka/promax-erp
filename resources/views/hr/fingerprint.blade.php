@extends('layouts.system')

{{--
    أجهزة البصمة ZKTeco (ADMS — ٧/١١/٢٠٢٦): المكن وحالتها، الربط برقم الموظف،
    البصمات اللحظية (polling على `hr.fingerprint.live`)، وخطوات إعداد المكنة.
--}}

@section('title', __('fp.devices_title'))

@php
    $resultBadge = ['applied' => 'b-green', 'duplicate' => 'b-gray', 'unmapped' => 'b-orange', 'old' => 'b-gray', 'inactive' => 'b-red'];
    $cmdBadge = ['pending' => 'b-gray', 'sent' => 'b-blue', 'done' => 'b-green', 'failed' => 'b-red'];
    $empOptions = $employees->map(fn ($e) => ['id' => $e->id, 'label' => $e->code.' · '.$e->displayName().($e->fp_pin ? ' ('.$e->fp_pin.')' : '')]);
    $pingUrl = 'http://'.$host.'/iclock/ping';
@endphp

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('fp.devices_title') }}</div><div class="val">{{ $devices->count() }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('fp.online') }}</div><div class="val pos" id="kpiOnline">{{ $devices->filter->isOnline()->count() }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('fp.mapped_employees') }}</div><div class="val">{{ $employees->whereNotNull('fp_pin')->count() }} / {{ $employees->count() }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('fp.unmapped_title') }}</div><div class="val {{ $unmapped->isNotEmpty() ? 'mid' : '' }}">{{ $unmapped->count() }}</div></div>
</div>

@if ($unknown !== [])
    <div class="card" style="border-color:var(--orange)">
        <h3>📡 {{ __('fp.unknown_title') }} <span class="side">{{ __('fp.unknown_hint') }}</span></h3>
        <div class="tablewrap"><table>
            <tr><th>{{ __('fp.serial') }}</th><th>IP</th><th>{{ __('fp.last_seen') }}</th><th></th></tr>
            @foreach ($unknown as $sn => $u)
                <tr>
                    <td class="num" dir="ltr">{{ $sn }}</td>
                    <td class="num" dir="ltr">{{ $u['ip'] ?? '—' }}</td>
                    <td class="num" dir="ltr">{{ $u['at'] ?? '—' }}</td>
                    <td><button class="btn sm green" type="button" onclick="document.getElementById('fpSerial').value='{{ e($sn) }}';document.getElementById('fpName').focus()">{{ __('fp.register_this') }}</button></td>
                </tr>
            @endforeach
        </table></div>
    </div>
@endif

<div class="card">
    <h3>🖐️ {{ __('fp.devices_title') }} <span class="side">{{ __('fp.devices_sub') }}</span></h3>

    <form method="POST" action="{{ route('hr.fingerprint.store') }}" class="searchbar" data-noprint>
        @csrf
        <label class="fl"><span>{{ __('fp.serial') }}</span><input type="text" name="serial" id="fpSerial" dir="ltr" required maxlength="40" value="{{ old('serial') }}"></label>
        <label class="fl"><span>{{ __('fp.device_name') }}</span><input type="text" name="name" id="fpName" required maxlength="80" value="{{ old('name') }}"></label>
        <label class="fl"><span>{{ __('fp.location') }}</span><input type="text" name="location" maxlength="120" value="{{ old('location') }}"></label>
        <label class="fl"><span>{{ __('fp.mode') }}</span>
            <select name="mode">
                @foreach (\App\Models\FpDevice::MODES as $m)<option value="{{ $m }}">{{ __('fp.mode_'.$m) }}</option>@endforeach
            </select></label>
        <button class="btn green" type="submit">➕ {{ __('fp.add_device') }}</button>
    </form>

    @forelse ($devices as $d)
        <div class="card" style="margin-top:10px" data-device="{{ $d->id }}">
            <h3>
                <span class="badge {{ $d->isOnline() ? 'b-green' : 'b-red' }}" data-online>{{ $d->isOnline() ? __('fp.online') : ($d->last_seen_at ? __('fp.offline') : __('fp.never_seen')) }}</span>
                {{ $d->name }} <span class="num muted" dir="ltr">{{ $d->serial }}</span>
                <span class="side">{{ __('fp.last_seen') }}: <span data-seen>{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</span>
                    · {{ __('fp.last_punch') }}: <span dir="ltr">{{ $d->last_punch_at?->format('Y-m-d H:i') ?? '—' }}</span>
                    · IP <span dir="ltr">{{ $d->ip ?? '—' }}</span>
                    · {{ __('fp.firmware') }}: <span dir="ltr">{{ $d->firmware ?? '—' }}</span>
                    · {{ __('fp.counts') }}: {{ $d->users_count ?? '—' }} / {{ $d->fp_count ?? '—' }} / {{ $d->logs_count ?? '—' }}</span>
            </h3>

            <form method="POST" action="{{ route('hr.fingerprint.update', $d) }}" class="searchbar" data-noprint>
                @csrf
                <label class="fl"><span>{{ __('fp.device_name') }}</span><input type="text" name="name" value="{{ $d->name }}" required maxlength="80"></label>
                <label class="fl"><span>{{ __('fp.location') }}</span><input type="text" name="location" value="{{ $d->location }}" maxlength="120"></label>
                <label class="fl"><span>{{ __('fp.mode') }}</span>
                    <select name="mode">
                        @foreach (\App\Models\FpDevice::MODES as $m)<option value="{{ $m }}" @selected($d->mode === $m)>{{ __('fp.mode_'.$m) }}</option>@endforeach
                    </select></label>
                <label class="fl" title="{{ __('fp.apply_from_hint') }}"><span>{{ __('fp.apply_from') }}</span>
                    <input type="date" name="apply_from" value="{{ $d->apply_from?->format('Y-m-d') }}"></label>
                <label class="fl"><span>{{ __('fp.active') }}</span><input type="checkbox" name="active" value="1" @checked($d->active)></label>
                <button class="btn" type="submit">💾 {{ __('common.save') }}</button>
            </form>

            <div class="searchbar" data-noprint>
                <strong>{{ __('fp.commands') }}:</strong>
                <form method="POST" action="{{ route('hr.fingerprint.command', $d) }}" style="display:flex;gap:6px;align-items:center">
                    @csrf<input type="hidden" name="action" value="resync">
                    <input type="date" name="from" value="{{ today()->subDays(7)->format('Y-m-d') }}" required>
                    <input type="date" name="to" value="{{ today()->format('Y-m-d') }}">
                    <button class="btn sm" type="submit">🔄 {{ __('fp.cmd_resync') }}</button>
                </form>
                @foreach (['users' => '👥', 'pull_users' => '⬇', 'info' => 'ℹ️'] as $act => $ic)
                    <form method="POST" action="{{ route('hr.fingerprint.command', $d) }}">
                        @csrf<input type="hidden" name="action" value="{{ $act }}">
                        <button class="btn sm" type="submit">{{ $ic }} {{ __('fp.cmd_'.$act) }}</button>
                    </form>
                @endforeach
            </div>
        </div>
    @empty
        <p class="muted">{{ __('fp.no_devices') }}</p>
    @endforelse
</div>

<div class="grid2">
    <div class="card">
        <h3>🔗 {{ __('fp.map_title') }} <span class="side">{{ __('fp.map_sub') }}</span></h3>
        <form method="POST" action="{{ route('hr.fingerprint.map') }}" class="searchbar" data-noprint id="mapForm">
            @csrf
            <label class="fl wide"><span>{{ __('hr.employee') }}</span>
                <select name="employee_id" required>
                    <option value=""></option>
                    @foreach ($empOptions as $o)<option value="{{ $o['id'] }}">{{ $o['label'] }}</option>@endforeach
                </select></label>
            <label class="fl"><span>{{ __('fp.pin') }}</span><input type="text" name="pin" id="mapPin" dir="ltr" maxlength="20"></label>
            <button class="btn green" type="submit">🔗 {{ __('fp.map') }}</button>
        </form>

        @if ($unmapped->isNotEmpty())
            <h4 style="margin:10px 0 4px">⚠️ {{ __('fp.unmapped_title') }}</h4>
            <p class="muted" style="font-size:12px">{{ __('fp.unmapped_hint') }}</p>
            <div class="tablewrap"><table>
                <tr><th data-nosum>{{ __('fp.pin') }}</th><th>{{ __('fp.name_on_device') }}</th><th>{{ __('fp.device') }}</th><th>{{ __('fp.punches_count') }}</th><th>{{ __('fp.last_punch') }}</th><th></th></tr>
                @foreach ($unmapped as $u)
                    <tr>
                        <td class="num" dir="ltr">{{ $u->pin }}</td>
                        <td>{{ $deviceNames[$u->fp_device_id.'|'.$u->pin]->name ?? '—' }}</td>
                        <td>{{ $devices->firstWhere('id', $u->fp_device_id)?->name }}</td>
                        <td class="num">{{ $u->n }}</td>
                        <td class="num" dir="ltr">{{ \Illuminate\Support\Carbon::parse($u->last_at)->format('Y-m-d H:i') }}</td>
                        <td><button class="btn sm" type="button" onclick="document.getElementById('mapPin').value='{{ e($u->pin) }}';document.querySelector('#mapForm select').focus()">🔗</button></td>
                    </tr>
                @endforeach
            </table></div>
        @endif

        <h4 style="margin:12px 0 4px">{{ __('fp.mapped_employees') }}</h4>
        <div class="tablewrap"><table>
            <tr><th>{{ __('common.code') }}</th><th style="text-align:start">{{ __('hr.employee') }}</th><th data-nosum>{{ __('fp.pin') }}</th></tr>
            @foreach ($employees->whereNotNull('fp_pin') as $e)
                <tr><td class="num">{{ $e->code }}</td><td style="text-align:start">{{ $e->displayName() }}</td><td class="num" dir="ltr">{{ $e->fp_pin }}</td></tr>
            @endforeach
        </table></div>
    </div>

    <div class="card">
        <h3>🛠️ {{ __('fp.setup_title') }}</h3>
        <ol style="line-height:1.9;padding-inline-start:20px">
            <li>{{ __('fp.setup_1') }}</li>
            <li>{{ __('fp.setup_2') }}</li>
            <li><strong>{{ __('fp.setup_3', ['host' => $host]) }}</strong><br><span class="muted">{{ __('fp.setup_3b') }}</span></li>
            <li><strong>{{ __('fp.setup_4') }}</strong></li>
            <li>{{ __('fp.setup_5') }}</li>
            <li>{{ __('fp.setup_6') }}</li>
            <li>{{ __('fp.setup_7') }}</li>
            <li>{{ __('fp.setup_8') }}</li>
        </ol>
        <p class="muted">{{ __('fp.setup_test', ['url' => $pingUrl]) }}</p>

        <h4 style="margin:12px 0 4px">🔔 {{ __('fp.settings') }}</h4>
        <form method="POST" action="{{ route('hr.fingerprint.settings') }}" data-noprint>
            @csrf
            <label><input type="checkbox" name="notify_all" value="1" @checked($notifyAll) onchange="this.form.submit()"> {{ __('fp.notify_all') }}</label>
            <p class="muted" style="font-size:12px">{{ __('fp.notify_hint') }}</p>
        </form>

        @if ($commands->isNotEmpty())
            <h4 style="margin:12px 0 4px">{{ __('fp.recent_commands') }}</h4>
            <div class="tablewrap"><table>
                @foreach ($commands as $c)
                    <tr>
                        <td class="num" dir="ltr" style="font-size:11px">{{ $c->created_at?->format('m-d H:i') }}</td>
                        <td>{{ $c->device?->name }}</td>
                        <td dir="ltr" style="font-size:11px;text-align:start">{{ \Illuminate\Support\Str::limit($c->command, 50) }}</td>
                        <td><span class="badge {{ $cmdBadge[$c->status] ?? 'b-gray' }}">{{ __('fp.cmd_status_'.$c->status) }}</span></td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>
</div>

<div class="card">
    <h3>⚡ {{ __('fp.live_title') }} <span class="side">{{ __('fp.live_sub') }}</span></h3>
    <div class="tablewrap"><table id="liveTable">
        <tr><th>{{ __('fp.time') }}</th><th>{{ __('fp.device') }}</th><th data-nosum>{{ __('fp.pin') }}</th><th style="text-align:start">{{ __('hr.employee') }}</th><th>{{ __('hrm.punch_type') }}</th><th>{{ __('fp.verify') }}</th><th>{{ __('fp.result') }}</th></tr>
        @foreach ($logs as $l)
            @php $r = app(\App\Http\Controllers\Hr\FingerprintController::class)->row($l); @endphp
            <tr data-id="{{ $r['id'] }}">
                <td class="num" dir="ltr" style="font-size:11px">{{ $r['time'] }}</td>
                <td>{{ $r['device'] }}</td>
                <td class="num" dir="ltr">{{ $r['pin'] }}</td>
                <td style="text-align:start">{{ $r['employee'] }}</td>
                <td>{{ $r['type'] }}</td>
                <td>{{ $r['verify'] }}</td>
                <td><span class="badge {{ $resultBadge[$r['result']] ?? 'b-gray' }}">{{ $r['result_label'] }}</span></td>
            </tr>
        @endforeach
    </table></div>
</div>

<script>
(function () {
    var table = document.getElementById('liveTable');
    var badges = @json($resultBadge);
    var lastId = {{ (int) ($logs->first()?->id ?? 0) }};
    function cell(text, cls, ltr) {
        var td = document.createElement('td');
        if (cls) td.className = cls;
        if (ltr) td.dir = 'ltr';
        td.textContent = text;
        return td;
    }
    function tick() {
        fetch(@json(route('hr.fingerprint.live')) + '?after=' + lastId, {headers: {'Accept': 'application/json'}})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j) return;
                j.logs.slice().reverse().forEach(function (l) {
                    var tr = document.createElement('tr');
                    tr.style.background = '#FFFDE0';
                    tr.appendChild(cell(l.time, 'num', true));
                    tr.appendChild(cell(l.device));
                    tr.appendChild(cell(l.pin, 'num', true));
                    var emp = cell(l.employee); emp.style.textAlign = 'start'; tr.appendChild(emp);
                    tr.appendChild(cell(l.type));
                    tr.appendChild(cell(l.verify));
                    var td = document.createElement('td'), b = document.createElement('span');
                    b.className = 'badge ' + (badges[l.result] || 'b-gray'); b.textContent = l.result_label;
                    td.appendChild(b); tr.appendChild(td);
                    table.rows[0].insertAdjacentElement('afterend', tr);
                    lastId = Math.max(lastId, l.id);
                });
                var online = 0;
                j.devices.forEach(function (d) {
                    var box = document.querySelector('[data-device="' + d.id + '"]');
                    if (d.online) online++;
                    if (!box) return;
                    var seen = box.querySelector('[data-seen]');
                    if (seen) seen.textContent = d.seen;
                });
                var k = document.getElementById('kpiOnline');
                if (k) k.textContent = online;
            })
            .catch(function () {});
    }
    setInterval(tick, 10000);
})();
</script>
@endsection
