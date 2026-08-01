@php
    // اللغة والاتجاه من SetLocale middleware — نفس منطق ليّاوت النظام
    $locale = app()->getLocale();
    $isRtl = $locale === 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ __('auth.sign_in') }} — PROMAX ERP</title>
<link rel="icon" href="{{ asset('brand/logo/logo-v-blue.png') }}">
{{-- طبقة البراند: الألوان والفونتات الرسمية --}}
<link rel="stylesheet" href="{{ asset('brand/promax.css') }}">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
  font-family:{{ $isRtl ? 'Cairo,Poppins,Tahoma' : 'Poppins,Cairo,Tahoma' }},sans-serif;
  min-height:100vh;display:grid;grid-template-columns:1.05fr .95fr;
  color:var(--text);overflow:hidden;
}
@media(max-width:900px){body{grid-template-columns:1fr}.stage{display:none}}

/* ═══ اليسار: مسرح البراند ═══ */
.stage{
  background:var(--brand-gradient);
  position:relative;overflow:hidden;
  display:flex;flex-direction:column;justify-content:space-between;
  padding:44px 48px;color:#fff;
}
/* الصاعقة — العنصر الأساسي للبراند */
.stage::after{
  content:"";position:absolute;
  inset-block-start:50%;inset-inline-start:50%;
  transform:translate(-50%,-50%) rotate(-10deg);
  width:min(78%,520px);aspect-ratio:225/416;
  background:url('{{ asset('brand/bolt.svg') }}') no-repeat center/contain;
  opacity:.12;filter:brightness(4);pointer-events:none;
}
.stage > *{position:relative;z-index:1}
.stage .mark{width:210px;height:auto}
.stage .tag{
  font-size:clamp(30px,3.6vw,46px);font-weight:800;line-height:1.15;
  letter-spacing:-1.2px;max-width:12ch;
}
.stage .tag em{color:var(--brand-yellow);font-style:normal}
.stage .foot{font-size:11px;letter-spacing:1.6px;opacity:.65;font-weight:600}

/* ═══ اليمين: الفورم ═══ */
.pane{display:grid;place-items:center;padding:28px 22px;background:var(--paper)}
.box{width:100%;max-width:392px}
.hdr{margin-bottom:22px}
.hdr h1{font-size:24px;font-weight:800;letter-spacing:-.6px}
.hdr p{font-size:12.5px;color:var(--muted);margin-top:4px}
.hdr .mobmark{width:150px;margin-bottom:16px;display:none}
@media(max-width:900px){.hdr .mobmark{display:block}}

.card{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--r-lg);padding:26px 24px;box-shadow:var(--shadow);
}
label{display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--muted)}
input[type=text],input[type=password]{
  width:100%;border:1px solid var(--border);border-radius:var(--r-sm);
  padding:12px 14px;font-family:inherit;font-size:14px;outline:none;
  margin-bottom:14px;transition:.15s;background:var(--card);
}
input:focus{border-color:var(--royal-blue);box-shadow:0 0 0 3px rgba(18,57,155,.14)}
button{
  width:100%;background:var(--brand-gradient);color:#fff;border:none;
  border-radius:var(--r-sm);padding:13px;font-family:inherit;font-weight:800;
  font-size:15px;cursor:pointer;transition:.18s;box-shadow:0 4px 14px rgba(18,57,155,.28);
}
button:hover{filter:brightness(1.12);box-shadow:var(--shadow-lift)}
.err{background:#FDECEC;color:var(--red);border-radius:var(--r-sm);padding:10px 12px;font-size:12.5px;font-weight:700;margin-bottom:14px}
.hint{margin-top:16px;font-size:11.5px;color:var(--muted);line-height:1.95;background:var(--blue-050);border-radius:var(--r-sm);padding:12px 14px;border-inline-start:3px solid var(--royal-blue)}
.hint b{color:var(--royal-blue)}
.remember{display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--muted);margin-bottom:16px}
.langsw{display:flex;gap:6px;justify-content:center;margin-top:16px}
.langsw button{width:auto;padding:5px 16px;font-size:11.5px;background:transparent;color:var(--muted);border:1px solid var(--border);box-shadow:none;font-weight:700}
.langsw button.on{background:var(--royal-blue);color:#fff;border-color:var(--royal-blue)}
</style>
</head>
<body>

<aside class="stage">
    <img class="mark" src="{{ asset('brand/logo/logo-h-white.svg') }}" alt="PROMAX">
    <div class="tag">{!! $isRtl ? 'شغّل <em>طاقتك</em>' : 'Fuel Your <em>Flow</em>' !!}</div>
    <div class="foot">PROMAX FOOD INDUSTRIES</div>
</aside>

<main class="pane">
<div class="box">
    <div class="hdr">
        <img class="mobmark" src="{{ asset('brand/logo/logo-h-blue.svg') }}" alt="PROMAX">
        <h1>{{ __('auth.sign_in') }}</h1>
        <p>{{ __('nav.tagline') }}</p>
    </div>

    <form class="card" method="POST" action="{{ route('login') }}">
        @csrf

        @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        <label for="email">{{ __('auth.email_or_code') }}</label>
        {{-- ⚠️ **مفيش قيمة افتراضية.** كانت بتتملّى بإيميل أول حساب
             في السيستم — يعني أي حد بيفتح الصفحة بياخد إيميل شغّال
             جاهز، ومحتاج الباسورد بس. --}}
        <input id="email" type="text" name="email" value="{{ old('email') }}" required autofocus
               autocomplete="username" placeholder="{{ __('auth.email_or_code_ph') }}">

        <label for="password">{{ __('auth.password') }}</label>
        <input id="password" type="password" name="password" required>

        <div class="remember">
            <input type="checkbox" name="remember" id="remember" value="1">
            <label for="remember" style="margin:0;font-weight:600">{{ __('auth.remember_me') }}</label>
        </div>

        <button type="submit">{{ __('auth.sign_in') }}</button>

        {{-- ⚠️ **قايمة الحسابات اتشالت خالص.**
             كانت بتعرض إيميل واسم ورول كل حساب مفعّل في السيستم لأي
             حد يفتح الصفحة — يعني نص بيانات الدخول متسلّمة قبل أي
             محاولة، والباقي تخمين باسورد. ده كان مقبول وإحنا بنجرّب
             على الجهاز، ومستحيل على سيستم شغّال على الإنترنت.

             ⚠️ **وممنوع ترجع تاني في أي شكل** — ولا «حساب تجريبي»
             ولا لينك «نسيت الباسورد» بيقول إن الإيميل موجود ولا لأ. --}}

    </form>

    {{-- تبديل اللغة قبل الدخول --}}
    <div class="langsw">
        @foreach (\App\Models\User::LOCALES as $code => $label)
            <form method="POST" action="{{ route('locale.switch', $code) }}">
                @csrf
                <button type="submit" class="{{ $locale === $code ? 'on' : '' }}">{{ $label }}</button>
            </form>
        @endforeach
    </div>
</div>
</main>

</body>
</html>
