@extends('layouts.system')

{{--
    إصدار الأبلكيشن (2026-08-07) — أدمن بس.

    ⚠️ الشاشة دي بتتحكم في تليفونات المناديب اللي في الشارع. رفع
    «أقل إصدار» بيقفل الأبلكيشن على كل واحد لسه ما حدّثش — عشان كده
    الخانة دي متفصولة عن «آخر إصدار» ومكتوب جنبها تحذير صريح.
--}}

@section('title', __('appver.title'))

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px">
        <span>⚠️</span><span>{{ $errors->first() }}</span>
    </div>
@endif

<form method="POST" action="{{ route('erp.app_version.save') }}" enctype="multipart/form-data">
    @csrf

    <div class="card">
        <h3>📲 {{ __('appver.title') }}
            <span class="side">{{ __('appver.hint') }}</span></h3>

        <div class="frow">
            <div>
                <label class="f">{{ __('appver.latest') }}</label>
                <input type="text" name="app_version" required dir="ltr" pattern="\d+\.\d+\.\d+"
                       value="{{ old('app_version', $version) }}"
                       style="width:100%;text-align:center;font-weight:800">
                <div class="side" style="font-size:11px">{{ __('appver.latest_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('appver.minimum') }}</label>
                <input type="text" name="app_min_version" required dir="ltr" pattern="\d+\.\d+\.\d+"
                       value="{{ old('app_min_version', $minVersion) }}"
                       style="width:100%;text-align:center;font-weight:800;color:#B00020">
                <div class="side" style="font-size:11px;color:#B00020">{{ __('appver.minimum_hint') }}</div>
            </div>
        </div>

        <div style="margin-top:12px">
            <label class="f">{{ __('appver.note') }}</label>
            <input type="text" name="app_update_note" maxlength="300"
                   value="{{ old('app_update_note', $note) }}" style="width:100%"
                   placeholder="{{ __('appver.note_ph') }}">
            <div class="side" style="font-size:11px">{{ __('appver.note_hint') }}</div>
        </div>
    </div>

    <div class="card">
        <h3>📦 {{ __('appver.apk') }}</h3>

        @if ($apkExists)
            <div class="alert good" style="margin-bottom:10px">
                <span>✅</span>
                <span>{{ __('appver.apk_on_server', [
                    'size' => number_format($apkSize / 1048576, 1),
                    'at' => $apkAt,
                ]) }}</span>
            </div>
            <a href="{{ $apkUrl ?: url('app/promax.apk') }}" class="btn" style="margin-bottom:10px">
                ⬇️ {{ __('appver.download') }}
            </a>
        @else
            <div class="alert bad" style="margin-bottom:10px">
                <span>⚠️</span><span>{{ __('appver.apk_missing') }}</span>
            </div>
        @endif

        {{-- ═══ زرار واحد (طلب المالك ١١/٨): «حفظ» بيرفع الملف بالقطع
             ببار تقدم بالبايت، ولما يخلص بيحفظ الإصدارات ويرفرش
             بالداتا الصح. مفيش زرار رفع منفصل يلخبط. --}}
        <label class="f">{{ __('appver.apk_upload') }}</label>
        <input type="file" id="apkFile" accept=".apk,application/vnd.android.package-archive" style="width:100%">
        <div class="side" style="font-size:11px">{{ __('appver.apk_hint') }} — {{ __('appver.one_button_hint') }}</div>

        <div id="apkProg" style="display:none;margin-top:12px">
            <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:800;margin-bottom:5px">
                <span id="apkProgTxt">—</span>
                <span id="apkProgPct" dir="ltr">0%</span>
            </div>
            <div style="height:14px;border-radius:8px;background:var(--card2);border:1px solid var(--border);overflow:hidden">
                <div id="apkProgBar" style="height:100%;width:0%;background:var(--brand-gradient, #12399B);border-radius:8px;transition:width .2s"></div>
            </div>
            <div id="apkProgMsg" style="font-size:11px;color:var(--muted);margin-top:5px"></div>
        </div>
    </div>

    <button class="btn primary" type="submit" id="saveBtn">{{ __('common.save') }}</button>
</form>

<div class="card" style="margin-top:14px">
    <h3>📱 {{ __('appver.devices') }}
        <span class="side">{{ __('appver.devices_hint') }}</span></h3>

    @if (empty($devices))
        <div class="empty">{{ __('appver.no_devices') }}</div>
    @else
        <div class="tablewrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('appver.installed') }}</th>
                    <th>{{ __('appver.count') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($devices as $v => $n)
                    <tr>
                        <td dir="ltr" style="font-weight:800">{{ $v }}</td>
                        <td>{{ $n }}</td>
                        <td>
                            @if ($v === $version)
                                <span class="pill good">{{ __('appver.up_to_date') }}</span>
                            @elseif ($v === '—')
                                <span class="pill">{{ __('appver.unknown') }}</span>
                            @else
                                <span class="pill warn">{{ __('appver.outdated') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

@endsection

@section('scripts')
<script>
'use strict';

const APK_URL = @json(route('erp.app_version.chunk'));
const APK_CSRF = @json(csrf_token());
const T_CHUNK = @json(__('appver.chunk_of'));
const T_DONE = @json(__('appver.upload_done'));
const T_FAIL = @json(__('appver.upload_failed'));
const T_RETRY = @json(__('appver.retrying'));

/* ═══ زرار واحد (١١/٨): «حفظ» = رفع بالقطع + حفظ الإصدارات + ريفريش ═══

   فيه ملف متختار؟ بنمنع الإرسال العادي، نرفع الملف قطع ٢ ميجا
   بـXHR عشان ناخد **تقدم بالبايت جوه القطعة نفسها** (fetch مافيهوش
   upload progress — وده اللي كان مخلّي البار واقف على 0% والقطعة
   بترفع) — ولما الرفع يخلص بنبعت الفورم عادي فبيحفظ الإصدارات
   ويرجع بالصفحة متحدثة بالداتا الصح. مفيش ملف؟ الفورم بيتبعت عادي. */
const APK_CHUNK = 2 * 1024 * 1024;

function sendChunk(uploadId, i, total, blob, onProgress) {
    return new Promise(function (resolve, reject) {
        const fd = new FormData();
        fd.append('upload_id', uploadId);
        fd.append('index', i);
        fd.append('total', total);
        fd.append('chunk', blob, 'part');

        const x = new XMLHttpRequest();
        x.open('POST', APK_URL);
        x.setRequestHeader('X-CSRF-TOKEN', APK_CSRF);
        x.setRequestHeader('Accept', 'application/json');
        x.timeout = 120000;

        x.upload.onprogress = function (e) {
            if (e.lengthComputable) onProgress(e.loaded);
        };
        x.onload = function () {
            let j = {};
            try { j = JSON.parse(x.responseText || '{}'); } catch (e) { /* HTML خطأ */ }
            if (x.status >= 200 && x.status < 300) resolve(j);
            else reject(new Error(j.message || ('HTTP ' + x.status)));
        };
        x.onerror = function () { reject(new Error('network')); };
        x.ontimeout = function () { reject(new Error('timeout')); };
        x.send(fd);
    });
}

document.querySelector('form[action*="app-version"]').addEventListener('submit', async function (ev) {
    const form = this;
    const inp = document.getElementById('apkFile');
    const file = inp.files && inp.files[0];

    if (!file || form.dataset.uploaded === '1') return; // مفيش ملف أو اترفع خلاص → حفظ عادي

    ev.preventDefault();

    const btn = document.getElementById('saveBtn');
    const prog = document.getElementById('apkProg');
    const bar = document.getElementById('apkProgBar');
    const pct = document.getElementById('apkProgPct');
    const txt = document.getElementById('apkProgTxt');
    const msg = document.getElementById('apkProgMsg');

    const total = Math.ceil(file.size / APK_CHUNK);
    const uploadId = 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);

    btn.disabled = true;
    inp.disabled = true;
    prog.style.display = 'block';
    /* ⚠️ مش '' — الخلفية الأصلية inline، وتصفيرها كان بيمسحها خالص
       فالشريط يبقى شفاف: النسبة بتتحرك والبار «واقف» (اتشاف ١١/٨) */
    bar.style.background = 'var(--brand-gradient, #12399B)';
    msg.textContent = '';

    const paint = function (uploadedBytes) {
        const done = Math.min(99, Math.round((uploadedBytes / file.size) * 100));
        bar.style.width = done + '%';
        pct.textContent = done + '%';
    };

    try {
        let sent = 0;

        for (let i = 0; i < total; i++) {
            const blob = file.slice(i * APK_CHUNK, Math.min((i + 1) * APK_CHUNK, file.size));
            txt.textContent = T_CHUNK.replace(':x', i + 1).replace(':y', total);

            /* ٣ محاولات للقطعة — النت بيشقلب والقطعة بتتعاد لوحدها */
            let j = null;
            let lastErr = null;

            for (let attempt = 1; attempt <= 3 && j === null; attempt++) {
                try {
                    j = await sendChunk(uploadId, i, total, blob,
                        (loaded) => paint(sent + loaded));
                } catch (e) {
                    lastErr = e;
                    if (attempt < 3) {
                        msg.textContent = '🔁 ' + T_RETRY + ' (' + attempt + '/3)';
                        await new Promise(res => setTimeout(res, attempt * 1500));
                    }
                }
            }

            if (j === null) throw lastErr || new Error('upload failed');

            sent += blob.size;
            paint(sent);
            msg.textContent = '';
        }

        bar.style.width = '100%';
        pct.textContent = '100%';
        txt.textContent = T_DONE;
        msg.textContent = '✅ ' + T_DONE;

        /* الملف وصل — دلوقتي بنبعت الفورم نفسه عشان يحفظ الإصدارات
           ويرجع بالصفحة متحدثة. الخانة بتتفضّى عشان الملف الكبير
           مايتبعتش تاني مع البوست، والعلم بيمنع اللفة اللانهائية. */
        inp.value = '';
        inp.disabled = false;
        form.dataset.uploaded = '1';
        form.submit();
    } catch (e) {
        msg.textContent = '⛔ ' + T_FAIL + ' — ' + (e && e.message ? e.message : '');
        bar.style.background = '#B00020';
        btn.disabled = false;
        inp.disabled = false;
    }
});
</script>
@endsection
