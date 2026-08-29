<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /** POST /locale/{locale} — تبديل لغة الواجهة */
    public function switch(Request $request, string $locale)
    {
        if (! array_key_exists($locale, User::LOCALES)) {
            return back();
        }

        // بيتحفظ في السيشن (يلزق فوراً + صفحة اللوجين قبل الدخول)
        // وفي `web_locale` عشان يتبعه على أي جهاز.
        // ⚠️ ماينفعش نكتب في `users.locale` — ده بقى ملك الأبلكيشن
        // لوحده (٢٧/٨): الكتابة فيه من هنا كانت بتقلب لغة الأبلكيشن
        // على المندوب من غير ما يعمل حاجة.
        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['web_locale' => $locale])->save();

        return back();
    }
}
