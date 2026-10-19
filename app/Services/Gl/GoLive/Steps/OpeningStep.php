<?php

namespace App\Services\Gl\GoLive\Steps;

use App\Models\Client;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlOpeningLine;
use App\Models\Supplier;
use App\Models\SupplierTransaction;
use App\Models\Transaction;
use App\Services\Gl\CoaAdopter;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;
use App\Services\Gl\Ledger;
use App\Services\Gl\OpeningBuilder;

/**
 * ٦ — الأرصدة الافتتاحية (D6): سطور يدوية، عملاء وموردين بالاسم، والسطور
 * الأوتوماتيك من المصادر، وزرار الترحيل. الفرق ≠ صفر = تنبيه لحد ما يتصفّر.
 */
class OpeningStep implements Step
{
    /** سقف نتايج البحث بالاسم — زي المستند اليدوي، ومعاه «كمّل كتابة» */
    public const SEARCH_LIMIT = 60;

    public function number(): int
    {
        return 6;
    }

    public function key(): string
    {
        return 'opening';
    }

    public function titleKey(): string
    {
        return 'golive.s6_title';
    }

    public function state(): StepState
    {
        $b = app(OpeningBuilder::class);
        if ($b->asOf() === null) {
            return new StepState('red', [__('golive.opening_no_start')]);
        }
        if (! $this->posted()) {
            return new StepState('yellow', [__('golive.s6_not_posted')]);
        }
        if ($b->isStale()) {
            return new StepState('yellow', [__('golive.s6_stale')]);
        }
        $diff = $b->differenceBalance();
        if (abs($diff) >= 0.005) {
            return new StepState('yellow', [__('golive.s6_difference', ['amount' => number_format($diff, 2)])]);
        }

        return new StepState('green');
    }

    private function posted(): ?GlEntry
    {
        return GlEntry::where('origin', 'opening')->where('rule_key', Ledger::OPENING_RULE)->latest('id')->first();
    }

    public function view(): string
    {
        return 'gl.golive.steps.opening';
    }

    public function data(): array
    {
        $b = app(OpeningBuilder::class);
        $compose = null;
        $composeError = null;
        if ($b->asOf() !== null) {
            try {
                $compose = $b->compose();
            } catch (\InvalidArgumentException $e) {
                $composeError = $e->getMessage();
            }
        }

        $accounts = GlAccount::where('active', true)->where('is_postable', true)->whereNull('user_id');
        if (CoaAdopter::adopted()) {
            $accounts->where(fn ($q) => $q->whereNotNull('coa_draft_id')->orWhere('system_key', 'opening_difference'));
        }

        $q = trim((string) request()->query('q', ''));
        $clients = $suppliers = collect();
        $clientsMore = $suppliersMore = false;
        if (mb_strlen($q) >= 2) {
            $like = '%'.$q.'%';
            $clients = Client::visibleTo(Client::query(), request()->user())
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->orderBy('name')->limit(self::SEARCH_LIMIT + 1)->get();
            $suppliers = Supplier::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('code', 'like', $like))
                ->orderBy('name')->limit(self::SEARCH_LIMIT + 1)->get();
            $clientsMore = $clients->count() > self::SEARCH_LIMIT;
            $suppliersMore = $suppliers->count() > self::SEARCH_LIMIT;
            $clients = $clients->take(self::SEARCH_LIMIT);
            $suppliers = $suppliers->take(self::SEARCH_LIMIT);
        }

        return [
            'asOf' => $b->asOf(),
            'compose' => $compose,
            'composeError' => $composeError,
            'posted' => $this->posted(),
            'difference' => $b->differenceBalance(),
            'lines' => GlOpeningLine::with(['account', 'costCenter'])->orderBy('id')->get(),
            'accounts' => $accounts->orderBy('code')->get(),
            'centers' => CostCenter::orderBy('code')->get(),
            'q' => $q,
            'clients' => $clients,
            'suppliers' => $suppliers,
            'clientsMore' => $clientsMore,
            'suppliersMore' => $suppliersMore,
            'clientOpenings' => Transaction::with('client')->where('idem_key', 'like', 'golive-opening:client:%')->orderBy('id')->get(),
            'supplierOpenings' => SupplierTransaction::with('supplier')->where('idem_key', 'like', 'golive-opening:supplier:%')->orderBy('id')->get(),
        ];
    }
}
