@extends('layouts.system')

{{--
    إعداد السلاسل / العملاء — صف واحد بكل حاجة  ·  ١٧ أغسطس ٢٠٢٦

    طلب المالك: «اسم السلسلة .. الديفيجن .. النوع .. السعر اللي
    بيتعامل بيه .. الخصم .. شامل ولا لا — وأدوس Save فكل عملاء
    السلسلة ياخدوا نفس البيانات». وصفحة تانية بنفس الترتيب للعملاء.

    ⚠️ **البليد واحد للشاشتين** (`$mode`) — الأعمدة هي هي، والفرق
    الوحيد أول عمود وراوت الحفظ. نسختين كانوا هيفترقوا مع أول تعديل.
--}}

@php
    use App\Support\Divisions;
    $isChains = $mode === 'chains';
@endphp

@section('title', $isChains ? __('client.setup_chains') : __('client.setup_clients'))

@section('actions')
    @if ($isChains)
        <a class="btn" href="{{ route('erp.setup.clients') }}">👥 {{ __('client.setup_clients') }}</a>
    @else
        <a class="btn" href="{{ route('erp.setup.chains') }}">🔗 {{ __('client.setup_chains') }}</a>
        <a class="btn {{ request()->boolean('unassigned') ? 'gold' : '' }}"
           href="{{ route('erp.setup.clients', request()->boolean('unassigned') ? [] : ['unassigned' => 1]) }}">
            ⚠️ {{ __('client.no_division') }}</a>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>{{ $isChains ? '🔗 '.__('client.setup_chains') : '👥 '.__('client.setup_clients') }}
        <span class="side">{{ $rows->count() }}</span></h3>

    <div class="tablewrap">
        <table data-page="50">
            <thead>
            <tr>
                <th>{{ $isChains ? __('client.chain') : __('client.client') }}</th>
                <th style="min-width:170px" data-nosum>{{ __('client.division') }}</th>
                <th style="min-width:150px" data-nosum>{{ __('client.ff_type') }}</th>
                <th style="min-width:150px" data-nosum>{{ __('client.price_list') }}</th>
                <th style="width:90px" data-nosum>{{ __('client.discount') }} %</th>
                <th style="width:80px" data-nosum>{{ __('client.tax_inclusive') }}</th>
                <th style="width:70px"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                @php
                    // ⚠️ صف السلسلة بيتعبى من **أول فرع** كتمثيل للوضع
                    // الحالي — الحفظ بيوحّد الكل عليه بعد التعديل.
                    $c = $isChains ? $r->clients()->first() : $r;
                @endphp
                <tr>
                    <td>
                        @if ($isChains)
                            <a href="{{ route('erp.groups.show', $r) }}"><b>{{ $r->displayName() }}</b></a>
                            <br><span style="font-size:10.5px;color:var(--muted)">
                                {{ __('client.branch_countable', ['count' => $r->clients_count]) }}</span>
                        @else
                            <a href="{{ route('erp.clients.show', $r) }}"><b>{{ $r->displayName() }}</b></a>
                            <br><span style="font-size:10.5px;color:var(--muted)">
                                {{ $r->group?->displayName() ?? ($r->zone?->displayName() ?? '—') }}</span>
                        @endif
                    </td>
                    @php $fid = ($isChains ? 'g' : 'c').$r->id; @endphp
                    <td>
                        <select name="division" form="{{ $fid }}" style="width:100%">
                            <option value="">— {{ __('client.no_division') }} —</option>
                            @foreach (Divisions::options() as $k => $lbl)
                                <option value="{{ $k }}" @selected(($c?->division) === $k)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        {{-- ⚠️ الفاضي = «حسب الديفيجن» مش «مفيش» --}}
                        <select name="fulfillment_mode" form="{{ $fid }}" style="width:100%">
                            <option value="">— {{ __('client.ff_by_division') }} —</option>
                            @foreach (['cashvan', 'delivery', 'online'] as $f)
                                <option value="{{ $f }}" @selected(($c?->fulfillment_mode) === $f)>
                                    {{ __('client.ff_'.$f) }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <select name="price_list_id" form="{{ $fid }}" style="width:100%">
                            <option value="">—</option>
                            @foreach ($lists as $pl)
                                <option value="{{ $pl->id }}" @selected(($c?->price_list_id) === $pl->id)>
                                    {{ $pl->displayName() }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <input type="number" name="discount" form="{{ $fid }}"
                               step="0.01" min="0" max="100" dir="ltr"
                               style="width:100%;text-align:center"
                               value="{{ $c && (float) $c->discount > 0 ? rtrim(rtrim(number_format((float) $c->discount * 100, 2, '.', ''), '0'), '.') : '' }}">
                    </td>
                    <td style="text-align:center">
                        {{-- شامل = مفيش ضريبة بتتضاف (taxable = false) --}}
                        <input type="hidden" name="inclusive" value="0" form="{{ $fid }}">
                        <input type="checkbox" name="inclusive" value="1" form="{{ $fid }}"
                               @checked($c && ! $c->taxable)>
                    </td>
                    <td>
                        <form id="{{ $fid }}" method="POST"
                              action="{{ $isChains
                                  ? route('erp.setup.chains.save', $r)
                                  : route('erp.setup.clients.save', $r) }}">
                            @csrf
                            <button class="btn sm gold" type="submit">💾 Save</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">
                    {{ __('client.all_assigned') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
