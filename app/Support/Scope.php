<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * الحارس المشترك — «مين مسموح له يشتغل على مين»
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه الكلاس ده موجود؟** فحص التدقيق (٨ أغسطس ٢٠٢٦) لقى ٩ شاشات
 * بتاخد علاقة (مندوب / عميل / منطقة) من الريكوست من غير ما السيرفر
 * يتأكد منها — سبعة منهم نفس التلات سطور الناقصة. `exists:users,id`
 * بتقول «اليوزر ده موجود» بس؛ ماتقولش إنه ميداني، ولا أكتيف، ولا تحت
 * نفس المدير، ولا في نفس الفرع. الحارس ده هو المكان الوحيد اللي
 * الإجابات دي بتتكتب فيه.
 *
 * ⚠️ **القاعدة: ندهه جوّه الترانزاكشن أو قبلها مباشرة، مش في الفيو.**
 * فلترة القايمة بتخبّي الصف عن العين مش عن الراوت — أي حد بيعرف
 * الـid بيوصله بـPOST.
 *
 * ⚠️ **ممنوع تخفّف الحارس عشان شاشة**. لو شاشة محتاجة تسكين أوسع،
 * الحل إن الفاعل يبقى `admin` أو العلاقة تتصلّح في الداتا — مش إن
 * الفحص يتشال.
 *
 * كل الميثودز بترمي `403` عن طريق `abort()` أو `Rejected` (422) —
 * مفيش واحدة بترجع bool وتسيب الكنترولر يقرر، عشان النسيان
 * مايبقاش ممكن.
 */
class Scope
{
    // ═══════════════════════ الموظف الميداني ═══════════════════════

    /**
     * هل الفاعل مسموح له يشتغل على الموظف الميداني ده؟
     *
     * الشروط بالترتيب:
     * 1. الموظف موجود، **أكتيف**، ورول ميداني (`User::FIELD_ROLES`)
     *    — **أو تشانل مانجر شغّال على نفسه** (شوف تحت)
     * 2. الفاعل شايف فرعه (`canSeeBranch`)
     * 3. لو الفاعل تشانل مانجر → الموظف متسكّن له (`manager_id`)
     *
     * ⚠️ الأدمن بيعدّي من ٣، بس **مش** من ١ — تحميل عهدة على محاسب
     * غلط حتى لو الأدمن هو اللي عمله.
     *
     * ═══ المدير بقى ميداني كمان (قرار المالك ١١ أغسطس ٢٠٢٦) ═══
     * التشانل مانجر بينزل الشارع: زيارات وعهدة وأوامر توريد وخط سير.
     * **الهدف المدير مقبول لما الفاعل هو نفسه** (`actor->id === rep->id`)
     * **أو لما الفاعل أدمن** — يعني المدير بينظّم شغله هو، والأدمن
     * يقدر يسكّن عليه. **مدير تاني لأ**: مدير ماينفعش يتسكّن له شغل
     * من زميله — دي نفس قاعدة الفريق بالظبط.
     */
    public static function canRep(?User $actor, ?User $rep): bool
    {
        if ($rep === null || $actor === null) {
            return false;
        }

        if (! $rep->active) {
            return false;
        }

        if (! in_array($rep->role, User::FIELD_ROLES, true)) {
            // المدير الميداني (١١/٨): على نفسه، أو الأدمن عليه — وبس.
            if ($rep->role !== 'manager') {
                return false;
            }

            if ($actor->id !== $rep->id && ! $actor->isAdmin()) {
                return false;
            }
        }

        if (! $actor->canSeeBranch($rep->branch_id)) {
            return false;
        }

        // ⚠️ `$actor->id !== $rep->id` — المدير على نفسه بيعدّي:
        // `manager_id` بتاعه هو null غالباً، والفحص ده عن فريقه مش عنه.
        if ($actor->role === 'manager' && $actor->id !== $rep->id
            && (int) $rep->manager_id !== (int) $actor->id) {
            return false;
        }

        return true;
    }

    /**
     * نفس `canRep` بس بترمي 403.
     *
     * لو بعتّ `$client` كمان، بيتفحص إنه مرئي للفاعل وإن العميل
     * والمندوب تحت **نفس المدير** — دي المرساة اللي كانت ناقصة في
     * إنشاء أوامر التوريد وتسكينها.
     */
    public static function assertRep(?User $actor, ?User $rep, ?Client $client = null): void
    {
        abort_unless(self::canRep($actor, $rep), 403, __('perm.scope_rep_denied'));

        if ($client !== null) {
            self::assertClient($actor, $client);
            self::assertSameTeam($rep, $client);
        }
    }

    // ═══════════════════════════ العميل ═══════════════════════════

    /**
     * هل الفاعل شايف العميل ده؟ — `visibleBy` (سكوب المدير) + سكوب الفرع.
     *
     * ⚠️ الاتنين مطلوبين مع بعض. `visibleBy` لوحدها بتعدّي مدير فرع
     * على عميل فرع تاني، و`canSeeBranch` لوحدها بتعدّي تشانل مانجر
     * على عميل مدير تاني في نفس الفرع.
     */
    public static function canClient(?User $actor, ?Client $client): bool
    {
        if ($client === null || $actor === null) {
            return false;
        }

        return $client->visibleBy($actor) && $actor->canSeeBranch($client->branch_id);
    }

    public static function assertClient(?User $actor, ?Client $client): void
    {
        abort_unless(self::canClient($actor, $client), 403, __('perm.scope_client_denied'));
    }

    // ═══════════════════════ اتساق العلاقة ═══════════════════════

    /**
     * العميل والمندوب تحت نفس المدير وفي نفس الفرع؟
     *
     * ⚠️ **الفحص بيتخطى لو حد من الاتنين مالوش مدير/فرع** (`null`).
     * ده مقصود: الداتا التاريخية فيها عملاء بلا `manager_id`، والفحص
     * الصارم كان هيقفل تسكين شرعي. اللي بيتمنع هو التعارض الصريح:
     * عميل مدير «أ» على مندوب مدير «ب».
     *
     * ⚠️ **مش بنفحص `rep_id`.** السواق بيوصّل أوامر لعملاء مش
     * مسكّنين عليه — ده الفلو الطبيعي لأوامر التوريد.
     */
    public static function sameTeam(?User $rep, ?Client $client): bool
    {
        if ($rep === null || $client === null) {
            return false;
        }

        if ($rep->manager_id !== null && $client->manager_id !== null
            && (int) $rep->manager_id !== (int) $client->manager_id) {
            return false;
        }

        if ($rep->branch_id !== null && $client->branch_id !== null
            && (int) $rep->branch_id !== (int) $client->branch_id) {
            return false;
        }

        return true;
    }

    public static function assertSameTeam(?User $rep, ?Client $client): void
    {
        abort_unless(self::sameTeam($rep, $client), 403, __('perm.scope_team_denied'));
    }

    /**
     * العميل في منطقة المندوب؟ — لتسكين العملاء وخطط السير بس.
     *
     * ⚠️ **بيتخطى لو المندوب مالوش مناطق أو العميل مالوش زون.** تسكين
     * المناطق بيحصل في نفس الفورم بتاع تسكين العملاء، فالفحص الصارم
     * كان هيقفل أول تسكين لمندوب لسه مالوش مناطق.
     *
     * @param  array<int>|null  $extraZoneIds  مناطق بتتسكّن في نفس الريكوست
     */
    public static function inZone(?User $rep, ?Client $client, ?array $extraZoneIds = null): bool
    {
        if ($rep === null || $client === null) {
            return false;
        }

        if ($client->zone_id === null) {
            return true;
        }

        $zoneIds = $rep->zones->pluck('id')->all();

        if ($rep->zone_id !== null) {
            $zoneIds[] = (int) $rep->zone_id;
        }

        foreach ($extraZoneIds ?? [] as $z) {
            $zoneIds[] = (int) $z;
        }

        if ($zoneIds === []) {
            return true;   // مندوب لسه مالوش مناطق — أول تسكين
        }

        return in_array((int) $client->zone_id, array_map('intval', $zoneIds), true);
    }

    /**
     * @param  array<int>|null  $extraZoneIds
     */
    public static function assertInZone(?User $rep, ?Client $client, ?array $extraZoneIds = null): void
    {
        abort_unless(self::inZone($rep, $client, $extraZoneIds), 422, __('perm.scope_zone_denied'));
    }

    // ═══════════════════════ فريق الميدان ═══════════════════════

    /**
     * هل الفاعل مسؤول عن الموظف ده **أياً كان رولّه**؟ — للحضور
     * والنقاط وقفل اليومية، اللي بتلمس موظفين مش ميدانيين كمان.
     *
     * زي `canRep` بالظبط ناقص شرط الرول الميداني.
     */
    public static function canStaff(?User $actor, ?User $staff): bool
    {
        if ($staff === null || $actor === null) {
            return false;
        }

        if (! $actor->canSeeBranch($staff->branch_id)) {
            return false;
        }

        if ($actor->role === 'manager' && (int) $staff->manager_id !== (int) $actor->id) {
            return false;
        }

        return true;
    }

    public static function assertStaff(?User $actor, ?User $staff): void
    {
        abort_unless(self::canStaff($actor, $staff), 403, __('perm.scope_staff_denied'));
    }
}
