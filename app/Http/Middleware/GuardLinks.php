<?php

namespace App\Http\Middleware;

use App\Support\LinkGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * اللينك اللي اليوزر مايقدرش يفتحه بيتحوّل نص عادي قبل ما الصفحة تخرج
 * (٢٢ سبتمبر ٢٠٢٦) — القاعدة والسبب في `App\Support\LinkGuard`.
 *
 * ⚠️ للأدمن مفيش شغل: كل حاجة مفتوحة له، فالصفحة بتعدّي زي ما هي.
 * ⚠️ HTML ناجح بس — التصدير والـJSON والتحويلات مالهمش لينكات.
 * ⚠️ لينكات السايدبار (`navlink`) متفلترة أصلاً من `Access::navFor`.
 * ⚠️ الصف اللي بيتداس (`onclick="location.href=…"`) بيتعامل زي اللينك.
 */
class GuardLinks
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user === null || $user->isAdmin() || $response->getStatusCode() !== 200
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $html = $response->getContent();

        if (! is_string($html) || $html === '') {
            return $response;
        }

        $out = rescue(function () use ($html, $user) {
            LinkGuard::reset();

            $html = preg_replace_callback(
                '~<a\b([^>]*?)\bhref="([^"#][^"]*)"([^>]*)>(.*?)</a>~s',
                function (array $m) use ($user) {
                    $attrs = $m[1].' '.$m[3];

                    if (str_contains($attrs, 'navlink') || LinkGuard::canOpen($user, $m[2])) {
                        return $m[0];
                    }

                    // الشكل (كارت، بادج) بيفضل — اللي بيتشال الفتح بس
                    $class = preg_match('~\bclass="([^"]*)"~', $attrs, $c) ? $c[1] : '';
                    $style = preg_match('~\bstyle="([^"]*)"~', $attrs, $s) ? ' style="'.$s[1].'"' : '';

                    return '<span class="'.trim($class.' nolink').'"'.$style.'>'.$m[4].'</span>';
                }, $html) ?? $html;

            return preg_replace_callback(
                '~\sonclick="(?:window\.)?location\.href\s*=\s*\'([^\']+)\'"~',
                fn (array $m) => LinkGuard::canOpen($user, $m[1]) ? $m[0] : ' data-nolink',
                $html) ?? $html;
        }, $html, false);

        // ⚠️ `setContent` بيدهس `original` (الفيو) — وأي حاجة بتقرا بيانات الفيو بعد كده بتلاقي نص
        $original = $response->original ?? null;
        $response->setContent($out);

        if ($original !== null) {
            $response->original = $original;
        }

        return $response;
    }
}
