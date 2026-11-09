<?php

namespace App\Support;

/**
 * كتالوج مرجعي لكل مفتاح صلاحية (قسم / صفحة / شاشة / زرار): وصف بسيط
 * + مستوى خطورة. المصفوفة (٩/١١) بتعرضه جنب كل تاجل عشان الأدمن يفهم
 * هو بيدّي إيه. مفيش أي سلوك وقت التشغيل هنا — قراءة بس.
 */
class PermissionCatalog
{
    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    public const CRITICAL = 'critical';

    public const LEVELS = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];

    /**
     * استثناءات الخطورة عن الافتراضي (قسم/صفحة/شاشة = medium، زرار = high).
     * critical = مسح، تسعير بأثر رجعي، GL، مرتبات، فريق/باسوردات، استيراد،
     * وأي حاجة بتحرك فلوس/مخزون بالجملة. low = شغل اليوزر على نفسه.
     *
     * @var array<string, string>
     */
    public const RISK = [
        // critical
        'act.overview.wipe' => self::CRITICAL,
        'act.overview.demo' => self::CRITICAL,
        'act.prices.propagate' => self::CRITICAL,
        'act.gl.admin' => self::CRITICAL,
        'act.costing.admin' => self::CRITICAL,
        'act.hr.payroll_approve' => self::CRITICAL,
        'act.hr.settings' => self::CRITICAL,
        'act.team.manage' => self::CRITICAL,
        'act.import.run' => self::CRITICAL,
        'act.custody.adjust' => self::CRITICAL,
        // إضافات: مسح/حذف/إعادة تسعير/صرف فلوس بالجملة
        'act.field.wipe' => self::CRITICAL,
        'act.products.delete' => self::CRITICAL,
        'act.clients.delete' => self::CRITICAL,
        'act.invoices.delete' => self::CRITICAL,
        'act.invoices.edit' => self::CRITICAL,
        'act.returns.delete' => self::CRITICAL,
        'act.repclose.delete' => self::CRITICAL,
        'act.custody.clear' => self::CRITICAL,
        'act.ka.reprice' => self::CRITICAL,
        'act.review.stock_reset' => self::CRITICAL,
        'act.online.sync_settings' => self::CRITICAL,
        'act.hr.payroll_pay' => self::CRITICAL,
        // low
        'nav.group_docs' => self::LOW,
        'docs.index' => self::LOW,
        'docs.file' => self::LOW,
        'notifications' => self::LOW,
        'attendance.punch' => self::LOW,
        'hr.leave' => self::LOW,
        'hr.permissions' => self::LOW,
    ];

    /** @var list<string>|null */
    private static ?array $keys = null;

    /** @var array<string, string> شاشة ⇒ صفحتها */
    private static array $pageOfScreen = [];

    /** @var list<string>|null */
    private static ?array $pages = null;

    /** @return list<string> كل الصفحات (NAV + العامة) */
    public static function pages(): array
    {
        if (self::$pages === null) {
            $pages = Access::PUBLIC_PAGES;

            foreach (Access::NAV as $links) {
                foreach ($links as $link) {
                    $pages[] = $link[0];
                }
            }

            self::$pages = array_values(array_unique($pages));
        }

        return self::$pages;
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return array_merge(array_keys(Access::NAV), [Access::PUBLIC_GROUP]);
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(Access::ACTIONS);
    }

    /** @return list<string> قسم، صفحة، شاشة، زرار */
    public static function keys(): array
    {
        if (self::$keys === null) {
            $keys = self::groups();

            foreach (self::pages() as $page) {
                $keys[] = $page;

                foreach (Access::screensOf($page) as $screen) {
                    $keys[] = $screen;
                    self::$pageOfScreen[$screen] = $page;
                }
            }

            self::$keys = array_values(array_unique(array_merge($keys, self::actions())));
        }

        return self::$keys;
    }

    /** صفحة الشاشة (أو null لو المفتاح مش شاشة) */
    public static function pageOf(string $key): ?string
    {
        self::keys();

        return self::$pageOfScreen[$key] ?? null;
    }

    /** group | page | screen | action */
    public static function kind(string $key): string
    {
        if (isset(Access::ACTIONS[$key])) {
            return 'action';
        }

        if (in_array($key, self::groups(), true)) {
            return 'group';
        }

        return in_array($key, self::pages(), true) ? 'page' : 'screen';
    }

    public static function risk(string $key): string
    {
        if (isset(self::RISK[$key])) {
            return self::RISK[$key];
        }

        // المقفولين (perms/roles/worktime/agent) دايماً critical
        if (Access::isLocked($key)) {
            return self::CRITICAL;
        }

        return self::kind($key) === 'action' ? self::HIGH : self::MEDIUM;
    }

    public static function riskLabel(string $risk): string
    {
        return __('permref.risk_'.(in_array($risk, self::LEVELS, true) ? $risk : self::MEDIUM));
    }

    /** مفتاح اللانج: النقط ⇒ underscore */
    public static function langKey(string $key): string
    {
        return 'permref.'.str_replace('.', '_', $key);
    }

    /**
     * @return array{what:string, data:?string}
     */
    public static function describe(string $key): array
    {
        $entry = trans(self::langKey($key));

        if (! is_array($entry) && ($page = self::pageOf($key)) !== null) {
            $entry = trans(self::langKey($page));
        }

        if (! is_array($entry)) {
            return ['what' => $key, 'data' => null];
        }

        return ['what' => (string) ($entry['what'] ?? $key), 'data' => $entry['data'] ?? null];
    }
}
