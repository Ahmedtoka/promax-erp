@extends('layouts.system')

@section('title', __('tax.settings'))

@php
    $on = ($s['tax_enabled'] ?? '0') === '1';
    $rate = (float) ($s['tax_rate'] ?? 0);
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.eta') }}">{{ __('tax.eta_page') }} →</a>
@endsection

@section('content')

<div class="card">
    <h3>⚙️ {{ __('tax.settings') }} <span class="side">{{ __('tax.settings_sub') }}</span></h3>

    {{-- ⚠️ الحالة أهم حاجة في الصفحة: اليوزر لازم يعرف من أول نظرة
         هل الفواتير بتطلع بضريبة ولا لأ. --}}
    @if (! $on)
        <div class="alert warn">{{ __('tax.tax_off_warning') }}</div>
    @else
        <div class="alert good">{{ __('tax.tax_on_notice', ['rate' => rtrim(rtrim(number_format($rate, 2), '0'), '.').'%']) }}</div>

        @if (($s['company_tax_id'] ?? '') === '')
            <div class="alert warn">{{ __('tax.missing_tax_id') }}</div>
        @endif

        @if ($taxableClients === 0)
            <div class="alert warn">{{ __('tax.no_taxable_clients') }}</div>
        @endif
    @endif
</div>

<form method="POST" action="{{ route('erp.tax.settings.save') }}">
    @csrf

    {{-- ═══════════ الضريبة ═══════════ --}}
    <div class="card">
        <h3>🧾 {{ __('tax.tax_section') }}</h3>
        <div class="frow">
            <div>
                <label class="f">{{ __('tax.tax_enabled') }}</label>
                <label style="display:flex;align-items:center;gap:7px;padding-top:6px">
                    <input type="hidden" name="tax_enabled" value="0">
                    <input type="checkbox" name="tax_enabled" value="1" @checked($on)>
                    <span style="font-size:12px;color:var(--muted)">{{ __('tax.tax_enabled_hint') }}</span>
                </label>
            </div>
            <div>
                <label class="f">{{ __('tax.default_rate') }}</label>
                <input type="number" step="0.5" min="0" max="100" name="tax_rate"
                       value="{{ $rate }}" required style="width:100%">
                <div style="font-size:11px;color:var(--muted);margin-top:4px">{{ __('tax.default_rate_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('tax.taxable') }}</label>
                <div style="padding-top:9px;font-size:13px">
                    <b class="num">{{ number_format($taxableClients) }}</b>
                    <span style="color:var(--muted)"> / {{ number_format($totalClients) }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════ بيانات الشركة ═══════════ --}}
    <div class="card">
        <h3>🏢 {{ __('tax.company_section') }}</h3>
        <div class="frow">
            <div>
                <label class="f">{{ __('tax.company_name') }}</label>
                <input type="text" name="company_name" value="{{ $s['company_name'] ?? '' }}" required style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('tax.company_name_en') }}</label>
                <input type="text" name="company_name_en" value="{{ $s['company_name_en'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('tax.company_tax_id') }}</label>
                <input type="text" name="company_tax_id" value="{{ $s['company_tax_id'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('tax.activity_code') }}</label>
                <input type="text" name="company_activity_code" value="{{ $s['company_activity_code'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('tax.branch_code') }}</label>
                <input type="text" name="company_branch_code" value="{{ $s['company_branch_code'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('tax.phone') }}</label>
                <input type="text" name="company_phone" value="{{ $s['company_phone'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('doc.cr') }}</label>
                <input type="text" name="company_cr" value="{{ $s['company_cr'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('doc.email') }}</label>
                <input type="email" name="company_email" value="{{ $s['company_email'] ?? '' }}" style="width:100%">
            </div>
        </div>

        {{-- ⚠️ سطر واحد **للطباعة**، منفصل عن حقول العنوان المفكوكة تحت.
             المفكوكة بتروح لمصلحة الضرائب بصيغتها المطلوبة، وده اللي
             بيتقرا بعين بشرية على الورقة. --}}
        <div style="margin-top:12px">
            <label class="f">{{ __('tax.print_address') }}</label>
            <input type="text" name="company_address" value="{{ $s['company_address'] ?? '' }}" style="width:100%">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">{{ __('tax.print_address_hint') }}</div>
        </div>
    </div>

    {{-- ═══════════ بيانات البنك ═══════════ --}}
    <div class="card">
        <h3>🏦 {{ __('doc.bank_details') }}
            <span class="side">{{ __('tax.bank_hint') }}</span></h3>

        {{-- ⚠️ التحذير قبل الحقول مش بعدها: مستند بيقول «حوّل على
             الحساب المدرج فقط» وفيه رقم حساب وهمي أخطر من مستند
             من غير بيانات بنك خالص. --}}
        @if (\App\Models\Setting::bankIsDemo())
            <div class="alert warn">{{ __('doc.bank_demo') }}</div>
        @endif

        <div class="frow">
            <div>
                <label class="f">{{ __('doc.bank_name') }}</label>
                <input type="text" name="bank_name" value="{{ $s['bank_name'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('doc.bank_branch') }}</label>
                <input type="text" name="bank_branch" value="{{ $s['bank_branch'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('doc.bank_account_name') }}</label>
                <input type="text" name="bank_account_name" value="{{ $s['bank_account_name'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('doc.bank_account_no') }}</label>
                <input type="text" name="bank_account_no" value="{{ $s['bank_account_no'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('doc.bank_iban') }}</label>
                <input type="text" name="bank_iban" value="{{ $s['bank_iban'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('doc.bank_swift') }}</label>
                <input type="text" name="bank_swift" value="{{ $s['bank_swift'] ?? '' }}" style="width:100%">
            </div>
        </div>

        <div class="alert info">{{ __('doc.bank_note') }}</div>
    </div>

    {{-- ═══════════ العنوان ═══════════ --}}
    <div class="card">
        <h3>📍 {{ __('tax.address_section') }}</h3>
        <div class="frow">
            <div>
                <label class="f">{{ __('tax.governorate') }}</label>
                <input type="text" name="company_governorate" value="{{ $s['company_governorate'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('tax.city') }}</label>
                <input type="text" name="company_city" value="{{ $s['company_city'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('tax.street') }}</label>
                <input type="text" name="company_street" value="{{ $s['company_street'] ?? '' }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('tax.building') }}</label>
                <input type="text" name="company_building" value="{{ $s['company_building'] ?? '' }}" style="width:100%">
            </div>
        </div>
    </div>

    {{-- ═══════════ المنظومة ═══════════ --}}
    <div class="card">
        <h3>🔗 {{ __('tax.eta_section') }}</h3>
        <div class="frow">
            <div>
                <label class="f">{{ __('tax.eta_client_id') }}</label>
                <input type="text" name="eta_client_id" value="{{ $s['eta_client_id'] ?? '' }}" style="width:100%">
            </div>
        </div>
        <div class="alert info">{{ __('tax.signing_notice') }}</div>
    </div>

    <div class="card" style="text-align:center">
        <button class="btn gold" style="padding:11px 26px">💾 {{ __('tax.save') }}</button>
    </div>
</form>

@endsection
