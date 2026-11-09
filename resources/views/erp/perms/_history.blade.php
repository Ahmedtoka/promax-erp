{{-- ═══ السجل: حفظ المصفوفة (perms.saved) + إنشاء/تعديل/نسخ/مسح الرولز — الأحدث الأول ═══ --}}
@php
    use App\Http\Controllers\PermissionController as PC;

    $evTone = ['perms.saved' => 'b-blue', 'created' => 'b-green', 'updated' => 'b-purple', 'deleted' => 'b-red', 'roles.cloned' => 'b-gold'];
    $subjTone = ['Role' => 'b-blue', 'BuiltinRole' => 'b-gray', 'User' => 'b-orange'];
    $show = fn ($v) => $v === null || $v === '' ? '—' : (is_bool($v) ? ($v ? '1' : '0') : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v));
@endphp

<div class="card">
    <h3>🕓 {{ __('perm.hist_title') }}
        <span class="side">{{ __('perm.hist_hint') }}</span></h3>

    <div class="rng-q" style="margin-bottom:12px;gap:6px">
        <a href="{{ $tabUrl('history') }}" @class(['on' => $histKind === null])>{{ __('perm.hist_all') }}</a>
        <a href="{{ $tabUrl('history', ['kind' => 'perms']) }}" @class(['on' => $histKind === 'perms'])>🧩 {{ __('perm.hist_perms') }}</a>
        <a href="{{ $tabUrl('history', ['kind' => 'roles']) }}" @class(['on' => $histKind === 'roles'])>👥 {{ __('perm.hist_roles') }}</a>
    </div>

    <div class="tablewrap">
        <table data-plain>
            <thead><tr>
                <th style="width:120px">{{ __('perm.hist_when') }}</th>
                <th>{{ __('perm.hist_who') }}</th>
                <th>{{ __('perm.hist_event') }}</th>
                <th>{{ __('perm.hist_subject') }}</th>
                <th>{{ __('perm.hist_changes') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($histRows as $h)
                @php
                    $changes = is_array($h->changes) ? $h->changes : [];
                    $isDiff = $h->event === 'perms.saved';
                    $crit = $isDiff ? count(array_filter($changes, fn ($c) => ($c['risk'] ?? null) === 'critical')) : 0;
                @endphp
                <tr>
                    <td style="white-space:nowrap;font-size:11.5px">{{ $h->created_at?->format('Y-m-d') }}
                        <br><span class="pm-muted">{{ $h->created_at?->format('h:i A') }}</span></td>
                    <td><b>{{ $h->user_name ?? '—' }}</b></td>
                    <td><span class="badge {{ $evTone[$h->event] ?? 'b-gray' }}">{{ __('perm.hist_e_'.str_replace('.', '_', $h->event)) }}</span></td>
                    <td>
                        <span class="badge {{ $subjTone[$h->subject_type] ?? 'b-gray' }}">{{ __('perm.hist_s_'.$h->subject_type) }}</span>
                        <b>{{ PC::historySubject($h) }}</b>
                    </td>
                    <td style="white-space:normal;max-width:520px">
                        @if ($changes === [])
                            <span class="pm-muted">—</span>
                        @else
                            <details>
                                <summary style="cursor:pointer">
                                    {{ $isDiff ? __('perm.changes_n', ['n' => count($changes)]) : __('perm.fields_n', ['n' => count($changes)]) }}
                                    @if ($crit)<span class="pm-risk r-critical">{{ __('permref.risk_critical') }} × {{ $crit }}</span>@endif
                                </summary>
                                <div style="margin-top:6px;display:grid;gap:4px;font-size:11.5px">
                                    @if ($isDiff)
                                        @foreach ($changes as $c)
                                            <div @if(($c['risk'] ?? '') === 'critical') style="background:#FDECEC;border-radius:6px;padding:2px 6px" @endif>
                                                {!! $risk($c['risk'] ?? 'medium') !!}
                                                @if (! empty($c['implied']))<span class="badge b-gray">⚡ {{ __('perm.hist_implied') }}</span>@endif
                                                <b>{{ $c['label'] ?? $c['key'] }}</b> <span class="pm-key">{{ $c['key'] ?? '' }}</span>:
                                                <s class="pm-muted">{{ $ov($c['before'] ?? null) }}</s> → <b>{{ $ov($c['after'] ?? null) }}</b>
                                                <span class="pm-muted">({{ ($c['effective_before'] ?? false) ? '✓' : '✗' }} → {{ ($c['effective_after'] ?? false) ? '✓' : '✗' }})</span>
                                            </div>
                                        @endforeach
                                    @else
                                        @foreach ($changes as $field => $pair)
                                            <div><span class="pm-key">{{ $field }}</span>:
                                                @if (is_array($pair) && array_is_list($pair) && count($pair) === 2)
                                                    <s class="pm-muted">{{ $show($pair[0]) }}</s> → <b>{{ $show($pair[1]) }}</b>
                                                @else
                                                    <b>{{ $show($pair) }}</b>
                                                @endif
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:26px">{{ __('perm.hist_empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('partials._pagination', ['p' => $histRows])
</div>
