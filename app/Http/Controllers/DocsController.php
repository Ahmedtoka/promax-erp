<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════
 * «الدليل» — موقع التدريب والتسليم ورا اللوجين (٢١/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * بيخدم ملفات ساكنة من `config('docs.path')` (افتراضي `storage/app/docs`).
 * مفيش داتابيز ولا فيو غير صفحة «لسه ما اترفعش».
 *
 * ⚠️ **`/docs` بيحوّل لـ`/docs/index.html` مش `/docs/`.** الموقع كله
 * مسارات نسبية (`assets/app.css`)، ولارافيل + htaccess بيشيلوا السلاش
 * الأخيرة — فـ`/docs/` بيرجع `/docs` والمسارات تتحل على `/assets/...`
 * بره الدليل. و`<base href>` مش حل: لينكات `#/screens` بتتحل على مسار
 * الـbase فالصفحة كانت هتتحمّل من الأول مع كل ضغطة.
 * الهاش بيعدّي مع التحويل، فـ`/docs#/screen/S041` بيفتح الشاشة نفسها
 * (جاهز لزرار المساعدة بتاع كل شاشة بعدين).
 *
 * ⚠️ **حارس الخروج من الفولدر:** أي مقطع `..` أو `.` أو مخفي أو `\`
 * أو `:` أو بايت صفر = 404، وبعدين `realpath` لازم يفضل جوه الجذر.
 * والامتدادات قايمة بيضا — `.php` و`.env` و`.json` عمرهم ما يتخدموا.
 */
class DocsController extends Controller
{
    /** الامتدادات المسموحة ونوعها — اللي مش هنا 404 */
    private const TYPES = [
        'html' => 'text/html; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'pdf' => 'application/pdf',
        'webm' => 'video/webm',
        'mp4' => 'video/mp4',
    ];

    /**
     * الصفحة والسكربتات والداتا بتتراجع كل مرة (304 لو ماتغيرتش) — عشان
     * استبدال الفولدر يبان فوراً. الصور والخطوط والفيديو أسبوع.
     * ⚠️ `private` دايماً: ورا اللوجين، ممنوع أي كاش مشترك يحتفظ بيها.
     */
    private const REVALIDATE = ['html', 'js', 'css', 'txt'];

    public function index(): Response
    {
        if (! is_file((self::root() ?? '').DIRECTORY_SEPARATOR.'index.html')) {
            return $this->missing();
        }

        return redirect()->route('docs.file', ['path' => 'index.html']);
    }

    public function file(Request $request, string $path): Response
    {
        $root = self::root();

        if ($root === null) {
            // الفولدر نفسه مش موجود — الصفحة الرئيسية بتقول كده بأدب، والباقي 404
            return $path === 'index.html' ? $this->missing() : abort(404);
        }

        $full = self::resolve($root, $path);

        if ($full === null) {
            abort(404);
        }

        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));

        $response = new BinaryFileResponse($full, 200, [
            'Content-Type' => self::TYPES[$ext],
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => in_array($ext, self::REVALIDATE, true)
                ? 'private, no-cache'
                : 'private, max-age=604800',
        ], false, null, false, true);   // ⚠️ public=false — وإلا Symfony بيكتب public فوق private

        $response->isNotModified($request);

        return $response;
    }

    /** جذر الدليل الحقيقي، أو null لو لسه ما اترفعش */
    public static function root(): ?string
    {
        $root = realpath((string) config('docs.path'));

        return $root !== false && is_dir($root) ? $root : null;
    }

    /** المسار الكامل لملف مسموح جوه الجذر — أو null */
    public static function resolve(string $root, string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':')) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            // فاضي (`//` أو `/` في الأول) أو `.`/`..` أو ملف مخفي (`.env`، `.git`)
            if ($segment === '' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! isset(self::TYPES[$ext])) {
            return null;
        }

        $full = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));

        if ($full === false || ! is_file($full)
            || ! str_starts_with($full, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return null;
        }

        // الامتداد الحقيقي بعد الـrealpath (لينك رمزي لملف تاني مثلاً)
        return isset(self::TYPES[strtolower(pathinfo($full, PATHINFO_EXTENSION))]) ? $full : null;
    }

    private function missing(): Response
    {
        return response()->view('docs.missing', [
            'isAdmin' => (bool) auth()->user()?->isAdmin(),
        ]);
    }
}
