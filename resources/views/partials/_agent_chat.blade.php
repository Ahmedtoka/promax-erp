{{-- ═══════════════════════════════════════════════════════════════
     مساعد بروماكس — زرار عائم + بانل شات منزلق (٧/٩/٢٠٢٦)
     بيتعمل include في layouts/system.blade.php جوه @auth.
     الرد: نص + بلوك بيانات (جدول/كارت) + زرار «افتح في السيستم».
     ⚠️ كل نص الموديل بيتعرض بـtextContent — ممنوع innerHTML عليه.
     ═══════════════════════════════════════════════════════════════ --}}

<style>
/* الزرار العائم — يمين تحت (منطقي: بيتقلب مع الإنجليزي لوحده) */
#pmxAgentBtn {
    position: fixed; inset-inline-end: 18px; bottom: 18px; z-index: 6000;
    width: 54px; height: 54px; border-radius: 50%; border: 0; cursor: pointer;
    background: linear-gradient(135deg, #12399B 0%, #602D90 100%);
    color: #fff; font-size: 23px; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 16px rgba(18, 57, 155, .38); transition: transform .15s;
}
#pmxAgentBtn:hover { transform: scale(1.07); }

/* البانل المنزلق */
#pmxAgent {
    position: fixed; inset-block: 0; inset-inline-end: 0; z-index: 6001;
    width: min(420px, 100vw); background: var(--card, #fff);
    border-inline-start: 1px solid var(--border, #E4E4EA);
    box-shadow: -6px 0 24px rgba(10, 10, 15, .14);
    display: flex; flex-direction: column;
    transform: translateX(calc(var(--pmx-dir, 1) * 105%)); transition: transform .22s ease;
}
[dir="rtl"] #pmxAgent { --pmx-dir: -1; }
#pmxAgent.on { transform: translateX(0); }

#pmxAgent .pmx-head {
    background: linear-gradient(135deg, #12399B 0%, #602D90 100%);
    color: #fff; padding: 12px 16px; display: flex; align-items: center; gap: 10px;
}
#pmxAgent .pmx-head b { font-size: 14.5px; }
#pmxAgent .pmx-head .sub { font-size: 10.5px; opacity: .75; }
#pmxAgent .pmx-head button {
    background: rgba(255, 255, 255, .16); border: 0; color: #fff; cursor: pointer;
    border-radius: 8px; padding: 4px 9px; font-size: 12px;
}

#pmxMsgs { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; }

.pmx-b { max-width: 88%; border-radius: 14px; padding: 9px 12px; font-size: 12.5px; line-height: 1.55; white-space: pre-wrap; }
.pmx-b.user { align-self: flex-end; background: var(--royal-blue, #12399B); color: #fff; border-end-end-radius: 4px; }
.pmx-b.bot { align-self: flex-start; background: var(--card2, #F1F1F4); color: var(--text, #12121A); border-end-start-radius: 4px; }
.pmx-b.err { align-self: flex-start; background: #FDE8E8; color: #B91C1C; }

/* بلوك البيانات */
.pmx-data { align-self: stretch; background: #fff; border: 1px solid var(--border, #E4E4EA); border-radius: 12px; overflow: hidden; }
.pmx-data .t { font-size: 11.5px; font-weight: 800; padding: 8px 10px; color: var(--royal-blue, #12399B);
    border-bottom: 1px solid var(--border, #E4E4EA); background: #F7F8FE; }
.pmx-data .wrap { max-height: 260px; overflow: auto; }
.pmx-data table { width: 100%; border-collapse: collapse; font-size: 11px; }
.pmx-data th { position: sticky; top: 0; background: var(--card2, #F1F1F4); padding: 5px 8px; text-align: start; font-size: 10px; }
.pmx-data td { padding: 5px 8px; border-top: 1px solid var(--border, #E4E4EA); vertical-align: top; }
.pmx-data td.n { font-variant-numeric: tabular-nums; direction: ltr; text-align: end; white-space: nowrap; }
.pmx-data .f { font-size: 10.5px; color: var(--muted, #6B6B7B); padding: 7px 10px; border-top: 1px solid var(--border, #E4E4EA); }
.pmx-data .cr { display: flex; justify-content: space-between; gap: 10px; padding: 7px 10px; font-size: 12px; border-top: 1px solid var(--border, #E4E4EA); }
.pmx-data .cr:first-of-type { border-top: 0; }
.pmx-data .cr b { font-variant-numeric: tabular-nums; direction: ltr; }

.pmx-link { align-self: flex-start; }

/* كارت تأكيد الأكشن (المرحلة التانية) — أصفر تحذيري لحد ما يتأكد */
.pmx-act { align-self: stretch; background: #FFFBEB; border: 1.5px solid #F59E0B; border-radius: 12px; overflow: hidden; }
.pmx-act .t { font-size: 12px; font-weight: 800; padding: 8px 10px; color: #92400E; border-bottom: 1px solid #FDE68A; }
.pmx-act .cr { display: flex; justify-content: space-between; gap: 10px; padding: 6px 10px; font-size: 12px; border-top: 1px solid #FDE68A; }
.pmx-act .cr:first-of-type { border-top: 0; }
.pmx-act .cr b { font-variant-numeric: tabular-nums; direction: ltr; }
.pmx-act .warn { font-size: 10.5px; color: #92400E; padding: 6px 10px; }
.pmx-act .btns { display: flex; gap: 8px; padding: 8px 10px; border-top: 1px solid #FDE68A; }
.pmx-act .btns button { flex: 1; border: 0; border-radius: 9px; padding: 8px; cursor: pointer;
    font-weight: 800; font-size: 12px; font-family: inherit; }
.pmx-act .ok-btn { background: #16A34A; color: #fff; }
.pmx-act .no-btn { background: #E4E4EA; color: #12121A; }
.pmx-act .done { padding: 9px 10px; font-size: 12px; font-weight: 700; }
.pmx-act.confirmed { border-color: #16A34A; background: #F0FDF4; }
.pmx-act.confirmed .t { color: #166534; border-color: #BBF7D0; }
.pmx-act.cancelled { opacity: .55; }

/* شيبس الاقتراحات — بتعلّم المستخدم نطاق المساعد */
.pmx-chips { display: flex; gap: 6px; flex-wrap: wrap; padding: 8px 12px 0; }
.pmx-chips button {
    border: 1px solid var(--border, #E4E4EA); background: #fff; border-radius: 999px;
    padding: 5px 11px; font-size: 11px; cursor: pointer; color: var(--royal-blue, #12399B);
    font-family: inherit; font-weight: 600;
}
.pmx-chips button:hover { background: #F2F5FF; border-color: var(--royal-blue, #12399B); }

/* زرار المايك — بيختفي لو المتصفح مش داعم */
#pmxMic { border: 1px solid var(--border, #E4E4EA); background: #fff; border-radius: 10px;
    padding: 0 12px; cursor: pointer; font-size: 16px; }
#pmxMic.rec { background: #FDE8E8; border-color: #DC2626; animation: pmxRec 1s infinite; }
@keyframes pmxRec { 50% { opacity: .55; } }

/* مؤشر التحميل — تلات نقط */
.pmx-wait { align-self: flex-start; display: flex; gap: 5px; padding: 12px 14px; }
.pmx-wait i { width: 7px; height: 7px; border-radius: 50%; background: var(--royal-blue, #12399B);
    animation: pmxP 1s infinite; opacity: .35; }
.pmx-wait i:nth-child(2) { animation-delay: .18s; }
.pmx-wait i:nth-child(3) { animation-delay: .36s; }
@keyframes pmxP { 40% { opacity: 1; transform: translateY(-3px); } }

/* الحالة الفاضية */
.pmx-empty { margin: auto; text-align: center; color: var(--muted, #6B6B7B); font-size: 12px;
    padding: 24px; line-height: 1.8; }
.pmx-empty .ic { font-size: 34px; }

#pmxAgent .pmx-foot { display: flex; gap: 8px; padding: 10px 12px; border-top: 1px solid var(--border, #E4E4EA); }
#pmxAgent .pmx-foot input { flex: 1; border: 1px solid var(--border, #E4E4EA); border-radius: 10px;
    padding: 9px 12px; font-size: 12.5px; font-family: inherit; }
#pmxAgent .pmx-foot input:focus { outline: 2px solid rgba(18, 57, 155, .14); border-color: var(--royal-blue, #12399B); }
#pmxAgent .pmx-foot button {
    border: 0; border-radius: 10px; padding: 9px 16px; cursor: pointer; font-weight: 800; font-size: 12.5px;
    background: linear-gradient(135deg, #12399B 0%, #602D90 100%); color: #fff; font-family: inherit;
}
#pmxAgent .pmx-foot button:disabled { opacity: .5; cursor: default; }
</style>

<button id="pmxAgentBtn" type="button" aria-label="{{ __('agent.open_btn_label') }}">💬</button>

<div id="pmxAgent" aria-hidden="true">
    <div class="pmx-head">
        <div style="flex:1">
            <b>⚡ {{ __('agent.title') }}</b>
            <div class="sub">{{ __('agent.subtitle') }}</div>
        </div>
        <button type="button" id="pmxNew">{{ __('agent.new_chat') }}</button>
        <button type="button" id="pmxClose" aria-label="{{ __('agent.close') }}">✕</button>
    </div>

    <div id="pmxMsgs">
        <div class="pmx-empty" id="pmxEmpty">
            <div class="ic">🤝</div>
            {{ __('agent.empty_hint') }}
        </div>
    </div>

    {{-- شيبس اقتراحات — بتملى الخانة وتبعت على طول --}}
    <div class="pmx-chips" id="pmxChips">
        <button type="button">{{ __('agent.chip_expiry') }}</button>
        <button type="button">{{ __('agent.chip_aging') }}</button>
        <button type="button">{{ __('agent.chip_sales') }}</button>
        <button type="button">{{ __('agent.chip_attendance') }}</button>
    </div>

    <form class="pmx-foot" id="pmxForm">
        <button type="button" id="pmxMic" aria-label="{{ __('agent.mic_label') }}" style="display:none">🎤</button>
        <input type="text" id="pmxInput" maxlength="2000"
               placeholder="{{ __('agent.placeholder') }}" autocomplete="off">
        <button type="submit" id="pmxSend">{{ __('agent.send') }}</button>
    </form>
</div>

@php
    // ⚠️ ممنوع دايركتيف الـjson بمصفوفة — json_encode بفلاجات الهيكس
    // و`originalParameters()` بترمي لو الراوت مش مربوط — نتأكد الأول
    $pmxRoute = request()->route();
    $pmxCtx = json_encode([
        'url' => route('agent.ask'),
        'route' => $pmxRoute?->getName(),
        'params' => (object) ($pmxRoute && $pmxRoute->hasParameters()
            ? $pmxRoute->originalParameters() : []),
        'thinking' => __('agent.thinking'),
        'errGeneric' => __('agent.err_generic'),
        'placeholder' => __('agent.placeholder'),
        'pwPlaceholder' => __('agent.pw_placeholder'),
        'pwHint' => __('agent.pw_hint'),
        'emptyHint' => __('agent.empty_hint'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

<script>
(function () {
    'use strict';

    const CTX = {!! $pmxCtx !!};
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    const panel = document.getElementById('pmxAgent');
    const msgs = document.getElementById('pmxMsgs');
    const input = document.getElementById('pmxInput');
    const sendBtn = document.getElementById('pmxSend');

    let convId = Number(sessionStorage.getItem('pmxAgentConv') || 0) || null;
    let busy = false;

    /* ═══ قفل كلمة السر (٧/٩) — الحُكم في السيرفر، ده UI بس ═══ */
    function setLockUi(on) {
        sessionStorage.setItem('pmxLocked', on ? '1' : '0');
        input.placeholder = on ? CTX.pwPlaceholder : CTX.placeholder;
        const chips = document.getElementById('pmxChips');
        if (chips) chips.style.display = on ? 'none' : '';
        const empty = document.getElementById('pmxEmpty');
        if (empty) {
            empty.querySelector('.ic').textContent = on ? '🔒' : '🤝';
            empty.childNodes.forEach(function (n) {
                if (n.nodeType === 3 && n.textContent.trim() !== '') {
                    n.textContent = on ? CTX.pwHint : CTX.emptyHint;
                }
            });
        }
    }

    if (sessionStorage.getItem('pmxLocked') === '1') setLockUi(true);

    document.getElementById('pmxAgentBtn').addEventListener('click', function () {
        panel.classList.toggle('on');
        panel.setAttribute('aria-hidden', panel.classList.contains('on') ? 'false' : 'true');
        if (panel.classList.contains('on')) input.focus();
    });
    document.getElementById('pmxClose').addEventListener('click', function () {
        panel.classList.remove('on');
        panel.setAttribute('aria-hidden', 'true');
    });
    document.getElementById('pmxNew').addEventListener('click', function () {
        convId = null;
        sessionStorage.removeItem('pmxAgentConv');
        msgs.querySelectorAll(':scope > *:not(#pmxEmpty)').forEach(e => e.remove());
        const empty = document.getElementById('pmxEmpty');
        if (empty) empty.style.display = '';
        input.focus();
    });

    function hideEmpty() {
        const empty = document.getElementById('pmxEmpty');
        if (empty) empty.style.display = 'none';
    }

    function scrollDown() { msgs.scrollTop = msgs.scrollHeight; }

    /* فقاعة نص — textContent دايماً، مفيش HTML من الموديل */
    function bubble(cls, text) {
        const d = document.createElement('div');
        d.className = 'pmx-b ' + cls;
        d.textContent = text;
        msgs.appendChild(d);
        scrollDown();
        return d;
    }

    /* بلوك البيانات: جدول أو كارت — كله textContent */
    function dataBlock(data) {
        if (!data) return;

        const box = document.createElement('div');
        box.className = 'pmx-data';

        if (data.title) {
            const t = document.createElement('div');
            t.className = 't';
            t.textContent = data.title;
            box.appendChild(t);
        }

        if (data.type === 'table') {
            const wrap = document.createElement('div');
            wrap.className = 'wrap';
            const table = document.createElement('table');
            const thead = document.createElement('thead');
            const hr = document.createElement('tr');
            (data.columns || []).forEach(function (c) {
                const th = document.createElement('th');
                th.textContent = c;
                hr.appendChild(th);
            });
            thead.appendChild(hr);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            (data.rows || []).forEach(function (row) {
                const tr = document.createElement('tr');
                row.forEach(function (cell) {
                    const td = document.createElement('td');
                    const v = String(cell === null || cell === undefined ? '—' : cell);
                    td.textContent = v;
                    // خلية رقمية؟ (أرقام وفواصل ونقط وشرط) — LTR وتابيولار
                    if (/^[\d,.—\-]+$/.test(v.trim())) td.className = 'n';
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            wrap.appendChild(table);
            box.appendChild(wrap);
        } else if (data.type === 'card') {
            (data.rows || []).forEach(function (pair) {
                const r = document.createElement('div');
                r.className = 'cr';
                const l = document.createElement('span');
                l.textContent = pair[0];
                const v = document.createElement('b');
                v.textContent = pair[1];
                r.appendChild(l);
                r.appendChild(v);
                box.appendChild(r);
            });
        }

        if (data.footer) {
            const f = document.createElement('div');
            f.className = 'f';
            f.textContent = data.footer;
            box.appendChild(f);
        }

        msgs.appendChild(box);
        scrollDown();
    }

    /* ═══ كارت تأكيد الأكشن (المرحلة التانية ٧/٩) ═══
       التنفيذ بيحصل في السيرفر وقت ضغطة التأكيد بس — الزرارين
       بيتقفلوا فوراً عشان مايتداسش مرتين */
    function actionCard(act) {
        if (!act || !act.action_id) return;

        const box = document.createElement('div');
        box.className = 'pmx-act';

        const t = document.createElement('div');
        t.className = 't';
        t.textContent = '⚠️ ' + (act.title || '');
        box.appendChild(t);

        (act.rows || []).forEach(function (pair) {
            const r = document.createElement('div');
            r.className = 'cr';
            const l = document.createElement('span');
            l.textContent = pair[0];
            const v = document.createElement('b');
            v.textContent = pair[1];
            r.appendChild(l);
            r.appendChild(v);
            box.appendChild(r);
        });

        if (act.warn) {
            const w = document.createElement('div');
            w.className = 'warn';
            w.textContent = act.warn;
            box.appendChild(w);
        }

        const btns = document.createElement('div');
        btns.className = 'btns';

        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'ok-btn';
        ok.textContent = '✓ ' + (act.confirm_label || 'OK');

        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'no-btn';
        no.textContent = act.cancel_label || 'X';

        async function decide(url, confirmed) {
            ok.disabled = no.disabled = true;
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json'},
                });
                const body = await r.json().catch(() => ({}));
                btns.remove();
                const d = document.createElement('div');
                d.className = 'done';
                d.textContent = body.message || '';
                d.style.color = (r.ok && confirmed) ? '#166534' : (r.ok ? '#6B6B7B' : '#B91C1C');
                box.appendChild(d);
                box.classList.add(r.ok && confirmed ? 'confirmed' : 'cancelled');
            } catch (_) {
                ok.disabled = no.disabled = false;
                bubble('err', CTX.errGeneric);
            }
        }

        ok.addEventListener('click', () => decide(act.confirm_url, true));
        no.addEventListener('click', () => decide(act.cancel_url, false));

        btns.appendChild(ok);
        btns.appendChild(no);
        box.appendChild(btns);

        msgs.appendChild(box);
        scrollDown();
    }

    /* زرار «افتح في السيستم» — الرابط جاي من السيرفر (route()) مش من الموديل */
    function linkBtn(link) {
        if (!link || !link.url) return;
        const a = document.createElement('a');
        a.className = 'btn sm pmx-link';
        a.href = link.url;
        a.textContent = '↗ ' + (link.label || '');
        msgs.appendChild(a);
        scrollDown();
    }

    async function send(text) {
        hideEmpty();
        bubble('user', text);

        const wait = document.createElement('div');
        wait.className = 'pmx-wait';
        wait.innerHTML = '<i></i><i></i><i></i>';
        wait.setAttribute('title', CTX.thinking);
        msgs.appendChild(wait);
        scrollDown();

        busy = true;
        sendBtn.disabled = true;

        try {
            const r = await fetch(CTX.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    message: text,
                    conversation_id: convId,
                    current_route: CTX.route,
                    route_params: CTX.params,
                }),
            });

            const body = await r.json().catch(() => ({}));
            wait.remove();

            if (!r.ok) {
                bubble('err', body.message || CTX.errGeneric);
                return;
            }

            /* ═══ ردود القفل (٧/٩) — قبل أي عرض عادي ═══ */
            if (body.kick) {
                // كلمة سر غلط: البانل بيتقفل والمحادثة بتتمسح —
                // ومايفتحش يرد تاني غير بكلمة السر في البوكس
                bubble('err', body.text || '');
                setLockUi(true);
                convId = null;
                sessionStorage.removeItem('pmxAgentConv');
                setTimeout(function () {
                    msgs.querySelectorAll(':scope > *:not(#pmxEmpty)').forEach(e => e.remove());
                    const empty = document.getElementById('pmxEmpty');
                    if (empty) empty.style.display = '';
                    panel.classList.remove('on');
                    panel.setAttribute('aria-hidden', 'true');
                }, 1200);
                return;
            }

            if (body.locked) {
                bubble('bot', body.text || '');
                setLockUi(true);
                return;
            }

            if (body.unlocked) {
                bubble('bot', body.text || '');
                setLockUi(false);
                return;
            }

            convId = body.conversation_id;
            sessionStorage.setItem('pmxAgentConv', String(convId));

            bubble('bot', body.text || '');
            dataBlock(body.data);
            linkBtn(body.link);
            actionCard(body.action);
        } catch (_) {
            wait.remove();
            bubble('err', CTX.errGeneric);
        } finally {
            busy = false;
            sendBtn.disabled = false;
            input.focus();
        }
    }

    document.getElementById('pmxForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const text = input.value.trim();
        if (!text || busy) return;
        input.value = '';
        send(text);
    });

    /* ═══ شيبس الاقتراحات — كليك = ابعت على طول ═══ */
    document.querySelectorAll('#pmxChips button').forEach(function (chip) {
        chip.addEventListener('click', function () {
            if (busy) return;
            send(chip.textContent.trim());
        });
    });

    /* ═══ المايك — Web Speech API (بيختفي لو المتصفح مش داعم) ═══ */
    (function () {
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return;

        const mic = document.getElementById('pmxMic');
        mic.style.display = '';

        let rec = null;

        mic.addEventListener('click', function () {
            if (rec) { rec.stop(); return; }

            rec = new SR();
            rec.lang = document.documentElement.lang === 'ar' ? 'ar-EG' : 'en-US';
            rec.interimResults = false;
            rec.maxAlternatives = 1;

            rec.onresult = function (e) {
                const said = e.results[0][0].transcript.trim();
                if (said) { input.value = said; input.focus(); }
            };
            rec.onend = function () { mic.classList.remove('rec'); rec = null; };
            rec.onerror = function () { mic.classList.remove('rec'); rec = null; };

            mic.classList.add('rec');
            rec.start();
        });
    })();
})();
</script>
