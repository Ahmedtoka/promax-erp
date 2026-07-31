@extends('layouts.system')

@section('title', __('client.channels'))

@php
    use App\Models\Channel;
    $fmt = fn ($n) => number_format((float) $n);
    $manager = auth()->user()->isManager();
    $admin = auth()->user()->isAdmin();
@endphp

@section('content')

{{-- ⚠️ الشاشة اسمها «القنوات الأربعة» وكانت بتعرض قناة واحدة من غير
     ما تقول إن فيه حاجة ناقصة. الصفحة اللي بتعرض رقم مخالف لعنوانها
     وساكتة هي أسوأ من صفحة بتقول «فيه غلط». --}}
@php $missingChannels = count(Channel::DEFAULTS) - $channels->count(); @endphp
@if ($missingChannels > 0)
    <div class="alert warn" style="margin-bottom:14px">
        <span>⚠️</span>
        <span>
            {{ __('client.channels_missing', ['count' => $missingChannels]) }}
            <code style="font-family:monospace;direction:ltr;display:inline-block">php artisan promax:channels</code>
        </span>
    </div>
@endif

<div class="card">
    <h3>🎯 {{ __('client.four_channels') }} <span class="side">{{ __('client.channel_pricing_note') }}</span></h3>
    {{-- ═════ الجدول: عملاء · بضاعة · مبيعات ═════ --}}
    {{-- ⚠️ **مفيش عمود خصم.** القناة بُعد تجميع مش مصدر تسعير —
         النسبة بتتحدد لكل عميل من عقده أو خصمه الخاص. عمود «الخصم»
         هنا كان بيدّي انطباع إن الرقم بيتظبط من الشاشة دي، وعميل
         جديد كان بياخده أوتوماتيك من غير ما حد يتفاوض عليه. --}}
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.channel') }}</th>
                <th>{{ __('client.client_count') }}</th>
                <th>{{ __('channel.goods_at_clients') }}</th>
                <th>{{ __('channel.goods_in_vans') }}</th>
                <th>{{ __('channel.units_sold') }}</th>
                <th>{{ __('client.purchases') }}</th>
                <th>{{ __('client.collected') }}</th>
                <th>{{ __('client.balance') }}</th>
                <th>{{ __('report.sales_today') }}</th>
                <th>{{ __('channel.discount_spread') }}</th>
                @if ($manager)<th></th>@endif
            </tr>
            @foreach ($channels as $ch)
                @php
                    $s = $stats[$ch->id];
                    $sp = $spread[$ch->id] ?? null;
                    // ملحوظة: ممنوع نستخدم دايركتيف json بمصفوفة جوه الـ Blade،
                    // بيكسّر الـ parser. بنجهّز الـ JSON هنا وبنطبعه خام.
                    $chJson = json_encode([
                        'id' => $ch->id,
                        'name' => $ch->name,
                        'name_en' => $ch->name_en,
                        'active' => (bool) $ch->active,
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                @endphp
                <tr>
                    <td>
                        <span class="badge {{ $ch->badgeClass() }}">{{ $ch->displayName() }}</span>
                        @if (! $ch->active)<span class="badge b-gray">{{ __('client.suspended') }}</span>@endif
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $ch->code }}</span>
                        @if ($s['team'])
                            <br><span style="font-size:10px;color:var(--muted)">{{ __('client.rep_count') }}: {{ $s['team'] }}</span>
                        @endif
                    </td>

                    <td class="num">
                        <b style="font-size:15px">{{ $s['active_clients'] }}</b>
                        @if ($s['clients'] > $s['active_clients'])
                            <br><span style="font-size:10px;color:var(--muted)">{{ __('client.out_of_clients', ['count' => $s['clients']]) }}</span>
                        @endif
                    </td>

                    {{-- ⚠️ بضاعة أمانة **مش مديونية** — لسه ملك بروماكس
                         لحد ما تتباع من الرف. عمود لوحده عشان محدش
                         يجمعها على الرصيد. --}}
                    <td class="num">
                        @if ($s['consignment'] > 0)
                            <b>{{ $fmt($s['consignment']) }}</b>
                            <br><span style="font-size:10px;color:var(--muted)">{{ __('channel.not_a_debt') }}</span>
                        @else — @endif
                    </td>

                    <td class="num">
                        @if ($s['in_vans'] > 0)
                            <b>{{ $fmt($s['in_vans']) }}</b>
                            <br><span style="font-size:10px;color:var(--muted)">{{ number_format($s['in_vans_units']) }} {{ __('channel.piece') }}</span>
                        @else — @endif
                    </td>

                    <td class="num">{{ $s['units'] ? number_format($s['units']) : '—' }}</td>
                    <td class="num">{{ $fmt($s['purchases']) }}</td>
                    <td class="num pos">
                        {{ $fmt($s['collections']) }}
                        @if ($s['purchases'] > 0)
                            <br><span style="font-size:10px;color:var(--muted)">{{ number_format($s['collections'] / $s['purchases'] * 100, 1) }}%</span>
                        @endif
                    </td>
                    <td class="num {{ $s['balance'] > 0 ? 'neg' : 'pos' }}">
                        {{ $fmt($s['balance']) }}
                        @if ($s['owing'])
                            <br><span style="font-size:10px;color:var(--muted)">{{ __('client.client_countable', ['count' => $s['owing']]) }}</span>
                        @endif
                    </td>
                    <td class="num pos">{{ $s['today'] ? $fmt($s['today']) : '—' }}</td>

                    {{-- ⚠️ **للقراءة بس.** المدى بيقول للمدير فيه تشتت
                         ولا لأ: قناة كلها 50% غير قناة من 10% لـ 60% —
                         التانية معناها إن فيه عميل واخد شروط محدش راجعها. --}}
                    <td class="num">
                        @if ($sp === null)
                            —
                        @elseif (abs($sp['max'] - $sp['min']) < 0.0001)
                            <b>{{ number_format($sp['avg'] * 100, 1) }}%</b>
                        @else
                            <b>{{ number_format($sp['min'] * 100, 1) }}%</b>
                            <span style="color:var(--muted)">–</span>
                            <b>{{ number_format($sp['max'] * 100, 1) }}%</b>
                            <br><span style="font-size:10px;color:var(--muted)">
                                {{ __('channel.average') }} {{ number_format($sp['avg'] * 100, 1) }}%
                            </span>
                        @endif
                    </td>

                    @if ($manager)
                        <td>
                            <button class="btn sm" onclick='editChannel({!! $chJson !!})'>{{ __('common.edit') }}</button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </table>
    </div>

    <div class="alerts" style="margin-top:14px">
        <div class="alert info">
            <div>💡 <b>{{ __('channel.no_rate_title') }}:</b> {{ __('channel.no_rate_note') }}</div>
        </div>
        @if ($orphans > 0)
            {{-- ⚠️ العميل من غير قناة مش داخل في أي رقم فوق. من غير
                 السطر ده، إجمالي الشاشة بيقل عن إجمالي السيستم ومحدش
                 يعرف الفرق راح فين. --}}
            <div class="alert warn">
                <div>⚠️ {{ __('channel.orphan_clients', ['count' => $orphans]) }}
                    <a href="{{ route('erp.clients') }}">{{ __('common.show_them') }} ←</a>
                </div>
            </div>
        @endif
    </div>
</div>

<div class="grid2">
    <div class="card">
        <h3>🏪 {{ __('client.key_account_segments') }}</h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('client.segment') }}</th><th>{{ __('client.client_count') }}</th></tr>
                @foreach (Channel::SUB_CHANNELS as $code => $label)
                    <tr>
                        <td><b>{{ __('enums.sub_channel.'.$code) }}</b></td>
                        <td class="num">{{ $subCounts[$code] ?? 0 }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    <div class="card">
        <h3>👔 {{ __('client.channel_managers') }}</h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('client.manager') }}</th><th>{{ __('client.responsible_for') }}</th>@if ($admin)<th></th>@endif</tr>
                @foreach ($managers as $m)
                    @php
                        $mJson = json_encode([
                            'id' => $m->id,
                            'name' => $m->name,
                            'channels' => $m->channels->pluck('id')->all(),
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                    @endphp
                    <tr>
                        <td><b>{{ $m->name }}</b><br><span style="font-size:10.5px;color:var(--muted)">{{ $m->code }}</span></td>
                        <td>
                            @forelse ($m->channels as $c)
                                <span class="badge {{ $c->badgeClass() }}">{{ $c->displayName() }}</span>
                            @empty
                                <span class="badge b-gray">{{ __('client.no_channels') }}</span>
                            @endforelse
                        </td>
                        @if ($admin)
                            <td>
                                <button class="btn sm" onclick='editManager({!! $mJson !!})'>{{ __('common.edit') }}</button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
</div>

@if ($manager)
<dialog id="dlgCh">
    <form class="dlg" method="POST" id="formCh">
        @csrf @method('PUT')
        <h4 id="chTitle">{{ __('client.edit_channel') }}</h4>
        <div class="frow">
            {{-- ⚠️ الاسمين مع بعض. `displayName()` بترجّع العربي كـfallback
                 لو الإنجليزي فاضي، فالواجهة الإنجليزية بتعرض «كي أكاونت»
                 في نص جملة إنجليزي والغلط بيعدّي من غير ما حد ياخد باله. --}}
            <div><label class="f">{{ __('common.name_ar') }}</label><input type="text" name="name" id="chName" required style="width:100%"></div>
            <div><label class="f">{{ __('common.name_en') }}</label><input type="text" name="name_en" id="chNameEn" dir="ltr" style="width:100%"></div>

        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;margin-top:6px">
            <input type="checkbox" name="active" value="1" id="chActive"> {{ __('client.channel_active') }}
        </label>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgCh')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@if ($admin)
<dialog id="dlgMgr">
    <form class="dlg" method="POST" id="formMgr">
        @csrf
        <h4 id="mgrTitle">{{ __('client.manager_channels') }}</h4>
        <p style="font-size:12px;color:var(--muted);margin-bottom:10px">
            {{ __('client.manager_scope_note') }}
        </p>
        @foreach ($channels as $ch)
            <label style="display:flex;align-items:center;gap:8px;padding:7px 0;font-size:13px">
                <input type="checkbox" name="channels[]" value="{{ $ch->id }}" class="mgrCh">
                <span class="badge {{ $ch->badgeClass() }}">{{ $ch->displayName() }}</span>
            </label>
        @endforeach
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgMgr')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<script>
function editChannel(c) {
    document.getElementById('chTitle').textContent = {!! json_encode(__('common.edit'), JSON_UNESCAPED_UNICODE) !!} + ': ' + c.name;
    document.getElementById('formCh').action = '{{ url('erp/channels') }}/' + c.id;
    document.getElementById('chName').value = c.name;
    // ⚠️ من غير السطر ده، فتح مودال التعديل بيسيب الخانة فاضية
    // وأول حفظ بيمسح الاسم الإنجليزي اللي كان متسجل.
    document.getElementById('chNameEn').value = c.name_en || '';
    document.getElementById('chActive').checked = c.active;
    openDlg('dlgCh');
}

function editManager(m) {
    document.getElementById('mgrTitle').textContent = {!! json_encode(__('client.channels_of'), JSON_UNESCAPED_UNICODE) !!} + ' ' + m.name;
    document.getElementById('formMgr').action = '{{ url('erp/channels/manager') }}/' + m.id;
    document.querySelectorAll('.mgrCh').forEach(cb => {
        cb.checked = m.channels.includes(parseInt(cb.value));
    });
    openDlg('dlgMgr');
}
</script>
@endsection
