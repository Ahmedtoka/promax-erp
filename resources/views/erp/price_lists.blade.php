@extends('layouts.system')

@php
    $fmt = fn ($n) => number_format((float) $n);
    $u = auth()->user();
    $canEdit = $u->isAdmin() || $u->role === 'manager';
@endphp

@section('title', __('price.price_lists'))

@section('actions')
    @if ($canEdit)
        @if (\App\Support\Access::action(auth()->user(), 'act.prices.edit'))<button class="btn gold" onclick="openDlg('dlgNewList')">+ {{ __('price.new_list') }}</button>@endif
    @endif
@endsection

@section('content')

{{-- ⚠️ **التحذير ده لازم يفضل ظاهر.** السعر اللي بيتكتب هنا هو اللي
     بيتحاسب بيه العميل في كل فاتورة — مش إعداد، ده فلوس. --}}
<div class="alert info" style="margin-bottom:14px">
    <span>🏷️</span><span>{{ __('price.lists_hint') }}</span>
</div>

<div class="card">
    <h3>{{ __('price.price_lists') }}
        <span class="side">{{ $totalProducts }} {{ __('price.priced_items') }}</span></h3>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.code') }}</th>
                <th>{{ __('price.list') }}</th>
                <th class="num">{{ __('price.priced') }}</th>
                <th class="num">{{ __('price.missing') }}</th>
                <th class="num">{{ __('client.live_clients') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>

            @forelse ($lists as $l)
                <tr>
                    <td class="num"><b>{{ $l->code }}</b></td>
                    <td>
                        <a href="{{ route('erp.prices.show', $l) }}"><b>{{ $l->displayName() }}</b></a>
                        @if ($l->is_default)
                            <span class="badge b-purple">{{ __('price.default') }}</span>
                        @endif
                        @if ($l->notes)
                            <div style="font-size:10.5px;color:var(--muted)">{{ $l->notes }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $fmt($l->priced_count) }}</td>
                    <td class="num">
                        @if ($l->missing_count > 0)
                            <b class="neg">{{ $fmt($l->missing_count) }}</b>
                        @else
                            <span class="pos">✓</span>
                        @endif
                    </td>
                    <td class="num">{{ $fmt($l->live_clients) }}</td>
                    <td>
                        @if ($l->active)
                            <span class="badge b-green">{{ __('common.active') }}</span>
                        @else
                            <span class="badge b-gray">{{ __('price.draft') }}</span>
                        @endif
                    </td>
                    <td class="num" style="white-space:nowrap">
                        <a class="btn sm" href="{{ route('erp.prices.show', $l) }}">
                            {{ __('price.set_prices') }} ←
                        </a>

                        @if ($canEdit)
                            @if (! $l->active)
                                {{-- ⚠️ الزرار بيبان دايماً وبيرفض بالرسالة لو
                                     القايمة ناقصة — إخفاؤه بيخلّي اللي قدامه
                                     يفتكر إن التفعيل مش موجود أصلاً. --}}
                                <form method="POST" action="{{ route('erp.prices.activate', $l) }}"
                                      style="display:inline">
                                    @csrf
                                    <button class="btn sm gold" type="submit">✅ {{ __('price.activate') }}</button>
                                </form>
                            @else
                                @unless ($l->is_default)
                                    <form method="POST" action="{{ route('erp.prices.default', $l) }}"
                                          style="display:inline">
                                        @csrf
                                        <button class="btn sm" type="submit">{{ __('price.make_default') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('erp.prices.deactivate', $l) }}"
                                          style="display:inline">
                                        @csrf
                                        <button class="btn sm" type="submit">{{ __('price.stop') }}</button>
                                    </form>
                                @endunless
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('price.no_lists') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($canEdit)
<dialog id="dlgNewList">
    <form class="dlg" method="POST" action="{{ route('erp.prices.store') }}">
        @csrf
        <h4>🏷️ {{ __('price.new_list') }}</h4>

        <div class="frow">
            <div>
                <label class="f">{{ __('common.code') }} <b class="req-star">*</b></label>
                {{-- ⚠️ الكود بيتخزن على العميل والعقد، فتغييره بعد كده
                     بيقطع الربط في صمت والعميل ياخد الافتراضية. --}}
                <input type="text" name="code" dir="ltr" maxlength="30" required style="width:100%"
                       class="{{ $errors->has('code') ? 'bad' : '' }}"
                       value="{{ old('code') }}" placeholder="list-2">
                @error('code')<div class="errline">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="f">{{ __('price.copy_from') }}</label>
                <select name="copy_from" style="width:100%">
                    <option value="">— {{ __('price.start_empty') }} —</option>
                    @foreach ($lists as $l)
                        <option value="{{ $l->id }}">{{ $l->displayName() }}</option>
                    @endforeach
                </select>
                <div style="font-size:11px;color:var(--muted);margin-top:5px">
                    {{ __('price.copy_hint') }}
                </div>
            </div>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('common.name_en') }}</label>
                <input type="text" name="name_en" dir="ltr" maxlength="190" style="width:100%"
                       value="{{ old('name_en') }}" placeholder="{{ __('price.name_en_ph') }}">
            </div>
            <div>
                <label class="f">{{ __('price.name_ar') }} <b class="req-star">*</b></label>
                <input type="text" name="name" maxlength="190" required style="width:100%"
                       class="{{ $errors->has('name') ? 'bad' : '' }}"
                       value="{{ old('name') }}" placeholder="{{ __('price.name_ar_ph') }}">
                @error('name')<div class="errline">{{ $message }}</div>@enderror
            </div>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('common.notes') }}</label>
            <input type="text" name="notes" maxlength="190" style="width:100%" value="{{ old('notes') }}">
        </div>

        <div class="alert warn" style="margin-top:12px">
            <span>⚠️</span><span>{{ __('price.new_list_note') }}</span>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewList')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
