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

    <form class="pmx-foot" id="pmxForm">
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

            convId = body.conversation_id;
            sessionStorage.setItem('pmxAgentConv', String(convId));

            bubble('bot', body.text || '');
            dataBlock(body.data);
            linkBtn(body.link);
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
})();
</script>
