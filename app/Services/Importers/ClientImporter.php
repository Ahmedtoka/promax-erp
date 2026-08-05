<?php

namespace App\Services\Importers;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Transaction;
use App\Models\Zone;
use App\Services\Sheet;
use Illuminate\Support\Facades\DB;

/**
 * استيراد العملاء بأرصدتهم الافتتاحية.
 *
 * ⚠️ الرصيد الافتتاحي **بيتقيّد كحركة** في كشف الحساب، مش بيتكتب في
 * عمود الرصيد مباشرة. لأن `transactions` هو مصدر الحقيقة الوحيد
 * للفلوس، وأي رصيد اتكتب من بره بيخلّي كشف الحساب مش مطابق للرصيد
 * ومحدش يعرف السبب.
 *
 * ⚠️ القناة والزون والسلسلة بتتربط **بالاسم**. لو الاسم مش موجود،
 * بنعمله — أحسن من إننا نرفض الصف أو نسيبه من غير قناة (وساعتها
 * خصم القناة مابيشتغلش عليه).
 */
class ClientImporter extends Importer
{
    public function kind(): string
    {
        return 'clients';
    }

    public function columns(): array
    {
        return [
            'name' => ['اسم العميل', 'name', 'العميل', 'client'],
            'name_en' => ['الاسم الإنجليزي', 'name_en', 'english name'],
            'code' => ['كود العميل', 'code', 'الكود'],
            'phone' => ['التليفون', 'phone', 'الموبايل'],
            'address' => ['العنوان', 'address'],
            'channel' => ['القناة', 'channel'],
            'sub_channel' => ['نوع القناة', 'sub_channel'],
            'zone' => ['المنطقة', 'zone', 'الزون'],
            'group' => ['السلسلة', 'group', 'chain'],
            'category' => ['التصنيف', 'category'],
            'discount' => ['نسبة الخصم', 'discount', 'الخصم'],
            'price_list' => ['قائمة السعر', 'price_list'],
            'payment_days' => ['أجل السداد', 'payment_days', 'credit days'],
            'lat' => ['خط العرض', 'lat', 'latitude'],
            'lng' => ['خط الطول', 'lng', 'longitude'],
            'status' => ['الحالة', 'status'],
            'opening_balance' => ['الرصيد الافتتاحي', 'opening_balance', 'opening'],
            'opening_date' => ['تاريخ الرصيد', 'opening_date'],
            'tax_id' => ['الرقم الضريبي', 'tax_id'],
            // إضافات 2026-08-05 — شيتات محمد حجر المنظمة
            'governorate' => ['المحافظة', 'governorate'],
            'manager' => ['الأكونت مانجر', 'مدير الحساب', 'manager', 'account manager'],
            'contact' => ['اسم المسؤول', 'الكونتاكت', 'contact', 'اسم المسؤول / الكونتاكت'],
            'phone2' => ['أرقام إضافية', 'phone2', 'تليفون إضافي'],
        ];
    }

    public function required(): array
    {
        return ['name'];
    }

    public function validateRow(array $row, int $line): array
    {
        $out = [];

        if (($row['name'] ?? null) === null) {
            $out[] = __('import.name_required');
        }

        foreach (['discount', 'opening_balance', 'lat', 'lng', 'payment_days'] as $f) {
            $v = $row[$f] ?? null;
            if ($v !== null && Sheet::number($v) === null) {
                $out[] = __('import.not_a_number', ['column' => $f, 'value' => $v]);
            }
        }

        // ⚠️ الخصم بيتكتب في الشيت كنسبة (15) وبيتخزن ككسر (0.15).
        // لو حد كتب 0.15 في الشيت، هيبقى 0.15% — رفض أوضح من تخمين.
        $disc = Sheet::number($row['discount'] ?? null);
        if ($disc !== null && ($disc < 0 || $disc > 100)) {
            $out[] = __('import.discount_range', ['value' => $disc]);
        }

        $pl = $row['price_list'] ?? null;
        if ($pl !== null && ! in_array($pl, ['old', 'new', 'قديم', 'جديد'], true)) {
            $out[] = __('import.unknown_price_list', ['value' => $pl]);
        }

        $cat = $row['category'] ?? null;
        if ($cat !== null && $this->category($cat) === null) {
            $out[] = __('import.unknown_category', [
                'value' => $cat,
                'allowed' => implode(', ', array_keys(Client::CATEGORIES)),
            ]);
        }

        $sub = $row['sub_channel'] ?? null;
        if ($sub !== null && $this->subChannel($sub) === null) {
            $out[] = __('import.unknown_sub_channel', ['value' => $sub]);
        }

        // ⚠️ رصيد افتتاحي من غير تاريخ بيتسجّل بتاريخ النهارده، فكل
        // المديونية التاريخية بتبان جديدة وأعمار الديون بتطلع سليمة
        // وهي مش سليمة. التاريخ إجباري مع أي رصيد.
        $bal = Sheet::number($row['opening_balance'] ?? null);
        if ($bal !== null && abs($bal) >= 0.01) {
            $d = $row['opening_date'] ?? null;
            if ($d === null) {
                $out[] = __('import.opening_date_required');
            } elseif (Sheet::date($d) === null) {
                $out[] = __('import.bad_date', ['column' => 'opening_date', 'value' => $d]);
            }
        }

        return $out;
    }

    public function apply(array $rows): array
    {
        $created = $updated = $opened = 0;
        $channels = $zones = $groups = [];

        DB::transaction(function () use ($rows, &$created, &$updated, &$opened, &$channels, &$zones, &$groups) {
            foreach ($rows as $row) {
                $name = (string) $row['name'];
                $code = $row['code'] ?? null;

                // ⚠️ المطابقة بالكود لو موجود، وإلا بالاسم. الكود أدق —
                // العميل ممكن يتغير اسمه بس كوده بيفضل.
                $existing = $code
                    ? Client::where('code', $code)->first()
                    : Client::where('name', $name)->first();

                $channelId = $this->channelId($row['channel'] ?? null, $channels);
                $zoneId = $this->zoneId($row['zone'] ?? null, $zones);
                $groupId = $this->groupId($row['group'] ?? null, $groups);

                $discount = Sheet::number($row['discount'] ?? null);

                // ⚠️ إنشاء أو تحديث صريح. `updateOrCreate(['id' => null])`
                // بيشتغل صح بالصدفة (لأن id مش في fillable فبيتشال بصمت)،
                // وأي إضافة لـ id في fillable بعدين بتكسّرها من غير تحذير.
                $payload = [
                    'code' => $code ?: ($existing?->code ?? Client::nextCode()),
                    'name' => $name,
                    'name_en' => $row['name_en'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'address' => $row['address'] ?? null,
                    'channel_id' => $channelId,
                    'sub_channel' => $this->subChannel($row['sub_channel'] ?? null),
                    'zone_id' => $zoneId,
                    'group_id' => $groupId,
                    'category' => $this->category($row['category'] ?? null) ?? 'ok',
                    'price_list' => $this->priceList($row['price_list'] ?? null),
                    'lat' => Sheet::number($row['lat'] ?? null),
                    'lng' => Sheet::number($row['lng'] ?? null),
                    // ⚠️ وجود رقم ضريبي معناه العميل مسجّل، فبيبقى خاضع.
                    // ده أدق من إن اليوزر يفتكر يعلّم خانة تانية.
                    'tax_id' => $row['tax_id'] ?? null,
                    'taxable' => ($row['tax_id'] ?? null) !== null,
                    // ⚠️ **الجديد بينزل pending مش active** (قرار المالك
                    // 2026-08-04): العميل المستورد لازم يعدي على شاشة
                    // «تفعيل العملاء» ويتراجع قبل ما يظهر للمناديب.
                    // الموجود بيحتفظ بحالته زي ما هي — الاستيراد
                    // مايوقفش عميل شغال. وعمود «الحالة» في الشيت
                    // بيتحكم صراحةً لو حد عاوز يدخّل active على طول.
                    'status' => $this->status($row['status'] ?? null)
                        ?? $existing?->status
                        ?? 'pending',
                ];

                // ⚠️ **الخصم بيتكتب بس لو موجود في الشيت** (إصلاح 2026-08-05).
                // كان دايماً في الـpayload بـ(discount ?? 0) — يعني شيت من
                // غير عمود خصم كان بيصفّر خصومات العملاء الموجودين في صمت.
                if ($discount !== null) {
                    // الشيت بالنسبة، الداتابيز بالكسر — القسمة مرة واحدة
                    $payload['discount'] = $discount / 100;
                    $payload['uses_channel_discount'] = $discount <= 0;
                }

                // ⚠️ ونفس المبدأ للإضافات — الفاضي مايمسحش الموجود
                if (($gov = $this->governorate($row['governorate'] ?? null)) !== null) {
                    $payload['governorate'] = $gov;
                }

                if (($managerId = $this->managerId($row['manager'] ?? null)) !== null) {
                    $payload['manager_id'] = $managerId;
                }

                $contact = trim((string) ($row['contact'] ?? ''));
                $phone2 = trim((string) ($row['phone2'] ?? ''));

                if ($contact !== '' || $phone2 !== '') {
                    $payload['contacts'] = [[
                        'name' => $contact !== '' ? $contact : null,
                        'role' => null,
                        'phone' => $phone2 !== '' ? $phone2 : null,
                    ]];
                }

                if ($existing) {
                    $existing->update($payload);
                    $client = $existing;
                    $updated++;
                } else {
                    // الجديد من غير عمود خصم بياخد صفر وبيرجع لخصم القناة
                    $client = Client::create($payload + [
                        'discount' => ($discount ?? 0) / 100,
                        'uses_channel_discount' => ($discount ?? 0) <= 0,
                    ]);
                    $created++;
                }

                if ($this->openBalance($client, $row)) {
                    $opened++;
                }
            }
        });

        return [
            'created' => $created, 'updated' => $updated, 'opening' => $opened,
            'channels' => count($channels), 'zones' => count($zones), 'groups' => count($groups),
        ];
    }

    /** المحافظة من مفتاحها أو اسمها العربي/الإنجليزي — null لو مش معروفة */
    private function governorate(?string $v): ?string
    {
        return \App\Support\Governorates::match($v);
    }

    /**
     * التشانل مانجر بالاسم (عربي/إنجليزي) أو الكود.
     *
     * ⚠️ رول `manager` بس — الشيت مايقدرش يسكّن عميل على مندوب أو أدمن.
     */
    private function managerId(?string $v): ?int
    {
        $v = trim((string) $v);

        if ($v === '') {
            return null;
        }

        return $this->managerCache[$v] ??= \App\Models\User::where('role', 'manager')
            ->where(fn ($q) => $q->where('name', 'like', "%$v%")
                ->orWhere('name_en', 'like', "%$v%")
                ->orWhere('code', $v))
            ->value('id');
    }

    /** @var array<string, ?int> */
    private array $managerCache = [];

    /** حالة صريحة من الشيت — null يعني الافتراضي (pending للجديد) */
    private function status(?string $v): ?string
    {
        return $this->toKey($v, ['active' => 'active', 'pending' => 'pending'], [
            'نشط' => 'active', 'مفعل' => 'active', 'مفعّل' => 'active',
            'مستني' => 'pending', 'غير مفعل' => 'pending',
        ]);
    }

    /** التصنيف من المفتاح أو من اسمه العربي */
    private function category(?string $v): ?string
    {
        return $this->toKey($v, Client::CATEGORIES);
    }

    private function subChannel(?string $v): ?string
    {
        return $this->toKey($v, ['chain' => 'chain', 'convenience' => 'convenience'], [
            'سلسلة' => 'chain', 'سلاسل' => 'chain',
            'كونفينيانس' => 'convenience', 'ملاءمة' => 'convenience',
        ]);
    }

    /**
     * الرصيد الافتتاحي كحركة في كشف الحساب.
     *
     * ⚠️ مدين = العميل مدين لنا. الرصيد السالب في الشيت معناه رصيد
     * دائن (دافع مقدماً)، فبيتقيّد في الخانة التانية.
     */
    private function openBalance(Client $client, array $row): bool
    {
        $amount = Sheet::number($row['opening_balance'] ?? null);

        if ($amount === null || abs($amount) < 0.01) {
            return false;
        }

        // التحقق ضمن إن التاريخ موجود وصحيح قبل ما نوصل هنا
        $date = Sheet::date($row['opening_date'] ?? null)?->format('Y-m-d');

        // ⚠️ **الاستيراد وشاشة العميل بيستخدموا نفس الدالة.** لما كان
        // فيه نسختين من المنطق، واحدة بتمسح القيد القديم والتانية لأ،
        // والرصيد الافتتاحي كان بيتحسب مرتين لأي عميل اتعمله الاتنين.
        $client->setOpeningBalance($amount, $date);

        return true;
    }

    private function priceList(?string $v): string
    {
        return match ($v) {
            'old', 'قديم' => 'old',
            default => 'new',
        };
    }

    private function channelId(?string $name, array &$cache): ?int
    {
        if ($name === null) {
            return null;
        }
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        // بالكود أو بالاسم — الشيتات بتكتب الاتنين
        $c = Channel::where('code', $name)->orWhere('name', $name)->orWhere('name_en', $name)->first();

        return $cache[$name] = $c?->id;
    }

    private function zoneId(?string $name, array &$cache): ?int
    {
        if ($name === null) {
            return null;
        }
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        // ⚠️ الكود من `Zone::nextCode()` مش من العدّ — العدّ وقع على
        // «Duplicate Z50» ورجّع استيراد كامل (2026-08-04)
        $z = Zone::where('name', $name)->orWhere('code', $name)->orWhere('name_en', $name)->first()
            ?? Zone::create([
                'code' => Zone::nextCode(),
                'name' => $name,
                'active' => true,
            ]);

        return $cache[$name] = $z->id;
    }

    private function groupId(?string $name, array &$cache): ?int
    {
        if ($name === null) {
            return null;
        }
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $g = ClientGroup::where('name', $name)->orWhere('name_en', $name)->first()
            ?? ClientGroup::create([
                'code' => ClientGroup::nextCode($name),
                'name' => $name,
                'active' => true,
            ]);

        return $cache[$name] = $g->id;
    }
}
