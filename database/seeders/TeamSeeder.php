<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * فريق العمل: الأدمن، مديرين القنوات، السيلز إيجينت، السواقين، البروموترز
 * كلمة السر بتتولّد عشوائي وبتتطبع في آخر التشغيل
 */
class TeamSeeder extends Seeder
{
    private static ?string $pw = null;

    public function run(): void
    {
        $this->blockOnProduction();

        $z1 = Zone::where('code', 'Z1')->first();
        $z2 = Zone::where('code', 'Z2')->first();

        $ka = Channel::where('code', Channel::KEY_ACCOUNT)->first();
        $online = Channel::where('code', Channel::ONLINE)->first();
        $van = Channel::where('code', Channel::CASH_VAN)->first();
        $wholesale = Channel::where('code', Channel::WHOLESALE)->first();

        // [الاسم، الإيميل، الرول، الكود، الزون، القناة، القنوات اللي بيديرها]
        $team = [
            ['أدمن السيستم', 'admin@promax.local', 'admin', 'ADM-001', null, null, 'all'],

            // مدير مسئول عن كل القنوات
            ['حسام الدين', 'hossam@promax.local', 'manager', 'CHM-001', null, null, 'all'],
            // مدير الكي أكاونت بس
            ['ياسمين فؤاد', 'yasmin@promax.local', 'manager', 'CHM-002', null, null, [Channel::KEY_ACCOUNT]],
            // مدير الأونلاين والجملة
            ['طارق منير', 'tarek@promax.local', 'manager', 'CHM-003', null, null, [Channel::ONLINE, Channel::WHOLESALE]],

            // سيلز إيجينت — بيفتح أكاونتات وبيبيع
            ['أحمد محمود', 'ahmed@promax.local', 'sales_agent', 'SLS-014', $z1?->id, $van?->id, null],
            ['مريم سامي', 'mariam@promax.local', 'sales_agent', 'SLS-021', $z2?->id, $van?->id, null],

            // سواق — بيوصّل أوامر التوريد
            ['محمد سعيد', 'mohamed@promax.local', 'driver', 'DRV-007', null, $online?->id, null],

            // بروموتر — بيعمل ريفيل لرفوف الكي أكاونت
            ['كريم عادل', 'kareem@promax.local', 'promoter', 'PRM-031', $z1?->id, $ka?->id, null],
        ];

        foreach ($team as [$name, $email, $role, $code, $zoneId, $channelId, $manages]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'code' => $code,
                    'phone' => '010'.random_int(10000000, 99999999),
                    'zone_id' => $zoneId,
                    'channel_id' => $channelId,
                    'active' => true,
                    'password' => Hash::make(self::seedPassword()),
                ],
            );

            // قنوات المدير
            if ($manages === 'all') {
                $user->channels()->sync(Channel::pluck('id'));
            } elseif (is_array($manages)) {
                $user->channels()->sync(
                    Channel::whereIn('code', $manages)->pluck('id')
                );
            }

            if ($user->tokens()->count() === 0) {
                $user->issueToken('mobile');
            }
        }

        $this->command->info('   • '.count($team).' مستخدم (الباسورد اتطبع فوق)');
    }

    /**
     * ⚠️ **الحارس ده هو الفرق بين تيست وكارثة.**
     * السيدرز دي بتعمل `admin@promax.local` بباسورد معروف ومكتوب في
     * README المرفوع على الجت. `php artisan db:seed --force` على
     * اللايف — سطر واحد بيتكتب بالغلط أو بيتنسخ من دليل قديم —
     * بيفتح باب خلفي على السيستم الشغّال.
     *
     * التشغيل على production لازم يبقى قرار صريح:
     *     PROMAX_ALLOW_SEED=1 php artisan db:seed --force
     */
    private function blockOnProduction(): void
    {
        if (! app()->environment('production') || env('PROMAX_ALLOW_SEED') === '1') {
            return;
        }

        throw new \RuntimeException(
            'السيدر ده بيعمل حسابات ديمو بباسورد معروف، وممنوع يشتغل على production. '
            .'الفريق الحقيقي بيتعمل بـ`php artisan promax:team:setup`. '
            .'لو متأكد إنك عايزه: PROMAX_ALLOW_SEED=1 php artisan db:seed --force'
        );
    }

    /**
     * باسورد عشوائي واحد للتشغيلة دي، بيتطبع في الترمينال.
     *
     * ⚠️ ثابت جوه التشغيلة الواحدة (`static`) عشان كل الحسابات تاخد
     * نفس الباسورد وتقدر تدخل بيه، ومختلف كل مرة عشان مايتكتبش في
     * أي ملف ولا يتحفظ في أي دليل.
     */
    protected static function seedPassword(): string
    {
        if (static::$pw !== null) {
            return static::$pw;
        }

        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return static::$pw = $out;
    }
}
