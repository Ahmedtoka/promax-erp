<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * إصدار الأبلكيشن — التحكم في التحديثات (2026-08-07)
 * ═══════════════════════════════════════════════════════════════
 *
 * الأبلكيشن مش على جوجل بلاي، فالتحديث بيتوزّع من هنا: بترفع ملف
 * APK، وبتقول إيه آخر إصدار وإيه أقل إصدار مسموح — والأبلكيشن
 * بيسأل عند كل فتحة.
 *
 * ⚠️ **التفرقة بين `app_version` و`app_min_version` هي كل الموضوع:**
 *   • `app_version`     = آخر إصدار متاح → المندوب بيشوف «فيه تحديث»
 *     ويقدر يقفل الرسالة ويكمّل شغله.
 *   • `app_min_version` = أقل إصدار مسموح → **الشاشة بتتقفل** ومفيش
 *     شغل لحد ما يحدّث.
 *
 * رفع الاتنين مع بعض معناه إيقاف كل مندوب في الشارع في نفس اللحظة.
 * ماتعملهاش غير لو التحديث بيصلح حاجة بتفسد داتا، أو الـAPI اتغيّر
 * بشكل بيكسّر النسخة القديمة.
 *
 * ⚠️ **الـAPK بيتحفظ في `public/app/`** — لازم يكون قابل للتنزيل من
 * غير تسجيل دخول، عشان الأبلكيشن بينزّله والمندوب ممكن يكون خارج
 * الجلسة. مفيش أي حاجة سرية فيه.
 */
class AppVersionController extends Controller
{
    /** المسار النسبي جوّه `public/` */
    private const APK_PATH = 'app/promax.apk';

    public function edit()
    {
        $apk = public_path(self::APK_PATH);

        return view('erp.app_version', [
            'version' => Setting::read('app_version', '1.0.0'),
            'minVersion' => Setting::read('app_min_version', '1.0.0'),
            'apkUrl' => Setting::read('app_apk_url', ''),
            'note' => Setting::read('app_update_note', ''),
            'apkExists' => is_file($apk),
            'apkSize' => is_file($apk) ? filesize($apk) : 0,
            'apkAt' => is_file($apk) ? date('Y-m-d H:i', filemtime($apk)) : null,
            // كام جهاز على كل إصدار — بيوريك التحديث وصل لمين فعلاً
            'devices' => DeviceToken::selectRaw('COALESCE(NULLIF(app_version, ""), "—") v, COUNT(*) n')
                ->groupBy('v')->orderByDesc('n')->pluck('n', 'v')->all(),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            // ⚠️ الصيغة متقيّدة `1.2.3` عشان المقارنة الرقمية في
            // الأبلكيشن تشتغل — أي صيغة تانية بتخلي المقارنة تفشل
            // في صمت والتحديث مايوصلش
            'app_version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'],
            'app_min_version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'],
            'app_update_note' => ['nullable', 'string', 'max:300'],
            'apk' => ['nullable', 'file', 'mimetypes:application/vnd.android.package-archive,application/octet-stream', 'max:204800'],
        ]);

        // ⚠️ **الأدنى ماينفعش يبقى أعلى من المتاح.** لو حصل، كل
        // الأجهزة هتتقفل على تحديث مش موجود أصلاً — وممكن الأبلكيشن
        // يبقى غير قابل للاستخدام خالص لحد ما تدخل السيرفر.
        if (version_compare($data['app_min_version'], $data['app_version'], '>')) {
            return back()->withInput()
                ->withErrors(['app_min_version' => __('appver.min_above_latest')]);
        }

        if ($request->hasFile('apk')) {
            $dir = public_path('app');

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $request->file('apk')->move($dir, 'promax.apk');
        }

        Setting::writeMany([
            'app_version' => $data['app_version'],
            'app_min_version' => $data['app_min_version'],
            'app_update_note' => (string) ($data['app_update_note'] ?? ''),
            // الرابط بيتولّد لوحده — كتابته بالإيد مصدر أخطاء
            'app_apk_url' => is_file(public_path(self::APK_PATH))
                ? url(self::APK_PATH).'?v='.$data['app_version']
                : '',
        ]);

        return back()->with('ok', __('appver.saved'));
    }

    /**
     * GET /api/app-version — **من غير تسجيل دخول عن قصد.**
     *
     * ⚠️ شاشة «لازم تحدّث» بتظهر قبل اللوجين كمان: المندوب اللي
     * نسخته قديمة ممكن يكون خارج الجلسة، ولو الإندبوينت محمي
     * هيلاقي نفسه مقفول من غير ما يعرف السبب.
     */
    public function api()
    {
        return response()->json([
            'version' => Setting::read('app_version', '1.0.0'),
            'min_version' => Setting::read('app_min_version', '1.0.0'),
            'apk_url' => Setting::read('app_apk_url', ''),
            'note' => Setting::read('app_update_note', ''),
        ]);
    }
}
