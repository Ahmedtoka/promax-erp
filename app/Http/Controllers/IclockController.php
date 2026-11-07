<?php

namespace App\Http\Controllers;

use App\Services\Fingerprint\Adms;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * مكنة البصمة ZKTeco بتكلّم السيرفر هنا (ADMS / iclock — ٧/١١/٢٠٢٦).
 *
 * ⚠️ **بره الـweb middleware كله** (`routes/iclock.php` متسجل في `bootstrap/app.php`):
 * مفيش سيشن ولا CSRF ولا لوجين — المكنة مابتعرفش حاجة عن دول. الهوية = السيريال
 * المتسجل في شاشة «أجهزة البصمة»، وأي سيريال تاني بياخد 404.
 *
 * ⚠️ الرد نص عادي (`text/plain`) — المكنة بتقرا السطور مش JSON.
 */
class IclockController extends Controller
{
    public function cdata(Request $request): Response
    {
        $device = Adms::device($request->query('SN'), $request->ip());

        if ($device === null) {
            return $this->text('UNKNOWN DEVICE', 404);
        }

        if ($request->isMethod('GET')) {
            return $this->text(Adms::handshake($device));
        }

        $table = strtoupper((string) $request->query('table'));
        $stamp = $request->query('Stamp', $request->query('OpStamp'));
        $body = (string) $request->getContent();

        $n = match ($table) {
            'ATTLOG' => Adms::attlog($device, $body, $stamp),
            'OPERLOG' => Adms::operlog($device, $body, $stamp),
            // صور البصمة وغيره — بنقبلها ونسيبها عشان المكنة ماتفضلش تعيد
            default => count(array_filter(preg_split('/\r\n|\r|\n/', $body), fn ($l) => trim($l) !== '')),
        };

        return $this->text('OK: '.$n);
    }

    public function getrequest(Request $request): Response
    {
        $device = Adms::device($request->query('SN'), $request->ip());

        if ($device === null) {
            return $this->text('UNKNOWN DEVICE', 404);
        }

        Adms::info($device, $request->query('INFO'));

        return $this->text(Adms::pendingCommands($device));
    }

    public function devicecmd(Request $request): Response
    {
        $device = Adms::device($request->query('SN'), $request->ip());

        if ($device === null) {
            return $this->text('UNKNOWN DEVICE', 404);
        }

        Adms::commandResults($device, (string) $request->getContent());

        return $this->text('OK');
    }

    /** للتجربة من المتصفح: `http://<server>/iclock/ping` لازم يرد PROMAX-ICLOCK OK */
    public function ping(): Response
    {
        return $this->text('PROMAX-ICLOCK OK '.now()->format('Y-m-d H:i:s'));
    }

    private function text(string $body, int $status = 200): Response
    {
        return response($body, $status)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
