{{--
    زرار «إعادة شحن» (١/١٠) — للأوردر اللي رجع كله بس، وللتيم (admin,manager)
    زي الراوت بالظبط. الشاشات: كل الأوردرات · شيت البيك اب · المرتجعات.

    ⚠️ بيسأل (٧/١٠): يتجهز من جديد، ولا الطرد في إيدي وجاهز للشحن على طول
    (البضاعة بتتخصم دلوقتي لأن المرتجع كان رجّعها الرف).
--}}
@if ($o->status === 'returned' && in_array(auth()->user()->role, ['admin', 'manager'], true))
    <details class="reship-pick" style="display:inline-block;position:relative">
        <summary class="btn sm gold" style="list-style:none;cursor:pointer">🔁 {{ __('online.act_reship') }}</summary>
        <form method="POST" action="{{ route('online.reship', $o) }}"
              style="position:absolute;inset-inline-end:0;z-index:5;margin-top:4px;background:var(--card,#fff);border:1px solid var(--border);border-radius:10px;padding:8px;display:flex;flex-direction:column;gap:6px;min-width:230px;box-shadow:0 6px 18px rgba(0,0,0,.12)">
            {{-- ⚠️ من غير تعطيل الزراير في onsubmit: الزرار المعطّل مابيبعتش mode فكان هيقع «يتجهز» دايماً.
                 الضغطتين بيتمسكوا في السيرفر (الادعاء الذري من «رجع») --}}
            @csrf
            <div class="dash-hint">{{ __('online.reship_ask', ['number' => $o->number]) }}</div>
            <button class="btn sm" type="submit" name="mode" value="prep">📦 {{ __('online.reship_mode_prep') }}</button>
            <button class="btn sm gold" type="submit" name="mode" value="ready">🚚 {{ __('online.reship_mode_ready') }}</button>
        </form>
    </details>
@endif
