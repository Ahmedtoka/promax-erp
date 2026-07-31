<?php

namespace App\Exceptions;

/**
 * رفض متوقّع — قاعدة شغل منعت العملية، مش خطأ تقني.
 * رسالتها بتتعرض للمستخدم على طول (مترجمة)، والرد بيكون 422.
 *
 * ⚠️ ليه استثناء مخصوص وماستخدمناش RuntimeException؟
 * لأن QueryException بترث من PDOException اللي بترث من RuntimeException.
 * فلو لقفنا RuntimeException، أي خطأ SQL (ديد لوك، كسر FK، انقطاع اتصال)
 * كان بيرجع للأبلكيشن كـ 422 وفيه نص الكويري نفسه — ده بيخبّي أخطاء
 * حقيقية وبيفضح السكيما. الاستثناء ده بيلقف الرفض المتوقّع بس، وأي حاجة
 * تانية تكمّل طريقها لـ 500 وتتسجل في اللوج زي ما المفروض.
 *
 * ⚠️ ممنوع تلقف RuntimeException حوالين DB::transaction في أي مكان.
 */
class Rejected extends \RuntimeException
{
}
