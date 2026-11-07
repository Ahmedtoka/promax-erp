<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\FpCommand;
use App\Models\FpDevice;
use App\Models\FpDeviceUser;
use App\Models\FpLog;
use App\Models\Setting;
use App\Services\Fingerprint\Adms;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * أجهزة البصمة (ZKTeco ADMS — ٧/١١/٢٠٢٦) — `role:admin,hr`.
 *
 * • المكن: السيريال، آخر ظهور، أونلاين/أوفلاين، الـfirmware، الأوامر.
 * • ربط رقم المكنة (PIN) بالموظف — البصمات اللي كانت مستنية بتتطبّق فوراً.
 * • البصمات اللحظية (polling كل ١٠ ثواني على `live`).
 * • إعدادات المكنة خطوة بخطوة (العنوان والبورت اللي يتكتبوا على الشاشة).
 */
class FingerprintController extends Controller
{
    public function index(Request $request)
    {
        $devices = FpDevice::orderBy('name')->get();

        $unmapped = FpLog::query()
            ->where('result', FpLog::UNMAPPED)
            ->select('fp_device_id', 'pin', DB::raw('COUNT(*) as n'), DB::raw('MAX(punched_at) as last_at'))
            ->groupBy('fp_device_id', 'pin')->orderByDesc('last_at')->limit(100)->get();

        $deviceNames = FpDeviceUser::query()->get()->keyBy(fn ($u) => $u->fp_device_id.'|'.$u->pin);

        return view('hr.fingerprint', [
            'devices' => $devices,
            'unknown' => cache()->get(Adms::UNKNOWN_CACHE_KEY, []),
            'unmapped' => $unmapped,
            'deviceNames' => $deviceNames,
            'employees' => Employee::where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'fp_pin', 'user_id']),
            'logs' => $this->latest(0, 60),
            'commands' => FpCommand::with('device:id,name')->latest('id')->limit(15)->get(),
            'notifyAll' => (string) Setting::read('fp_notify_all', '0') === '1',
            'host' => $request->getHost(),
        ]);
    }

    /** البصمات الجديدة بعد `after` — الشاشة بتسأل كل ١٠ ثواني */
    public function live(Request $request)
    {
        $logs = $this->latest((int) $request->query('after', 0), 30);

        return response()->json([
            'logs' => $logs->map(fn (FpLog $l) => $this->row($l))->values(),
            'devices' => FpDevice::orderBy('name')->get()->map(fn (FpDevice $d) => [
                'id' => $d->id,
                'online' => $d->isOnline(),
                'seen' => $d->last_seen_at?->diffForHumans() ?? '—',
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'serial' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('fp_devices', 'serial')],
            'name' => ['required', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:120'],
            'mode' => ['required', Rule::in(FpDevice::MODES)],
        ]);

        // ⚠️ التاريخ القديم اللي على المكنة (قبل النهارده) بيترفع خام بس — مايعملش غياب/حضور بأثر رجعي
        FpDevice::create($data + ['active' => true, 'apply_from' => today()]);

        $unknown = cache()->get(Adms::UNKNOWN_CACHE_KEY, []);
        unset($unknown[$data['serial']]);
        cache()->put(Adms::UNKNOWN_CACHE_KEY, $unknown, now()->addDays(7));

        return back()->with('ok', __('fp.device_saved'));
    }

    public function update(Request $request, FpDevice $device)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:120'],
            'mode' => ['required', Rule::in(FpDevice::MODES)],
            'active' => ['nullable', 'boolean'],
            'apply_from' => ['nullable', 'date'],
        ]);

        $device->update([
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'mode' => $data['mode'],
            'active' => $request->boolean('active'),
            'apply_from' => $data['apply_from'] ?? null,
        ]);

        return back()->with('ok', __('fp.device_saved'));
    }

    /** أوامر للمكنة: إعادة رفع بصمات فترة · رفع أسماء الموظفين · تحديث البيانات */
    public function command(Request $request, FpDevice $device)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['resync', 'users', 'info', 'pull_users'])],
            'from' => ['nullable', 'date', 'required_if:action,resync'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $by = $request->user();
        $msg = match ($data['action']) {
            'resync' => tap(__('fp.cmd_queued'), fn () => Adms::queueResync($device,
                Carbon::parse($data['from']), Carbon::parse($data['to'] ?? $data['from']), $by)),
            'users' => __('fp.users_queued', ['n' => Adms::queueUsers($device, $by)]),
            'pull_users' => tap(__('fp.cmd_queued'), fn () => Adms::queue($device, 'DATA QUERY USERINFO', $by)),
            default => tap(__('fp.cmd_queued'), fn () => Adms::queue($device, 'INFO', $by)),
        };

        return back()->with('ok', $msg);
    }

    /** ربط رقم المكنة بالموظف — وبصماته المستنية بتتطبّق على طول */
    public function map(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'pin' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $e = Employee::findOrFail($data['employee_id']);
        $pin = ($data['pin'] ?? '') !== '' ? $data['pin'] : null;

        if ($pin !== null && Employee::where('fp_pin', $pin)->whereKeyNot($e->id)->exists()) {
            return back()->withErrors(['pin' => __('fp.pin_taken', ['pin' => $pin])]);
        }

        $e->forceFill(['fp_pin' => $pin])->save();
        $n = $pin !== null ? Adms::reapplyPin($pin) : 0;

        return back()->with('ok', __('fp.mapped', ['name' => $e->displayName(), 'n' => $n]));
    }

    public function settings(Request $request)
    {
        Setting::write('fp_notify_all', $request->boolean('notify_all') ? '1' : '0');

        return back()->with('ok', __('fp.settings_saved'));
    }

    private function latest(int $after, int $limit)
    {
        return FpLog::with(['employee:id,code,name,name_en', 'device:id,name'])
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->orderByDesc('id')->limit($limit)->get();
    }

    /** سطر البصمة للشاشة وللـJSON — نفس الأعمدة */
    public function row(FpLog $l): array
    {
        return [
            'id' => $l->id,
            'time' => $l->punched_at->format('Y-m-d H:i:s'),
            'device' => $l->device?->name ?? '—',
            'pin' => $l->pin,
            'employee' => $l->employee?->displayName() ?? '—',
            'type' => $l->punch_type !== null ? __('hr.punch_'.$l->punch_type) : '—',
            'verify' => $l->verifyLabel(),
            'result' => $l->result,
            'result_label' => __('fp.result_'.$l->result),
        ];
    }
}
