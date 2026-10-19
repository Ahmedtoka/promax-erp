{{-- ٤ — الخزن والبنوك: كل خزنة على حساب في الشجرة + عهد المناديب --}}
<div class="tablewrap">
    <table>
        <tr>
            <th data-nosum style="text-align:start">{{ __('golive.s4_safe') }}</th>
            <th data-nosum>{{ __('golive.s4_kind') }}</th>
            <th data-nosum>{{ __('golive.s4_center') }}</th>
            <th data-nosum>{{ __('golive.s4_account') }}</th>
            @if ($canAdmin)<th data-nosum>{{ __('golive.s4_link') }}</th>@endif
        </tr>
        @forelse ($safes as $safe)
            @php $acc = $safe->glAccount; @endphp
            <tr>
                <td style="text-align:start"><b class="num">{{ $safe->code }}</b> — {{ $safe->displayName() }}
                    @unless ($safe->active)<span class="badge b-gray">{{ __('golive.inactive') }}</span>@endunless</td>
                <td>{{ __('golive.safe_kind_'.$safe->kind) }}</td>
                <td>{{ $safe->costCenter?->code ?? '—' }}</td>
                <td>@if ($acc)<span class="num">{{ $acc->code }}</span> · {{ $acc->displayName() }}@else<span class="badge b-red">{{ __('golive.s4_none') }}</span>@endif</td>
                @if ($canAdmin)
                    <td>
                        <form method="POST" action="{{ route('gl.golive.safes.link', $safe) }}" style="display:flex;gap:6px">
                            @csrf
                            <select name="gl_account_id" required>
                                @foreach ($accounts as $a)
                                    <option value="{{ $a->id }}" @selected($a->id === $safe->gl_account_id)>{{ $a->code }} · {{ $a->displayName() }}</option>
                                @endforeach
                            </select>
                            <button class="btn sm gold" type="submit">{{ __('common.save') }}</button>
                        </form>
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $canAdmin ? 5 : 4 }}" style="color:var(--muted)">{{ __('golive.s4_empty') }}</td></tr>
        @endforelse
    </table>
</div>

<div class="alert {{ ($repCash['ok'] ?? false) ? 'good' : 'warn' }}" style="margin-top:10px">
    <span>👤</span>
    <span>{{ __('golive.s4_rep_line', ['n' => $repAccounts, 'code' => $repCash['account']?->code ?? '—']) }}
        @unless ($repCash['ok'] ?? false) — {{ $repCash['why'] ?? '' }} · <a href="{{ route('gl.mapping') }}">{{ __('golive.s5_open_mapping') }}</a>@endunless</span>
</div>
