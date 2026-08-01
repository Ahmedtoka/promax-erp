<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        if (Auth::check()) {
            return redirect()->route(Access::home(auth()->user()));
        }

        // ⚠️ **مفيش أي بيانات حسابات بتتبعت للصفحة.**
        // كانت بتبعت 12 حساب بإيميلاتهم وأسمائهم ورولاتهم لأي حد
        // يفتح `/login` — من غير تسجيل دخول. على سيستم على الإنترنت
        // ده تسليم نص بيانات الدخول لأي زائر.
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], [
            'email' => __('auth.attr_email_or_code'),
            'password' => __('auth.attr_password'),
        ]);

        // يقبل الإيميل أو كود الموظف
        $field = filter_var($data['email'], FILTER_VALIDATE_EMAIL) ? 'email' : 'code';
        $credentials = [$field => $data['email'], 'password' => $data['password']];

        if (! Auth::attempt($credentials + ['active' => 1], $request->boolean('remember'))) {
            // لو البيانات صح يبقى الحساب موقوف — نفرّق في الرسالة
            throw ValidationException::withMessages([
                'email' => Auth::validate($credentials) ? __('auth.inactive') : __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        // ⚠️ **كل رول بيهبط على شاشة مسموحة له.** الديفولت كان
        // `erp.overview` للكل — يعني أمين المخزن كان يدخل ويترمي على
        // 403 في وشه أول ثانية، ويفتكر إن الحساب مش شغال ويكلّم الأدمن.
        $home = route(Access::home($request->user()));

        // ⚠️ `intended()` بترجّعه للصفحة اللي اتمنع منها. أمين المخزن
        // اللي جرّب يفتح `/erp/clients` وترمى على اللوجين كان هيرجع
        // لنفس الصفحة بعد الدخول ويترمي على 403 تاني — لوب مالوش نهاية
        // من وجهة نظره. بنتأكد إن الوجهة مسموحة قبل ما نروّحه ليها.
        $target = session()->pull('url.intended', $home);

        // ⚠️ `rescue` لازم: `match()` بترمي 404 لو الوجهة مش راوت
        // معروف (لينك قديم في المفضلة مثلاً)، والاستثناء ده كان هيبوّظ
        // اللوجين نفسه — المستخدم بيدخل بيانات صح ويشوف صفحة خطأ.
        $intendedRoute = rescue(
            fn () => app('router')->getRoutes()->match(Request::create($target))?->getName(),
            null,
            report: false,
        );

        if ($intendedRoute === null || ! Access::allows($request->user(), $intendedRoute)) {
            $target = $home;
        }

        return redirect()->to($target);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
