{{--
    ═══ ورقة الرول: كل حاجة مسموحة/ممنوعة للرول، متجمّعة، بالوصف والخطورة ═══
    محسوبة من نفس الريزولفر فبتفضل مطابقة للحقيقة دايماً. للطباعة (@media print)
    والإكسيل من زرار «إكسيل» فوق (erp.perms.sheet.excel).
--}}
@php
    use App\Http\Controllers\PermissionController as PC;

    $info = $roles[$sheetRole];
@endphp

<div class="card" data-noprint>
    <form method="GET" action="{{ route('erp.perms') }}" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <input type="hidden" name="tab" value="sheet">
        <label class="fl wide"><span>📄 {{ __('perm.sheet_pick') }}</span>
            <select name="role" onchange="this.form.submit()">
                @foreach ($roles as $k => $r)
                    <option value="{{ $k }}" @selected($sheetRole === $k)>{{ $r['label'] }}@if($r['custom']) — {{ __('enums.role.'.$r['base']) }}@endif</option>
                @endforeach
            </select></label>
        <label class="pm-chk" style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:700;padding-bottom:9px">
            <input type="checkbox" id="pmSheetAllowed"> {{ __('perm.sheet_only_allowed') }}</label>
        <span class="pm-muted" style="padding-bottom:9px;flex:1 1 260px">{{ __('perm.sheet_hint') }}</span>
    </form>
</div>

<div class="card pm-sheet">
    <h3>📄 {{ __('perm.sheet_title') }} — {{ $info['label'] }}
        <span class="side">{{ __('common.date') }}: {{ now()->format('Y-m-d H:i') }}</span></h3>

    <div class="pm-sheet-head">
        <span>{{ __('perm.base') }}: <b>{{ __('enums.role.'.$info['base']) }}</b></span>
        <span>💻 {{ $info['web'] ? __('perm.web_on') : __('perm.web_off') }}</span>
        <span>📱 {{ $info['app'] ? __('perm.app_on') : ($info['custom'] ? __('perm.app_off') : __('perm.app_file')) }}</span>
        <span>{{ __('perm.c_users') }}: <b>{{ $info['active_users'] }}</b></span>
        <span class="pm-state on">✓ {{ __('perm.st_allowed') }}: {{ $sheetStats['allowed'] }}</span>
        <span class="pm-state off">✗ {{ __('perm.st_denied') }}: {{ $sheetStats['denied'] }}</span>
        @if ($sheetStats['critical'] > 0)
            <span class="pm-risk r-critical">{{ __('perm.st_critical') }}: {{ $sheetStats['critical'] }}</span>
        @endif
    </div>

    <div class="tablewrap">
        <table data-plain data-noxl class="pm-sheet-t">
            <thead><tr>
                <th>{{ __('perm.c_item') }}</th>
                <th style="width:96px">{{ __('perm.c_status') }}</th>
                <th style="width:88px">{{ __('perm.c_risk') }}</th>
                <th>{{ __('perm.c_what') }}</th>
                <th style="width:120px">{{ __('perm.c_source') }}</th>
            </tr></thead>
            <tbody>
            @foreach (PC::flatRows($tree) as $r)
                @if ($r['kind'] === 'group')
                    <tr class="pm-sg"><td colspan="5">{{ $r['icon'] }} <b>{{ $r['label'] }}</b>
                        <span class="pm-muted">— {{ $r['effective'] ? __('perm.group_visible') : __('perm.group_hidden') }}</span></td></tr>
                @else
                    @php [$title, $withWhat] = PC::rowTitle($r); $bare = ! $withWhat; @endphp
                    <tr data-sheet-key="{{ $r['key'] }}" data-allowed="{{ $r['effective'] ? '1' : '0' }}" class="d{{ $r['depth'] }} {{ $r['effective'] ? '' : 'no' }}">
                        <td>
                            <span style="padding-inline-start:{{ ($r['depth'] - 1) * 20 }}px;display:inline-block">
                                @if ($r['depth'] === 2)<span class="pm-muted">{{ $r['kind'] === 'action' ? '🔘' : '🖥' }}</span>@endif
                                <b>{{ $title }}</b>
                                <div class="pm-key">{{ $r['key'] }}</div>
                            </span>
                        </td>
                        <td>{!! $state($r['effective']) !!}</td>
                        <td>{!! $risk($r['risk']) !!}</td>
                        <td style="font-size:12px;line-height:1.6;white-space:normal">
                            @unless ($bare){{ $r['what'] }}@endunless
                            @if ($r['data'])<div class="pm-muted" style="font-size:11px">{{ $r['data'] }}</div>@endif
                            @if ($r['locked'])<div class="pm-muted" style="font-size:11px">🔒 {{ __('perm.locked_reason') }}</div>@endif
                        </td>
                        <td>{!! $src($r['source'], $r['via']) !!}</td>
                    </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<style>
.pm-sheet-head{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;font-size:12.5px;margin-bottom:12px}
.pm-sheet-t td{vertical-align:top}
.pm-sheet-t tr.pm-sg td{background:var(--card2);font-size:13px;padding-top:10px;padding-bottom:10px}
.pm-sheet-t tr.no td{color:var(--muted)}
.pm-sheet-t tr.no td b{font-weight:600}
.pm-sheet.only-allowed tr.no{display:none}
@media print{
  .pm-sheet{border:0;padding:0}
  .pm-sheet .tablewrap{overflow:visible}
  .pm-sheet-t{font-size:10.5px}
  .pm-sheet-t thead{display:table-header-group}
  .pm-sheet-t tr{break-inside:avoid}
  .pm-sheet-t tr.pm-sg td{background:#EEE!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .pm-risk,.pm-src{border:1px solid #CCC;-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
<script>
document.getElementById('pmSheetAllowed').addEventListener('change', function () {
    document.querySelector('.pm-sheet').classList.toggle('only-allowed', this.checked);
});
</script>
