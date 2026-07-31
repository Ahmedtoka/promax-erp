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

        // بيتحفظ على اليوزر عشان يفضل معاه على أي جهاز،
        // وفي السيشن كمان عشان صفحة اللوجين قبل ما يسجّل دخول
        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['locale' => $locale])->save();

        return back();
    }
}
