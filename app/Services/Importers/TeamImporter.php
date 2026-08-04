<?php

namespace App\Services\Importers;

use App\Models\Channel;
use App\Models\User;
use App\Models\Zone;
use App\Services\Sheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * استيراد الفريق والمناطق.
 *
 * ⚠️ الباسوردات: الشيت بيجيب باسورد مبدئي أو بنولّد واحد. الاستيراد
 * **مابيغيّرش** باسورد مستخدم موجود — لو حد غيّر باسورده من الأبلكيشن،
 * إعادة الاستيراد مالازمش ترجّعه للمبدئي وتقفل عليه.
 *
 * ⚠️ الأدمن اللي شغّال دلوقتي محمي: الاستيراد مايقدرش يوقّفه أو يغيّر
 * روله — وإلا ممكن يقفل السيستم على نفسه بشيت غلط.
 */
class TeamImporter extends Importer
{
    public function kind(): string
    {
        return 'team';
    }

    public function columns(): array
    {
        return [
            'name' => ['الاسم', 'name', 'اسم الموظف'],
            'name_en' => ['الاسم الإنجليزي', 'name_en', 'english name'],
            'email' => ['الإيميل', 'email'],
            'code' => ['الكود', 'code'],
            'role' => ['الوظيفة', 'role', 'الرول'],
            'phone' => ['التليفون', 'phone', 'الموبايل'],
            'zone' => ['المنطقة', 'zone', 'الزون'],
            'channel' => ['القناة', 'channel'],
            'password' => ['الباسورد', 'password'],
            'zones' => ['المناطق المسؤول عنها', 'zones'],
            'day' => ['يوم الزيارة', 'day', 'اليوم'],
        ];
    }

    public function required(): array
    {
        return ['name', 'role'];
    }

    public function validateRow(array $row, int $line): array
    {
        $out = [];

        if (($row['name'] ?? null) === null) {
            $out[] = __('import.name_required');
        }

        $role = $this->role($row['role'] ?? null);
        if ($role === null) {
            $out[] = __('import.unknown_role', [
                'value' => $row['role'] ?? '—',
                'allowed' => implode(', ', array_keys(User::ROLES)),
            ]);
        }

        $email = $row['email'] ?? null;
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[] = __('import.bad_email', ['value' => $email]);
        }

        // ⚠️ من غير إيميل مايقدرش يسجّل دخول. مسموح للبروموتر اللي
        // بيدخل بالكود، بس لازم يبقى قرار واعي مش نسيان.
        if ($email === null && in_array($role, ['admin', 'manager'], true)) {
            $out[] = __('import.email_required_for_role', ['role' => $role]);
        }

        return $out;
    }

    public function apply(array $rows): array
    {
        $created = $updated = $zonesMade = $linked = 0;
        $currentId = auth()->id();

        DB::transaction(function () use ($rows, &$created, &$updated, &$zonesMade, &$linked, $currentId) {
            $zoneCache = [];

            foreach ($rows as $row) {
                $email = $row['email'] ?? null;
                $code = $row['code'] ?? null;

                $existing = $email ? User::where('email', $email)->first() : null;
                $existing = $existing ?? ($code ? User::where('code', $code)->first() : null);

                // ⚠️ الأدمن الحالي مالوش تعديل من الاستيراد
                if ($existing && $existing->id === $currentId) {
                    continue;
                }

                $zoneId = $this->zoneId($row['zone'] ?? null, $row['day'] ?? null, $zoneCache, $zonesMade);
                $channel = ($row['channel'] ?? null)
                    ? Channel::where('code', $row['channel'])->orWhere('name', $row['channel'])->first()
                    : null;

                $payload = [
                    'name' => $row['name'],
                    // ⚠️ من غيره اسم الموظف بيفضل عربي في الواجهة الإنجليزية
                    'name_en' => $row['name_en'] ?? null,
                    'email' => $email,
                    'code' => $code ?: $this->nextCode((string) $this->role($row['role'])),
                    'role' => $this->role($row['role']),
                    'phone' => $row['phone'] ?? null,
                    'zone_id' => $zoneId,
                    'channel_id' => $channel?->id,
                    'active' => true,
                ];

                // الباسورد للجديد بس — الموجود بيحتفظ بباسورده
                if (! $existing) {
                    // ⚠️ **عشوائي مش `promax2026`.** الشيت اللي مافيهوش
                    // عمود باسورد كان بيعمل كل الحسابات بنفس الباسورد
                    // المعروف. اللي بيستورد بيظبط الباسوردات بعدها بـ
                    // `promax:password`، أو بيحطها في عمود في الشيت.
                    $payload['password'] = Hash::make(
                        $row['password'] ?? \App\Console\Commands\SetupTeam::newPassword()
                    );
                }

                $user = $existing
                    ? tap($existing)->update($payload)
                    : User::create($payload);

                $existing ? $updated++ : $created++;

                // مدير القنوات ممكن يبقى مسؤول عن أكتر من زون
                if (($row['zones'] ?? null) !== null) {
                    $ids = [];
                    foreach (preg_split('/[,،|]/u', $row['zones']) as $z) {
                        $z = trim($z);
                        if ($z === '') {
                            continue;
                        }
                        $id = $this->zoneId($z, null, $zoneCache, $zonesMade);
                        if ($id) {
                            $ids[] = $id;
                        }
                    }
                    $user->zones()->sync($ids);
                    $linked += count($ids);
                }
            }
        });

        return [
            'created' => $created, 'updated' => $updated,
            'zones' => $zonesMade, 'zone_links' => $linked,
        ];
    }

    /** الرول من المفتاح أو من اسمه العربي */
    private function role(?string $v): ?string
    {
        return $this->toKey($v, User::ROLES, [
            'مدير' => 'manager', 'مدير قناة' => 'manager',
            'مندوب' => 'sales_agent', 'سيلز' => 'sales_agent',
            'سواق' => 'driver', 'كورير' => 'driver',
            'مروّج' => 'promoter', 'مرتشندايزر' => 'promoter',
        ]);
    }

    private function nextCode(string $role): string
    {
        $prefix = match ($role) {
            'admin' => 'ADM', 'manager' => 'MGR', 'sales_agent' => 'SLS',
            'driver' => 'DRV', 'promoter' => 'PRM', default => 'USR',
        };

        $n = User::where('role', $role)->count() + 1;

        return $prefix.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    private function zoneId(?string $name, ?string $day, array &$cache, int &$made): ?int
    {
        if ($name === null) {
            return null;
        }
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $z = Zone::where('name', $name)->orWhere('code', $name)->orWhere('name_en', $name)->first();

        if ($z === null) {
            $z = Zone::create([
                // نفس إصلاح ClientImporter — العدّ بيقع على كود مكرر
                'code' => Zone::nextCode(),
                'name' => $name,
                'day_label' => $day,
                'active' => true,
            ]);
            $made++;
        } elseif ($day !== null && $z->day_label === null) {
            $z->update(['day_label' => $day]);
        }

        return $cache[$name] = $z->id;
    }
}
