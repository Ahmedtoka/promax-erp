<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\JourneyPlan;
use App\Models\Location;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockCounting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * داتا ديمو ليوزر واحد — عشان شرح الشغل على الأبلكيشن
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **بيمشي في الفلو الحقيقي مش بيكتب في الجداول.** إذن استلام
 * حقيقي → ترصيف على رف → تسليم عهدة بأمر تجهيز بالـFEFO. لو كتبنا
 * `custody_items` مباشرة، الباتشات والأرفف و`stocks` كانوا هيفضلوا
 * مش عارفين عن البضاعة دي حاجة، وأول جرد يطلع عجز وهمي.
 *
 * ⚠️ **البضاعة اللي بتتعمل حقيقية على السيستم.** كل حاجة متعلّمة
 * DEMO (رقم الباتش، المورد، ملاحظة العهدة) عشان تتشال بالعين وقت
 * التنضيف — بس الفواتير اللي هتتعمل في الشرح فواتير فعلية بتحرّك
 * رصيد العميل. اعمل الشرح على عملاء الديمو وبس.
 */
class SetupDemo extends Command
{
    protected $signature = 'promax:demo
        {email : إيميل اليوزر اللي هياخد الداتا}
        {--clients=6 : عدد العملاء اللي هيتربطوا بيه}
        {--qty=20 : كمية كل صنف في العهدة}
        {--gift=2 : كمية الهدايا لكل صنف}
        {--products=5 : عدد الأصناف}';

    protected $description = 'داتا ديمو ليوزر واحد: عملاء + خط سير + عهدة محمّلة — بالفلو الحقيقي';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('  ⛔ مفيش يوزر بالإيميل ده: '.$this->argument('email'));

            return self::FAILURE;
        }

        if (! in_array($user->role, User::FIELD_ROLES, true)) {
            $this->error("  ⛔ {$user->name} رولّه «{$user->roleLabel()}» — الديمو للرولز الميدانية بس.");

            return self::FAILURE;
        }

        $warehouse = $user->warehouse_id
            ? Warehouse::find($user->warehouse_id)
            : Warehouse::where('active', true)->orderBy('id')->first();

        if ($warehouse === null) {
            $this->error('  ⛔ مفيش مخازن مفعّلة — اعمل مخزن الأول.');

            return self::FAILURE;
        }

        $this->info("  🎬 ديمو لـ {$user->displayName()} ({$user->roleLabel()}) — مخزن {$warehouse->displayName()}");
        $this->newLine();

        $clients = $this->assignClients($user);

        if ($clients->isEmpty()) {
            $this->error('  ⛔ مفيش عملاء مفعّلين — فعّل شوية عملاء من شاشة التفعيل الأول.');

            return self::FAILURE;
        }

        $this->buildJourney($user, $clients);
        $this->loadCustody($user, $warehouse);

        $this->newLine();
        $this->info('  ✅ خلص. افتح الأبلكيشن بحساب '.$user->email.' — هيلاقي:');
        $this->line('     · '.$clients->count().' عميل متربطين بيه');
        $this->line('     · خط سير النهارده وبكرة');
        $this->line('     · عهدة محمّلة ومستنية استلامه على الموبايل');
        $this->newLine();
        $this->warn('  ⚠️ الفواتير اللي هتتعمل في الشرح حقيقية — اعمل الديمو على عملاء الديمو وبس.');

        return self::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════
    //  1. العملاء
    // ═══════════════════════════════════════════════════════════

    /**
     * بيربط عملاء مفعّلين باليوزر.
     *
     * ⚠️ **اللي من غير مندوب الأول.** خطف عميل متربط بمندوب حقيقي
     * عشان ديمو كان هيخفيه من خط سير صاحبه — واللي ليه مندوب
     * مابيتلمسش إلا لو مفيش غيره.
     */
    private function assignClients(User $user): \Illuminate\Support\Collection
    {
        $zoneIds = $user->zones()->pluck('zones.id');
        $want = max(1, (int) $this->option('clients'));

        $pick = function ($query) use ($user, $want) {
            return $query->where('status', 'active')
                ->where(fn ($w) => $w->whereNull('rep_id')->orWhere('rep_id', $user->id))
                ->orderBy('code')
                ->limit($want)
                ->get();
        };

        // زوناته الأول — الديمو المفروض يشبه شغله الحقيقي
        $clients = $pick(Client::whereIn('zone_id', $zoneIds));

        if ($clients->count() < $want) {
            $more = $pick(Client::whereNotIn('id', $clients->pluck('id')));
            $clients = $clients->concat($more)->take($want);
        }

        DB::transaction(function () use ($clients, $user) {
            Client::whereIn('id', $clients->pluck('id'))->update(['rep_id' => $user->id]);
        });

        $this->line('  ✓ عملاء: '.$clients->count());

        return $clients->values();
    }

    // ═══════════════════════════════════════════════════════════
    //  2. خط السير
    // ═══════════════════════════════════════════════════════════

    /** نص العملاء النهارده والنص التاني بكرة — عشان يشرح «اليوم» و«الأسبوع» */
    private function buildJourney(User $user, $clients): void
    {
        $today = today()->dayOfWeek;
        $tomorrow = today()->addDay()->dayOfWeek;
        $made = 0;

        DB::transaction(function () use ($user, $clients, $today, $tomorrow, &$made) {
            foreach ($clients as $i => $client) {
                $day = $i < ceil($clients->count() / 2) ? $today : $tomorrow;

                $plan = JourneyPlan::firstOrCreate(
                    ['user_id' => $user->id, 'client_id' => $client->id, 'weekday' => $day],
                    ['every_weeks' => 1, 'sort' => $i + 1, 'active' => true],
                );

                if ($plan->wasRecentlyCreated) {
                    $made++;
                }
            }
        });

        $this->line("  ✓ خط سير: {$made} محطة (النهارده + بكرة)");
    }

    // ═══════════════════════════════════════════════════════════
    //  3. العهدة — بالفلو الحقيقي
    // ═══════════════════════════════════════════════════════════

    private function loadCustody(User $user, Warehouse $warehouse): void
    {
        $qty = max(1, (int) $this->option('qty'));
        $gift = max(0, (int) $this->option('gift'));
        $products = Product::where('active', true)
            ->orderBy('code')
            ->limit(max(1, (int) $this->option('products')))
            ->get();

        if ($products->isEmpty()) {
            $this->warn('  ⚠️ مفيش منتجات مفعّلة — العهدة اتعدّت.');

            return;
        }

        // ⚠️ لو ليه عهدة النهارده فيها بضاعة خلاص، مش بنكرر — تشغيل
        // الأمر مرتين قبل الشرح مايحمّلش العربية مرتين.
        $existing = $user->custodies()->whereDate('date', today())->first();

        if ($existing && $existing->items()->where('assigned', '>', 0)->exists()) {
            $this->line('  ✓ العهدة: محمّلة خلاص من قبل كده — اتعدّت.');

            return;
        }

        // ── أ. بضاعة ناقصة؟ إذن استلام DEMO يكمّلها ──
        $need = [];

        foreach ($products as $p) {
            $short = ($qty + $gift) - $warehouse->availableFor($p->id);
            if ($short > 0) {
                $need[$p->id] = $short;
            }
        }

        if ($need !== []) {
            $this->receiveAndShelve($warehouse, $products, $need);
        }

        // ── ب. تسليم العهدة — نفس مسار الشاشة بالظبط ──
        $result = PickOrder::issueDirect(
            $warehouse,
            $user,
            $products->mapWithKeys(fn ($p) => [$p->id => $qty])->all(),
            $gift > 0 ? $products->mapWithKeys(fn ($p) => [$p->id => $gift])->all() : [],
            User::where('role', 'admin')->first() ?? $user,
            'DEMO',
        );

        if ($result['error'] !== null) {
            $this->error('  ⛔ العهدة فشلت: '.$result['error']);

            return;
        }

        $this->line("  ✓ عهدة: {$products->count()} صنف × {$qty}".($gift ? " + {$gift} هدية" : '')
            ." — أمر {$result['order']->number}");
    }

    /** إذن استلام DEMO + ترصيف — عشان الـFEFO يلاقي بضاعة على الأرفف */
    private function receiveAndShelve(Warehouse $warehouse, $products, array $need): void
    {
        DB::transaction(function () use ($warehouse, $products, $need) {
            $receipt = GoodsReceipt::create([
                'number' => GoodsReceipt::nextNumber(),
                'warehouse_id' => $warehouse->id,
                'received_on' => today(),
                'status' => 'posted',
                'supplier' => 'DEMO',
                'reference' => 'DEMO-'.today()->format('Ymd'),
                'created_by' => User::where('role', 'admin')->value('id'),
                'notes' => 'بضاعة ديمو للشرح — promax:demo',
            ]);

            // رف DEMO — مايتلخبطش مع الأرفف الحقيقية
            $location = Location::firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'code' => 'DEMO'],
                ['active' => true],
            );

            foreach ($products as $p) {
                $short = $need[$p->id] ?? 0;

                if ($short <= 0) {
                    continue;
                }

                $batch = Batch::firstOrNew([
                    'product_id' => $p->id,
                    'batch_no' => 'DEMO-'.$p->code,
                    'warehouse_id' => $warehouse->id,
                ]);

                $batch->fill([
                    'goods_receipt_id' => $receipt->id,
                    'produced_on' => today()->subMonth(),
                    'expires_on' => today()->addMonths($p->shelfLife()),
                    'cost' => $p->cost,
                ]);
                $batch->qty_received = (int) $batch->qty_received + $short;
                $batch->qty_remaining = (int) $batch->qty_remaining + $short;
                $batch->save();

                // ⚠️ الترصيف بالدالة الرسمية — هي اللي بتحافظ على
                // «الباتش = مجموع أرففه»، والكتابة المباشرة بتكسرها.
                if ($error = BatchLocation::putAway($batch, $location, $short)) {
                    throw new \RuntimeException($error);
                }

                StockCounting::resync($p->id, $warehouse->id);
            }
        });

        $this->line('  ✓ بضاعة DEMO: اتستلمت واترصّفت على رف DEMO');
    }
}
