@extends('layouts.system')

@section('title', __('audit.page'))

@php
    use App\Models\ActivityLog;
    $fmt = fn ($n) => number_format((float) $n);

    // لون الحدث — التعديل والمسح لازم يبانوا وسط ضوضاء الزيارات
    $tone = fn ($e) => match ($e) {
        'created' => 'b-green',
        'updated' => 'b-blue',
        'deleted' => 'b-red',
        'login' => 'b-purple',
        'logout' => 'b-gray',
        'viewed' => 'b-gray',
        default => 'b-orange',
    };
@endphp

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">📊 {{ __('audit.today') }}</div><div class="val">{{ $fmt($kpi['today']) }}</div></div>
    <div class="kpi"><div class="lbl">🧑‍💼 {{ __('audit.users_today') }}</div><div class="val">{{ $fmt($kpi['users_today']) }}</div></div>
    <div class="kpi"><div class="lbl">✏️ {{ __('audit.edits_today') }}</div>
        <div class="val" style="color:var(--primary)">{{ $fmt($kpi['edits_today']) }}</div></div>
    <div class="kpi"><div class="lbl">🔑 {{ __('audit.logins_today') }}</div><div class="val">{{ $fmt($kpi['logins_today']) }}</div></div>
    <div class="kpi"><div class="lbl">🧾 {{ __('audit.total') }}</div><div class="val">{{ $fmt($kpi['total']) }}</div>
        <div class="sub2">{{ __('audit.retention_hint') }}</div></div>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="🔍 {{ __('common.search') }}" style="flex:1;min-width:200px">
        <select name="user" style="min-width:150px">
            <option value="">{{ __('audit.all_users') }}</option>
            @foreach ($users as $u)
                <option value="{{ $u->id }}" @selected((int) ($filters['user'] ?? 0) === $u->id)>
                    {{ $u->displayName() }}
                </option>
            @endforeach
        </select>
        <select name="event" style="min-width:120px">
            <option value="">{{ __('audit.all_events') }}</option>
            @foreach (ActivityLog::EVENTS as $e)
                <option value="{{ $e }}" @selected(($filters['event'] ?? '') === $e)>{{ __('audit.event_'.$e) }}</option>
            @endforeach
        </select>
        <select name="model" style="min-width:130px">
            <option value="">{{ __('audit.all_models') }}</option>
            @foreach ($models as $m)
                <option value="{{ $m }}" @selected(($filters['model'] ?? '') === $m)>
                    {{ __('audit.model_'.$m) === 'audit.model_'.$m ? $m : __('audit.model_'.$m) }}
                </option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" title="{{ __('audit.from') }}">
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" title="{{ __('audit.to') }}">
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.audit') }}">{{ __('common.clear') }}</a>
        <span class="badge b-gray">{{ $fmt($rows->total()) }}</span>
    </form>

    <div class="tablewrap" style="max-height:68vh;overflow-y:auto">
        <table>
            <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
            <tr>
                <th>{{ __('audit.when') }}</th><th>{{ __('audit.who') }}</th><th>{{ __('audit.what') }}</th>
                <th>{{ __('audit.on') }}</th><th>{{ __('audit.details') }}</th><th>{{ __('audit.from_ip') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td style="white-space:nowrap;font-size:11.5px">
                        {{ $r->created_at?->format('Y-m-d') }}
                        <br><span style="color:var(--muted)">{{ $r->created_at?->format('H:i:s') }}</span>
                    </td>
                    <td>
                        <b>{{ $r->user?->displayName() ?? $r->user_name ?? '—' }}</b>
                        @if ($r->role)<br><span style="font-size:10px;color:var(--muted)">{{ __('enums.role.'.$r->role) }}</span>@endif
                    </td>
                    <td><span class="badge {{ $tone($r->event) }}">{{ __('audit.event_'.$r->event) }}</span></td>
                    <td style="font-size:11.5px">
                        @if ($r->subject_type)
                            <b>{{ __('audit.model_'.$r->subject_type) === 'audit.model_'.$r->subject_type ? $r->subject_type : __('audit.model_'.$r->subject_type) }}</b>
                            <span style="color:var(--muted)">{{ $r->title }}</span>
                        @else
                            <span style="color:var(--muted)">{{ $r->title ?: $r->url }}</span>
                        @endif
                    </td>
                    <td style="font-size:11px;white-space:normal;max-width:380px">
                        @if ($r->changedCount() > 0)
                            {{-- الحقول اللي اتغيرت: قبل ← بعد. أول 4 بس والباقي عدد --}}
                            @foreach (array_slice($r->changes, 0, 4, true) as $field => $pair)
                                <div>
                                    <span style="color:var(--muted)">{{ $field }}:</span>
                                    @if (is_array($pair))
                                        <s style="color:var(--muted)">{{ $pair[0] === null || $pair[0] === '' ? '—' : $pair[0] }}</s>
                                        → <b>{{ $pair[1] === null || $pair[1] === '' ? '—' : $pair[1] }}</b>
                                    @else
                                        <b>{{ $pair === null || $pair === '' ? '—' : $pair }}</b>
                                    @endif
                                </div>
                            @endforeach
                            @if ($r->changedCount() > 4)
                                <span style="color:var(--muted)">+{{ $r->changedCount() - 4 }}</span>
                            @endif
                        @else
                            <span style="color:var(--muted)">{{ $r->url ?: __('audit.nothing') }}</span>
                        @endif
                    </td>
                    <td style="font-size:10.5px;color:var(--muted);white-space:nowrap">{{ $r->ip }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:26px">{{ __('audit.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

@endsection
