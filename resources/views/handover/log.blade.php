@extends('layouts.system')

@section('title', __('handover.log_title'))

@section('actions')
    <a class="btn" href="{{ route('handover.index') }}">← {{ __('handover.back') }}</a>
@endsection

@section('content')
<div class="card">
    <h3>📋 {{ __('handover.log_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $marks->total()]) }}</span>
    </h3>

    {{-- فلتر «من — إلى» على `marked_at` — وقت التعليم مش وقت الإدخال --}}
    <form class="searchbar" method="GET">
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
        <a class="btn" href="{{ route('handover.log') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('handover.col_marked_at') }}</th>
                <th>{{ __('handover.col_module') }}</th>
                <th>{{ __('handover.col_screen') }}</th>
                <th>{{ __('handover.col_user') }}</th>
                <th>{{ __('handover.note') }}</th>
                <th>{{ __('handover.col_state') }}</th>
                <th data-nosum>{{ __('handover.col_unmarked_at') }}</th>
            </tr>
            @forelse ($marks as $k)
                <tr>
                    <td class="num s">{{ $k->marked_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $k->screen?->module?->displayName() ?? '—' }}</td>
                    <td><b>{{ $k->screen?->displayTitle() ?? '—' }}</b></td>
                    <td>{{ $k->user?->displayName() ?? '—' }}</td>
                    <td>{{ $k->note ?: '—' }}</td>
                    <td>
                        @if ($k->unmarked_at)
                            <span class="badge b-gray">{{ __('handover.state_unmarked') }}</span>
                        @else
                            <span class="badge b-green">{{ __('handover.state_active') }}</span>
                        @endif
                    </td>
                    <td class="num s">
                        @if ($k->unmarked_at)
                            {{ $k->unmarked_at->format('Y-m-d H:i') }} · {{ $k->unmarker?->displayName() ?? '—' }}
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px">{{ __('handover.no_marks') }}</td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $marks])
</div>
@endsection
