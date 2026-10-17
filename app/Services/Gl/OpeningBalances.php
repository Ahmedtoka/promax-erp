<?php

namespace App\Services\Gl;

use App\Models\Client;
use App\Models\Setting;
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
