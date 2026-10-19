<?php

namespace App\Services\Gl;

use App\Models\Client;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ الأرصدة الافتتاحية للعملاء (مديونيات ما قبل السيستم) ═══
 *
 * CSV بتلات أعمدة: كود العميل · المبلغ · التاريخ (اختياري). `preview()`
 * قراءة بس وبترجّع كل سطر بحالته؛ `post()` بتكتب **كل السطور أو ولا
 * سطر**: قيد `opening` في `transactions` (مدين لو المبلغ موجب، دائن لو
 * سالب) + `recalculate()` جوه نفس الترانزاكشن — يعني ده مسار مديونية
 * حقيقي مش قيد شجرة، والشجرة بتاخده من `tx.opening` زي أي مستند.
 *
 * ⚠️ `idem_key` = بصمة (عميل + تاريخ + مبلغ): نفس الملف لو اترفع مرتين
 * السطر اللي اتسجل قبل كده بيتعلّم «مسجّل قبل كده» ومايتكتبش تاني.
 *
 * ⚠️ التاريخ الفاضي = `gl_start_date` (أو النهارده لو مش متحدد) — لو
 * القيد اتأرّخ **قبل** تاريخ البداية الشجرة مش هتشوفه، وحساب العملاء
 * هيطلع أقل من `SUM(clients.balance)` بالمبلغ ده.
 */
class OpeningBalances
{
    public const STATUS_OK = 'ok';

    /**
     * @return array{rows: list<array{line:int, code:string, client_id:?int, client:?string, amount:float, date:?string, status:string}>,
     *               ok:int, errors:int, total:float, before_gl_start:int}
     */
    public function preview(string $path): array
    {
        $rows = [];
        $seen = [];
        $default = Setting::read('gl_start_date') ?: today()->toDateString();
        $glStart = Setting::read('gl_start_date');

        foreach ($this->read($path) as [$line, $cells]) {
            $code = trim((string) ($cells[0] ?? ''));
            $rawAmount = str_replace([',', ' ', "\u{00A0}"], '', trim((string) ($cells[1] ?? '')));
            $rawDate = trim((string) ($cells[2] ?? ''));

            $row = ['line' => $line, 'code' => $code, 'client_id' => null, 'client' => null,
                'amount' => 0.0, 'date' => null, 'status' => self::STATUS_OK];

            $client = $code !== '' ? Client::where('code', $code)->first() : null;
            $date = $this->date($rawDate === '' ? $default : $rawDate);

            if ($code === '') {
                $row['status'] = 'missing_code';
            } elseif ($client === null) {
                $row['status'] = 'unknown_client';
            } elseif (! is_numeric($rawAmount) || round((float) $rawAmount, 2) == 0.0) {
                $row['status'] = 'bad_amount';
            } elseif ($date === null) {
                $row['status'] = 'bad_date';
            } elseif (isset($seen[$code])) {
                $row['status'] = 'duplicate_code';
            }

            if ($client !== null) {
                $row['client_id'] = $client->id;
                $row['client'] = $client->displayName();
            }
            if (is_numeric($rawAmount)) {
                $row['amount'] = round((float) $rawAmount, 2);
            }
            $row['date'] = $date;

            if ($row['status'] === self::STATUS_OK) {
                $seen[$code] = true;
                if (Transaction::where('idem_key', $this->idemKey($row))->exists()) {
                    $row['status'] = 'already_posted';
                }
            }
            $rows[] = $row;
        }

        $ok = array_values(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_OK));

        return [
            'rows' => $rows,
            'ok' => count($ok),
            'errors' => count($rows) - count($ok),
            'total' => round(array_sum(array_column($ok, 'amount')), 2),
            'before_gl_start' => $glStart ? count(array_filter($ok, fn ($r) => $r['date'] < $glStart)) : 0,
        ];
    }

    /**
     * الترحيل — بيرفض الملف كله لو فيه أي سطر مش `ok` (ماعدا «مسجّل قبل
     * كده» اللي بيتخطّى). بيرجّع عدد القيود اللي اتكتبت.
     */
    public function post(string $path, User $by): int
    {
        $preview = $this->preview($path);
        $bad = array_filter($preview['rows'], fn ($r) => ! in_array($r['status'], [self::STATUS_OK, 'already_posted'], true));
        if ($bad !== []) {
            throw new \InvalidArgumentException(__('gl.opening_import_has_errors', ['n' => count($bad)]));
        }

        return DB::transaction(function () use ($preview, $by) {
            $n = 0;
            $clients = [];
            foreach ($preview['rows'] as $r) {
                if ($r['status'] !== self::STATUS_OK) {
                    continue;
                }
                $tx = Transaction::create([
                    'client_id' => $r['client_id'],
                    'date' => $r['date'],
                    'memo' => __('gl.opening_import_memo', ['user' => $by->name]),
                    'debit' => $r['amount'] > 0 ? $r['amount'] : 0,
                    'credit' => $r['amount'] < 0 ? -$r['amount'] : 0,
                    'tax' => 0,
                    'kind' => 'opening',
                    'idem_key' => $this->idemKey($r),
                ]);
                // `create()` بيتجاهل `created_at` — نفس طريقة المستند اليدوي
                Transaction::whereKey($tx->id)->update(['created_at' => $r['date'].' 12:00:00']);
                $clients[$r['client_id']] = true;
                $n++;
            }
            foreach (Client::whereIn('id', array_keys($clients))->get() as $c) {
                $c->recalculate();
            }

            return $n;
        });
    }

    // ═══ الافتتاحي بالاسم من المعالج (الخط ٣ — D6) ═══

    /**
     * رصيد افتتاحي لعميل بالاسم — صف `opening` واحد بمفتاح ثابت
     * `golive-opening:client:{id}`. موجود = يتعدّل · صفر = يتمسح. الافتتاحي
     * اللي اتسجّل من ملف الـCSV القديم مالوش دعوة (مفتاحه مختلف).
     * `recalculate()` جوه نفس الترانزاكشن.
     */
    public function setClient(Client $c, float $amount, Carbon $date, User $by): ?Transaction
    {
        $amount = round($amount, 2);
        $key = 'golive-opening:client:'.$c->id;

        return DB::transaction(function () use ($c, $amount, $date, $by, $key) {
            Client::whereKey($c->id)->lockForUpdate()->first();
            $tx = Transaction::where('idem_key', $key)->lockForUpdate()->first();

            if ($amount == 0.0) {
                $tx?->delete();
                $c->recalculate();

                return null;
            }

            $attrs = [
                'client_id' => $c->id,
                'date' => $date->toDateString(),
                'memo' => __('golive.opening_client_memo', ['user' => $by->name]),
                'debit' => $amount > 0 ? $amount : 0,
                'credit' => $amount < 0 ? -$amount : 0,
                'tax' => 0,
                'kind' => 'opening',
            ];
            if ($tx) {
                $tx->update($attrs);
            } else {
                $tx = Transaction::create($attrs + ['idem_key' => $key]);
            }
            Transaction::whereKey($tx->id)->update(['created_at' => $date->toDateString().' 12:00:00']);
            $c->recalculate();

            return $tx->fresh();
        });
    }

    /**
     * رصيد افتتاحي لمورد بالاسم — موجب = علينا له (دائن)، سالب = مدين.
     * صف `opening` واحد بمفتاح `golive-opening:supplier:{id}`، صفر = يتمسح.
     */
    public function setSupplier(Supplier $s, float $amount, Carbon $date, User $by): ?SupplierTransaction
    {
        $amount = round($amount, 2);
        $key = 'golive-opening:supplier:'.$s->id;

        return DB::transaction(function () use ($s, $amount, $date, $by, $key) {
            Supplier::whereKey($s->id)->lockForUpdate()->first();
            $tx = SupplierTransaction::where('idem_key', $key)->lockForUpdate()->first();

            if ($amount == 0.0) {
                $tx?->delete();
                $s->recalculate();

                return null;
            }

            $memo = __('golive.opening_supplier_memo', ['user' => $by->name]);
            $debit = $amount < 0 ? -$amount : 0.0;
            $credit = $amount > 0 ? $amount : 0.0;
            if ($tx) {
                $tx->update(['date' => $date->toDateString(), 'kind' => 'opening', 'debit' => $debit, 'credit' => $credit, 'memo' => $memo]);
                $s->recalculate();

                return $tx->fresh();
            }

            return $s->post('opening', $date->toDateString(), $debit, $credit, $memo, null, $key);
        });
    }

    /**
     * CSV موردين: (كود المورد أو اسمه بالظبط، المبلغ، التاريخ اختياري).
     * التاريخ الفاضي = يوم قبل `gl_start_date` (أو النهارده لو مش متحدد).
     * نفس حالات ملف العملاء (`unknown_supplier` بدل `unknown_client`).
     *
     * @return array{rows: list<array{line:int, code:string, supplier_id:?int, supplier:?string, amount:float, date:?string, status:string}>,
     *               ok:int, errors:int, total:float}
     */
    public function previewSuppliers(string $path): array
    {
        $rows = [];
        $seen = [];
        $start = Setting::read('gl_start_date');
        $default = $start ? Carbon::parse($start)->subDay()->toDateString() : today()->toDateString();

        foreach ($this->read($path) as [$line, $cells]) {
            $code = trim((string) ($cells[0] ?? ''));
            $rawAmount = str_replace([',', ' ', "\u{00A0}"], '', trim((string) ($cells[1] ?? '')));
            $rawDate = trim((string) ($cells[2] ?? ''));

            $row = ['line' => $line, 'code' => $code, 'supplier_id' => null, 'supplier' => null,
                'amount' => 0.0, 'date' => null, 'status' => self::STATUS_OK];

            $supplier = null;
            if ($code !== '') {
                $supplier = Supplier::where('code', $code)->first()
                    ?? Supplier::where('name', $code)->orWhere('name_en', $code)->orderBy('id')->first();
            }
            $date = $this->date($rawDate === '' ? $default : $rawDate);

            if ($code === '') {
                $row['status'] = 'missing_code';
            } elseif ($supplier === null) {
                $row['status'] = 'unknown_supplier';
            } elseif (! is_numeric($rawAmount) || round((float) $rawAmount, 2) == 0.0) {
                $row['status'] = 'bad_amount';
            } elseif ($date === null) {
                $row['status'] = 'bad_date';
            } elseif (isset($seen[$supplier->id])) {
                $row['status'] = 'duplicate_code';
            }

            if ($supplier !== null) {
                $row['supplier_id'] = $supplier->id;
                $row['supplier'] = $supplier->displayName();
            }
            if (is_numeric($rawAmount)) {
                $row['amount'] = round((float) $rawAmount, 2);
            }
            $row['date'] = $date;

            if ($row['status'] === self::STATUS_OK) {
                $seen[$supplier->id] = true;
                if (SupplierTransaction::where('idem_key', $this->supplierIdemKey($row))->exists()) {
                    $row['status'] = 'already_posted';
                }
            }
            $rows[] = $row;
        }

        $ok = array_values(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_OK));

        return [
            'rows' => $rows,
            'ok' => count($ok),
            'errors' => count($rows) - count($ok),
            'total' => round(array_sum(array_column($ok, 'amount')), 2),
        ];
    }

    /** كل السطور أو ولا سطر — «مسجّل قبل كده» بيتخطّى. بيرجّع عدد القيود */
    public function postSuppliers(string $path, User $by): int
    {
        $preview = $this->previewSuppliers($path);
        $bad = array_filter($preview['rows'], fn ($r) => ! in_array($r['status'], [self::STATUS_OK, 'already_posted'], true));
        if ($bad !== []) {
            throw new \InvalidArgumentException(__('gl.opening_import_has_errors', ['n' => count($bad)]));
        }

        return DB::transaction(function () use ($preview, $by) {
            $n = 0;
            foreach ($preview['rows'] as $r) {
                if ($r['status'] !== self::STATUS_OK) {
                    continue;
                }
                $s = Supplier::findOrFail($r['supplier_id']);
                $s->post('opening', $r['date'], $r['amount'] < 0 ? -$r['amount'] : 0.0, $r['amount'] > 0 ? $r['amount'] : 0.0,
                    __('golive.opening_supplier_import_memo', ['user' => $by->name]), null, $this->supplierIdemKey($r));
                $n++;
            }

            return $n;
        });
    }

    private function supplierIdemKey(array $r): string
    {
        return 'sopening:'.sha1($r['supplier_id'].'|'.$r['date'].'|'.number_format($r['amount'], 2, '.', ''));
    }

    /** @return \Generator<array{0:int, 1:list<string>}> سطر برقمه — بيتخطى الفاضي والهيدر */
    private function read(string $path): \Generator
    {
        $h = fopen($path, 'r');
        if ($h === false) {
            throw new \InvalidArgumentException('Cannot read '.$path);
        }
        try {
            $line = 0;
            while (($cells = fgetcsv($h, 0, ',', '"', '')) !== false) {
                $line++;
                if ($line === 1 && isset($cells[0])) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
                }
                if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '') {
                    continue;
                }
                // الهيدر: أول سطر عمود المبلغ فيه مش رقم
                if ($line === 1 && ! is_numeric(str_replace([',', ' '], '', (string) ($cells[1] ?? '')))) {
                    continue;
                }
                yield [$line, array_map('strval', $cells)];
            }
        } finally {
            fclose($h);
        }
    }

    private function date(string $raw): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y'] as $f) {
            $d = \DateTime::createFromFormat('!'.$f, $raw);
            if ($d !== false && $d->format($f) === $raw) {
                return Carbon::instance($d)->toDateString();
            }
        }

        return null;
    }

    private function idemKey(array $r): string
    {
        return 'opening:'.sha1($r['client_id'].'|'.$r['date'].'|'.number_format($r['amount'], 2, '.', ''));
    }
}
