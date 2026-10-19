@extends('layouts.system')

{{--
    كشف بنك واحد (L4-D6) — سطور حساب البنك في فترة الكشف + البنود المعلّقة
    من قبلها (مش متعلّم عليها). الفورم بيبعت **كل** المعلّم عليه، والسيرفر
    بيزامن علامات الكشف ده: اللي اتشال بيتمسح واللي اتزوّد بيتسجّل.
--}}

@section('title', __('cash.recon').' '.$s->number)

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
    $editable = $canPost && $s->status === 'open';
    $diff = (float) $summary['difference'];
@endphp

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
    <a class="btn sm" href="{{ route('gl.recon') }}">🧮 {{ __('cash.recon') }}</a>
    @if ($editable)
        <form method="POST" action="{{ route('gl.recon.close', $s) }}" style="display:inline"
              onsubmit="return confirm(@js(__('cash.recon_confirm_close')))">
            @csrf
            <button class="btn gold" type="submit" @disabled($diff != 0) title="{{ $diff != 0 ? __('cash.recon_not_balanced') : '' }}">🔒 {{ __('cash.recon_close') }}</button>
        </form>
    @endif
@endsection

@section('content')

@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $errors->first() }}</span></div>
@endif

<div class="kpis">
    <div class="kpi" style="grid-column:span 2">
        <div class="lbl">{{ __('cash.recon_book') }}</div>
        <div class="val" dir="ltr">{{ $fmt($summary['book']) }}</div>
        <div class="sub2">{{ __('cash.recon_book_sub', ['date' => $s->date_to?->format('Y-m-d')]) }}</div>
    </div>
    <div class="kpi" style="grid-column:span 2">
        <div class="lbl">{{ __('cash.recon_cleared') }}</div>
        <div class="val" dir="ltr">{{ $fmt($summary['cleared']) }}</div>
        <div class="sub2">{{ __('cash.recon_cleared_sub', ['opening' => $fmt($s->opening_balance)]) }}</div>
    </div>
    <div class="kpi" style="grid-column:span 2">
        <div class="lbl">{{ __('cash.recon_closing') }}</div>
        <div class="val" dir="ltr">{{ $fmt($summary['statement']) }}</div>
        <div class="sub2">{{ $s->safe?->displayName() }} · {{ $s->date_from?->format('Y-m-d') }} → {{ $s->date_to?->format('Y-m-d') }}</div>
    </div>
    <div class="kpi" style="grid-column:span 2">
        <div class="lbl">{{ __('cash.difference') }}</div>
        <div class="val {{ $diff != 0 ? 'neg' : 'pos' }}" dir="ltr">{{ $fmt($diff) }}</div>
        <div class="sub2">
            <span class="badge {{ $s->status === 'closed' ? 'b-green' : 'b-blue' }}">{{ __('cash.recon_status_'.$s->status) }}</span>
            @if ($s->status === 'closed')
                {{ $s->closer?->displayName() }} · {{ $s->closed_at?->format('Y-m-d H:i') }}
            @endif
        </div>
    </div>
</div>

<div class="card" id="recon-lines">
    <h3>🏦 {{ $s->number }} <span class="side">{{ __('cash.recon_lines_sub') }}</span></h3>
    @if ($s->note)
        <div style="font-size:11px;color:var(--muted);margin-bottom:8px">{{ $s->note }}</div>
    @endif

    <form method="POST" action="{{ route('gl.recon.mark', $s) }}">
        @csrf
        <div class="tablewrap">
            <table>
                <tr>
                    <th data-nosum>✔</th>
                    <th>{{ __('common.date') }}</th>
                    <th data-nosum>{{ __('cash.recon_entry') }}</th>
                    <th style="text-align:start">{{ __('cash.recon_memo') }}</th>
                    <th class="num">{{ __('cash.recon_debit') }}</th>
                    <th class="num">{{ __('cash.recon_credit') }}</th>
                    <th>{{ __('cash.recon_marked') }}</th>
                </tr>
                @forelse ($lines as $l)
                    @php
                        $mine = $l->statement_id === $s->id;
                        $other = $l->statement_id && ! $mine;
                        $carried = $l->entry_date->lt($s->date_from);
                    @endphp
                    <tr @if ($other) style="opacity:.6" @endif>
                        <td>
                            <input type="checkbox" name="line_keys[]" value="{{ $l->line_key }}" @checked($mine) @disabled(! $editable || $other)>
                        </td>
                        <td class="num" style="font-size:11px">{{ $l->entry_date->format('Y-m-d') }}</td>
                        <td><b>{{ $l->entry_number }}</b></td>
                        <td style="text-align:start;font-size:11px">{{ $l->memo ?: $l->entry_memo }}
                            @if ($carried)
                                <span class="badge b-gold">{{ __('cash.recon_carried') }}</span>
                            @endif
                        </td>
                        <td class="num">{{ (float) $l->debit ? $fmt($l->debit) : '' }}</td>
                        <td class="num">{{ (float) $l->credit ? $fmt($l->credit) : '' }}</td>
                        <td>
                            @if ($mine)
                                <span class="badge b-green">{{ __('cash.recon_cleared_badge') }}</span>
                            @elseif ($other)
                                <span class="badge b-blue">{{ $otherNumbers[$l->statement_id] ?? __('cash.recon_other') }}</span>
                            @else
                                <span class="badge b-red">{{ __('cash.recon_outstanding') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="color:var(--muted);font-size:12px">{{ __('cash.recon_no_lines') }}</td></tr>
                @endforelse
            </table>
        </div>
        @if ($editable)
            <div style="display:flex;justify-content:flex-end;margin-top:10px">
                <button class="btn gold" type="submit">{{ __('cash.recon_save_marks') }}</button>
            </div>
        @endif
    </form>
</div>

@endsection
