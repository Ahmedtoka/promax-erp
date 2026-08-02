@extends('layouts.system')

@section('title', __('journey.assignments'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $myZoneIds = $rep?->zones->pluck('id')->all() ?? [];
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.journeys') }}">🗺️ {{ __('journey.page') }}</a>
    <a class="btn" href="{{ route('ops.live') }}">📡 {{ __('journey.live') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>👥 {{ __('journey.assignments') }} <span class="side">{{ __('journey.assignments_sub') }}</span></h3>

    @if ($orphanTotal > 0)
        <div class="alert warn">
            {{ __('journey.orphans_hint') }} — <b>{{ $fmt($orphanTotal) }}</b>
        </div>
    @else
        <div class="alert good">{{ __('journey.no_orphans') }}</div>
    @endif

    @if ($rep === null)
        <div class="alert warn">{{ __('journey.no_reps') }}</div>
    @else
        <form method="GET" action="{{ route('ops.assignments') }}" class="searchbar">
            <div>
                <label class="f">{{ __('journey.rep') }}</label>
                <select name="rep" onchange="this.form.submit()">
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected($rep->id === $r->id)>
                            {{ $r->displayName() }} — {{ $r->roleLabel() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('client.zone') }}</label>
                <select name="zone" onchange="this.form.submit()">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($zones as $z)
                        <option value="{{ $z->id }}" @selected(($filters['zone'] ?? '') == $z->id)>
                            {{ $z->displayName() }} ({{ $z->clients_count }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('common.search') }}</label>
                {{-- ⚠️ سيرفر-سايد مش متصفح — قايمة اليتامى مقصوصة على
                     300 صف، فالفلترة في المتصفح بتدوّر في المقصوص بس
                     والعميل اللي بعد الحد مش هيظهر أبداً. --}}
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}"
                       placeholder="{{ __('common.search') }}…">
            </div>
        </form>
    @endif
</div>

@if ($rep !== null)
<div class="grid2">

    {{-- ═══════════ عملاء المندوب ═══════════ --}}
    <div class="card">
        <h3>✅ {{ __('journey.my_clients') }} <span class="side">{{ $mine->count() }}</span></h3>

        <div class="tablewrap" style="max-height:420px;overflow-y:auto">
            <table>
                <tr>
                    <th>{{ __('common.name') }}</th>
                    <th>{{ __('client.zone') }}</th>
                    <th></th>
                </tr>
                @forelse ($mine as $c)
                    <tr>
                        <td><a href="{{ route('erp.clients.show', $c) }}"><b>{{ $c->displayName() }}</b></a></td>
                        <td class="s">{{ $c->zone?->displayName() ?: '—' }}</td>
                        <td class="num">
                            <form method="POST" action="{{ route('ops.assignments.unassign', $c) }}">
                                @csrf @method('DELETE')
                                <button class="btn sm">{{ __('journey.unassign') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:24px">
                        {{ __('journey.no_clients') }}
                    </td></tr>
                @endforelse
            </table>
        </div>
    </div>

    {{-- ═══════════ مناطق المندوب ═══════════ --}}
    <div class="card">
        <h3>📍 {{ __('journey.my_zones') }}</h3>

        <form method="POST" action="{{ route('ops.assignments.assign') }}">
            @csrf
            <input type="hidden" name="user_id" value="{{ $rep->id }}">
            {{-- ⚠️ العلم ده بيقول للكنترولر «الفورم ده بيحفظ المناطق».
                 المتصفح مابيبعتش `zone_ids[]` خالص لو كلها متشالة،
                 فمن غير العلم مسح كل المناطق مابيتنفذش. --}}
            <input type="hidden" name="zones_form" value="1">

            <div style="max-height:330px;overflow-y:auto;border:1px solid var(--border);border-radius:10px;padding:9px">
                @foreach ($zones as $z)
                    <label style="display:flex;align-items:center;gap:8px;padding:5px 3px;font-size:12.5px">
                        <input type="checkbox" name="zone_ids[]" value="{{ $z->id }}"
                               @checked(in_array($z->id, $myZoneIds, true))>
                        <span>{{ $z->displayName() }}</span>
                        <span class="s" style="margin-inline-start:auto;color:var(--muted)">
                            {{ $fmt($z->clients_count) }}
                        </span>
                    </label>
                @endforeach
            </div>

            <button class="btn gold" style="width:100%;margin-top:10px">{{ __('common.save') }}</button>
        </form>
    </div>
</div>

{{-- ═══════════ عملاء من غير مندوب ═══════════ --}}
<div class="card">
    <h3>⚠️ {{ __('journey.orphans') }} <span class="side">{{ $orphans->count() }} / {{ $fmt($orphanTotal) }}</span></h3>

    @if ($orphans->isEmpty())
        <div class="alert good">{{ __('journey.no_orphans') }}</div>
    @else
        <form method="POST" action="{{ route('ops.assignments.assign') }}">
            @csrf
            <input type="hidden" name="user_id" value="{{ $rep->id }}">

            <div style="margin-bottom:9px">
                <label style="font-size:12.5px;display:inline-flex;align-items:center;gap:7px">
                    <input type="checkbox" onchange="toggleAll(this)"> {{ __('journey.select_all') }}
                </label>
            </div>

            <div class="tablewrap" style="max-height:420px;overflow-y:auto">
                <table>
                    <tr>
                        <th style="width:34px"></th>
                        <th>{{ __('common.name') }}</th>
                        <th>{{ __('client.zone') }}</th>
                        <th>{{ __('common.phone') }}</th>
                        <th class="num">{{ __('client.balance') }}</th>
                    </tr>
                    @foreach ($orphans as $c)
                        <tr>
                            <td><input type="checkbox" class="pick" name="client_ids[]" value="{{ $c->id }}"></td>
                            <td><a href="{{ route('erp.clients.show', $c) }}">{{ $c->displayName() }}</a></td>
                            <td class="s">{{ $c->zone?->displayName() ?: '—' }}</td>
                            <td class="num s">{{ $c->phone ?: '—' }}</td>
                            <td class="num">{{ number_format((float) $c->balance, 2) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>

            <button class="btn gold" style="margin-top:11px">
                {{ __('journey.assign_selected') }} → {{ $rep->displayName() }}
            </button>
        </form>
    @endif
</div>
@endif

@endsection

@section('scripts')
<script>
    function toggleAll(box) {
        document.querySelectorAll('.pick').forEach(el => { el.checked = box.checked; });
    }
</script>
@endsection
