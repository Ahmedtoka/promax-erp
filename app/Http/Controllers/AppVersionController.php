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
            // ⚠️ **بتوقيت مصر مش UTC** (إصلاح ١١/٨): `date()` بتطلع
            // بتوقيت السيرفر، فالمالك رفع الملف حالاً ولقى الساعة
            // «غلط» بتلات ساعات وافتكر إن الرفع فشل والملف قديم.
            'apkAt' => is_file($apk)
                ? \Illuminate\Support\Carbon::createFromTimestamp(filemtime($apk))
                    ->timezone('Africa/Cairo')->format('Y-m-d h:i A')
                : null,
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
            // الرابط بيتولّد لوحده — كتابته بالإيد مصدر أخطاء.
            // ⚠️ **كاش-باستينج بوقت الملف مش برقم الإصدار** (٦/٩): رفعتين
            // بنفس الرقم كانوا بياخدوا نفس الـURL، وVarnish بتاع
            // Cloudways كان بيسرّف الـAPK القديم من الكاش — المندوب
            // يحدّث وينزّله النسخة القديمة ويفضل في لوب التحديث.
            'app_apk_url' => is_file(public_path(self::APK_PATH))
                ? url(self::APK_PATH).'?v='.filemtime(public_path(self::APK_PATH))
                : '',
        ]);

        return back()->with('ok', __('appver.saved'));
    }

    /**
     * ═══ رفع الـAPK بالقطع (١١ أغسطس ٢٠٢٦) ═══
     *
     * الرفع بريكوست واحد كان بيموت في النص (`ERR_HTTP2_PING_FAILED`):
     * ملف ٥٠+ ميجا على اتصال مصري ورا بروكسي Cloudways بيتعدى مهلة
     * الـHTTP2 ping ولا حد عارف وصل ولا لأ. دلوقتي الجافاسكربت بيقطّع
     * الملف قطع ٤ ميجا — كل قطعة ريكوست سريع مستحيل يتعدى المهلة،
     * والشاشة بتعرض بار تقدم حقيقي (قطعة X من Y).
     *
     * البروتوكول: `POST /erp/app-version/chunk` بحقول:
     *   upload_id (معرّف الجلسة من المتصفح) · index · total · chunk (الملف)
     * القطع بتتجمع في `storage/app/apk-upload/{upload_id}.part` بالترتيب،
     * وآخر قطعة بتنقل الملف لـ`public/app/promax.apk` وبتحدّث الرابط.
     *
     * ⚠️ **القطع لازم تيجي بالترتيب** (المتصفح بيبعتها واحدة واحدة) —
     * قطعة برقم غير المتوقع = الجلسة بايظة وبنرفض عشان مانجمّعش APK
     * مشوّه ويتوزع على المناديب.
     */
    public function uploadChunk(Request $request)
    {
        $data = $request->validate([
            'upload_id' => ['required', 'regex:/^[a-zA-Z0-9_-]{8,64}$/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:200'],
            // ٤ ميجا للقطعة + هامش — أي PHP config بيقبلها
            'chunk' => ['required', 'file', 'max:6144'],
        ]);

        $dir = storage_path('app/apk-upload');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $part = $dir.'/'.$data['upload_id'].'.part';
        $meta = $dir.'/'.$data['upload_id'].'.next';

        // أول قطعة بتبدأ ملف جديد — وأي بقايا قديمة بتتداس
        $expected = (int) $data['index'] === 0
            ? 0
            : (is_file($meta) ? (int) file_get_contents($meta) : -1);

        if ((int) $data['index'] !== $expected) {
            @unlink($part);
            @unlink($meta);

            return response()->json(['message' => __('appver.chunk_out_of_order')], 422);
        }

        $bytes = file_get_contents($request->file('chunk')->getRealPath());
        file_put_contents($part, $bytes, $data['index'] === 0 ? 0 : FILE_APPEND);
        file_put_contents($meta, (string) ($data['index'] + 1));

        // مش آخر قطعة؟ خلصنا هنا
        if ((int) $data['index'] + 1 < (int) $data['total']) {
            return response()->json(['ok' => true, 'received' => $data['index'] + 1]);
        }

        // ═══ آخر قطعة — التجميع خلص، ننقل للمكان النهائي ═══
        $pubDir = public_path('app');
        if (! is_dir($pubDir)) {
            mkdir($pubDir, 0755, true);
        }

        // فحص بدائي إن ده APK فعلاً (ZIP بيبدأ بـ PK) — مش أمان،
        // حماية من ملف اترفع غلط ويتوزع على كل التليفونات
        $head = file_get_contents($part, false, null, 0, 2);
        if ($head !== 'PK') {
            @unlink($part);
            @unlink($meta);

            return response()->json(['message' => __('appver.not_an_apk')], 422);
        }

        rename($part, public_path(self::APK_PATH));
        @unlink($meta);

        // ⚠️ نفس قاعدة الكاش-باستينج بتاعت save (٦/٩): وقت الملف مش
        // رقم الإصدار — كل رفع = URL جديد، فمفيش كاش يقدر يسرّف القديم
        Setting::write('app_apk_url',
            url(self::APK_PATH).'?v='.filemtime(public_path(self::APK_PATH)));

        return response()->json([
            'ok' => true,
            'done' => true,
            'size' => filesize(public_path(self::APK_PATH)),
        ]);
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
