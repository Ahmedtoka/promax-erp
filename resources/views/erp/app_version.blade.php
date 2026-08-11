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

        {{-- ═══ الرفع بالقطع (١١/٨) — بار تقدم حقيقي + ريفريش تلقائي ═══
             الرفع القديم بريكوست واحد كان بيموت في النص على الملفات
             الكبيرة (ERR_HTTP2_PING_FAILED) ومحدش عارف وصل ولا لأ.
             دلوقتي: الملف بيتقطّع ٤ ميجا، كل قطعة ريكوست سريع،
             والبار بيقول «قطعة X من Y» — ولما يخلص الصفحة بتترفرش
             لوحدها وتلاقي «الملف على السيرفر ✅». --}}
        <label class="f">{{ __('appver.apk_upload') }}</label>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="file" id="apkFile" accept=".apk,application/vnd.android.package-archive" style="flex:1;min-width:220px">
            <button class="btn gold" type="button" id="apkBtn" onclick="apkUpload()">⬆️ {{ __('appver.upload_now') }}</button>
        </div>
        <div class="side" style="font-size:11px">{{ __('appver.apk_hint') }}</div>

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

    <button class="btn primary" type="submit">{{ __('common.save') }}</button>
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
const T_PICK = @json(__('appver.pick_file_first'));
const T_CHUNK = @json(__('appver.chunk_of'));
const T_DONE = @json(__('appver.upload_done'));
const T_FAIL = @json(__('appver.upload_failed'));
const T_RETRY = @json(__('appver.retrying'));

/* ═══ الرفع بالقطع — ٤ ميجا للقطعة ═══
   كل قطعة ريكوست مستقل بمهلة قصيرة، وبيتعاد ٣ مرات لو النت شقلب —
   فالرفع بينجو من مهلة البروكسي اللي كانت بتموّت الريكوست الواحد
   الكبير. البار بيتحرك مع كل قطعة، وآخر قطعة بترجّع done=true
   والصفحة بتترفرش لوحدها. */
const APK_CHUNK = 4 * 1024 * 1024;

async function apkUpload() {
    const inp = document.getElementById('apkFile');
    const file = inp.files && inp.files[0];

    if (!file) { alert(T_PICK); return; }

    const btn = document.getElementById('apkBtn');
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
    msg.textContent = '';

    try {
        for (let i = 0; i < total; i++) {
            const blob = file.slice(i * APK_CHUNK, Math.min((i + 1) * APK_CHUNK, file.size));

            txt.textContent = T_CHUNK.replace(':x', i + 1).replace(':y', total);

            /* ٣ محاولات للقطعة — النت المصري بيشقلب والقطعة الضايعة
               تتعاد لوحدها بدل ما الرفع كله يقع */
            let lastErr = null;
            let ok = false;

            for (let attempt = 1; attempt <= 3 && !ok; attempt++) {
                try {
                    const fd = new FormData();
                    fd.append('upload_id', uploadId);
                    fd.append('index', i);
                    fd.append('total', total);
                    fd.append('chunk', blob, 'part');

                    const r = await fetch(APK_URL, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': APK_CSRF, 'Accept': 'application/json' },
                        body: fd,
                    });

                    const j = await r.json().catch(() => ({}));

                    if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status));

                    ok = true;

                    if (j.done) {
                        bar.style.width = '100%';
                        pct.textContent = '100%';
                        txt.textContent = T_DONE;
                        msg.textContent = '✅ ' + T_DONE;
                        /* ريفريش تلقائي — الصفحة بترجع بـ«الملف على السيرفر» */
                        setTimeout(() => location.reload(), 1200);
                        return;
                    }
                } catch (e) {
                    lastErr = e;
                    if (attempt < 3) {
                        msg.textContent = '🔁 ' + T_RETRY + ' (' + attempt + '/3)';
                        await new Promise(res => setTimeout(res, attempt * 1500));
                    }
                }
            }

            if (!ok) throw lastErr || new Error('upload failed');

            const done = Math.round(((i + 1) / total) * 100);
            bar.style.width = done + '%';
            pct.textContent = done + '%';
        }
    } catch (e) {
        msg.textContent = '⛔ ' + T_FAIL + ' — ' + (e && e.message ? e.message : '');
        bar.style.background = '#B00020';
    } finally {
        btn.disabled = false;
        inp.disabled = false;
    }
}
</script>
@endsection
