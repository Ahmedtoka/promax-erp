@extends('layouts.system')

@section('title', __('handover.title').' — '.$module->displayName())

@php
    $u = auth()->user();
    // ⚠️ كل زرار متحرس براوته هو (promax-system §3) — الصف بيبعت mark أو unmark،
    // والهيدر mark_all أو unmark_all
    $canMark = \App\Support\Access::allows($u, 'handover.index.mark') && \App\Support\Access::allows($u, 'handover.index.unmark');
    $canMarkAll = \App\Support\Access::allows($u, 'handover.index.mark_all') && \App\Support\Access::allows($u, 'handover.index.unmark_all');
    $allMarked = $progress['total'] > 0 && $progress['marked'] === $progress['total'];
@endphp

@section('actions')
    <a class="btn" href="{{ route('handover.index') }}">← {{ __('handover.back') }}</a>
    @if (\App\Support\Access::allows($u, 'handover.index.log'))
        <a class="btn" href="{{ route('handover.index.log') }}">📋 {{ __('handover.log') }}</a>
    @endif
@endsection

@section('content')
<style>
.ho-bar{height:8px;border-radius:99px;background:var(--card2);overflow:hidden;margin:4px 0 0}
.ho-bar > span{display:block;height:100%;background:linear-gradient(135deg, #12399B 0%, #602D90 100%)}
.ho-chk{width:18px;height:18px;cursor:pointer;accent-color:var(--royal-blue)}
.ho-note{min-width:160px}
</style>

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ $module->displayName() }}</div>
        <div class="val num">{{ $progress['pct'] }}%</div>
        <div class="sub2">{{ __('handover.marked_of', ['marked' => $progress['marked'], 'total' => $progress['total']]) }}</div>
        <div class="ho-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['pct'] }}"
             aria-label="{{ $module->displayName() }}"><span style="width:{{ $progress['pct'] }}%"></span></div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('handover.test_status') }} · {{ __('handover.live_status') }}</div>
        <div class="val" style="font-size:16px">{{ __('handover.test_'.$module->test_status) }} · {{ __('handover.live_'.$module->live_status) }}</div>
        <div class="sub2">{{ __('handover.deadline') }}:
            <span class="num">{{ $module->deadline?->format('Y-m-d') ?? __('handover.no_deadline') }}</span></div>
    </div>
</div>

@if ($module->notes)
    <div class="card"><h3>{{ __('handover.notes') }}</h3><div style="white-space:pre-line">{{ $module->notes }}</div></div>
@endif

<div class="card">
    <h3>{{ __('handover.screen') }} <span class="side">{{ __('handover.screens_n', ['n' => $progress['total']]) }}</span></h3>

    {{-- فورمز الهيدر بره الجدول — مايتداخلوش مع فورم كل صف --}}
    @if ($canMarkAll && $progress['total'] > 0)
        <form id="hoAll" method="POST"
              action="{{ $allMarked ? route('handover.index.unmark_all', $module) : route('handover.index.mark_all', $module) }}">
            @csrf
        </form>
    @endif

    <div class="tablewrap">
        <table>
            <tr>
                <th class="act" data-noexport style="width:36px">
                    @if ($canMarkAll && $progress['total'] > 0)
                        <input type="checkbox" class="ho-chk" form="hoAll" @checked($allMarked)
                               title="{{ $allMarked ? __('handover.unmark_all') : __('handover.mark_all') }}"
                               aria-label="{{ $allMarked ? __('handover.unmark_all') : __('handover.mark_all') }}"
                               onchange="if ({{ $allMarked ? 'true' : 'false' }} && !confirm({{ \Illuminate\Support\Js::from(__('handover.confirm_unmark_all')) }})) { this.checked = true; return; } this.form.submit()">
                    @endif
                </th>
                <th>{{ __('handover.col_screen') }}</th>
                <th>{{ __('handover.col_user') }}</th>
                <th data-nosum>{{ __('handover.col_marked_at') }}</th>
                <th>{{ __('handover.note') }}</th>
            </tr>
            @forelse ($module->screens as $s)
                @php $mark = $s->activeMark(); @endphp
                <tr>
                    <td class="act" data-noexport>
                        @if ($canMark)
                            <form id="hoS{{ $s->id }}" method="POST"
                                  action="{{ $mark ? route('handover.index.unmark', $s) : route('handover.index.mark', $s) }}">
                                @csrf
                                <input type="checkbox" class="ho-chk" @checked($mark) onchange="this.form.submit()"
                                       aria-label="{{ $mark ? __('handover.unmark') : __('handover.mark') }}: {{ $s->displayTitle() }}">
                            </form>
                        @else
                            {{ $mark ? '✓' : '—' }}
                        @endif
                    </td>
                    <td>
                        <b>{{ $s->displayTitle() }}</b>
                        @if ($s->isStale())
                            <span class="badge b-orange" title="{{ __('handover.stale_hint') }}">⚠ {{ __('handover.stale') }}</span>
                        @endif
                    </td>
                    <td>{{ $mark ? __('handover.marked_by', ['name' => $mark->user?->displayName() ?? '—']) : __('handover.not_marked') }}</td>
                    <td class="num s">{{ $mark?->marked_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td>
                        @if ($mark)
                            {{ $mark->note ?: '—' }}
                        @elseif ($canMark)
                            <input class="ho-note" type="text" name="note" maxlength="500" form="hoS{{ $s->id }}"
                                   placeholder="{{ __('handover.note_ph') }}" aria-label="{{ __('handover.note') }}">
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:28px">{{ __('handover.no_screens') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($canMeta)
    <div class="card">
        <h3>⚙️ {{ __('handover.meta_title') }} <span class="side">{{ __('handover.meta_hint') }}</span></h3>
        <form method="POST" action="{{ route('handover.index.meta', $module) }}">
            @csrf
            <div class="frow">
                <div><label class="f">{{ __('handover.name_ar') }}</label>
                    <input type="text" name="name_ar" maxlength="150" required value="{{ old('name_ar', $module->name_ar) }}"></div>
                <div><label class="f">{{ __('handover.name_en') }}</label>
                    <input type="text" name="name_en" maxlength="150" required value="{{ old('name_en', $module->name_en) }}"></div>
                <div><label class="f">{{ __('handover.test_status') }}</label>
                    <select name="test_status">
                        @foreach (\App\Models\HandoverModule::TEST_STATUSES as $st)
                            <option value="{{ $st }}" @selected(old('test_status', $module->test_status) === $st)>{{ __('handover.test_'.$st) }}</option>
                        @endforeach
                    </select></div>
                <div><label class="f">{{ __('handover.live_status') }}</label>
                    <select name="live_status">
                        @foreach (\App\Models\HandoverModule::LIVE_STATUSES as $st)
                            <option value="{{ $st }}" @selected(old('live_status', $module->live_status) === $st)>{{ __('handover.live_'.$st) }}</option>
                        @endforeach
                    </select></div>
                <div><label class="f">{{ __('handover.deadline') }}</label>
                    <input type="date" name="deadline" value="{{ old('deadline', $module->deadline?->toDateString()) }}"></div>
            </div>
            <div class="frow">
                <div><label class="f">{{ __('handover.notes') }}</label>
                    <textarea name="notes" rows="3" maxlength="2000">{{ old('notes', $module->notes) }}</textarea></div>
            </div>
            <div style="display:flex;justify-content:flex-end">
                <button class="btn" type="submit">{{ __('handover.save') }}</button>
            </div>
        </form>
    </div>
@endif
@endsection
