<?php

namespace App\Http\Controllers;

use App\Models\GpsDevice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Itrack;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * أجهزة تتبع العربيات — iTrack (٢٦ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الشاشة الواحدة: بيانات حساب المنصة + سحب الأجهزة + ربط كل جهاز
 * بمندوب/سواق + آخر موقع وحالة. العرض أدمن ومدير — والتعديل
 * (الحساب/السحب/الربط) أدمن بس على الراوت.
 */
class GpsController extends Controller
{
    public function index()
    {
        $u = auth()->user();

        // المدير بيشوف أجهزة فريقه بس — الأدمن الكل ومعاهم غير المربوط
        $q = GpsDevice::with('user')->orderByDesc('active')->orderBy('plate');
        if ($u->role !== 'admin') {
            $visible = User::fieldVisibleTo(User::query())->pluck('id');
            $q->whereIn('user_id', $visible);
        }

        return view('ops.gps', [
            'devices' => $q->get(),
            // اللينكابل للربط: فريق الميدان + المديرين (المدير الميداني ليه عربية)
            'field' => User::whereIn('role', User::FIELD_WORK_ROLES)
                ->where('active', true)->orderBy('name')->get(),
            'account' => (string) Setting::read('itrack_account'),
            'hasPassword' => trim((string) Setting::read('itrack_password_md5')) !== '',
            'tokenOk' => Setting::read('itrack_token') !== null
                && (int) Setting::read('itrack_token_exp') > time(),
            'lastError' => Setting::read('itrack_last_error'),
        ]);
    }

    /** حفظ بيانات الحساب — الباسورد بيتخزن md5 بس (شوف Itrack::saveCredentials) */
    public function credentials(Request $request)
    {
        $data = $request->validate([
            'account' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:100'],
        ]);

        Itrack::saveCredentials($data['account'], $data['password'] ?? null);

        // جرّب التوكن فوراً — عشان الغلط يبان دلوقتي مش بعد دقيقة بولينج
        if (Itrack::token() === null) {
            return back()->withErrors(['gps' => __('gps.creds_fail',
                ['e' => (string) Setting::read('itrack_last_error')])]);
        }

        return back()->with('ok', __('gps.creds_ok'));
    }

    /** سحب قايمة الأجهزة من المنصة — إضافة وتحديث ميتاداتا، مفيش مسح */
    public function sync()
    {
        $r = Itrack::syncDevices();

        if ($r['error']) {
            return back()->withErrors(['gps' => __('gps.sync_fail', ['e' => $r['error']])]);
        }

        return back()->with('ok', __('gps.sync_ok', ['a' => $r['added'], 'u' => $r['updated']]));
    }

    /** تحديث المواقع دلوقتي — نفس منطق الأمر المجدول بالحرف */
    public function poll()
    {
        $r = Itrack::pollOnce();

        if ($r['error']) {
            return back()->withErrors(['gps' => __('gps.poll_fail', ['e' => $r['error']])]);
        }

        return back()->with('ok', __('gps.poll_ok', ['n' => $r['updated']]));
    }

    /** حفظ الربط: لكل جهاز مندوب + لوحة + تفعيل — فورم واحد للجدول كله */
    public function save(Request $request)
    {
        $rows = (array) $request->input('d', []);
        $validUsers = User::whereIn('role', User::FIELD_WORK_ROLES)->pluck('id')->all();
        $saved = 0;

        foreach (GpsDevice::whereIn('id', array_keys($rows))->get() as $dev) {
            $row = $rows[$dev->id];
            $uid = (int) ($row['user_id'] ?? 0);

            $dev->update([
                // ربط بغير فريق الميدان = تجاهل في صمت أحسن من 500
                'user_id' => $uid > 0 && in_array($uid, $validUsers, true) ? $uid : null,
                'plate' => trim((string) ($row['plate'] ?? '')) ?: null,
                'active' => isset($row['active']),
            ]);
            $saved++;
        }

        return back()->with('ok', __('gps.saved', ['n' => $saved]));
    }
}
