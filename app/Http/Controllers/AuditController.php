<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * سجل حركة اليوزرات القديم — اتنقل لمركز نشاط المستخدمين (٢٢ سبتمبر ٢٠٢٦).
 *
 * الراوت فاضل عشان اللينكات المحفوظة والإشعارات القديمة: بيحوّل لتاب
 * «السجل الكامل» في `ActivityController` بنفس الفلاتر.
 */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        return redirect()->route('erp.activity', ['tab' => 'log'] + $request->query());
    }
}
