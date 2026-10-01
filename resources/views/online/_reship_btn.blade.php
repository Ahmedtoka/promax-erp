{{--
    زرار «إعادة شحن» (١/١٠) — للأوردر اللي رجع كله بس، وللتيم (admin,manager)
    زي الراوت بالظبط. الشاشات: كل الأوردرات · شيت البيك اب · المرتجعات.
--}}
@if ($o->status === 'returned' && in_array(auth()->user()->role, ['admin', 'manager'], true))
    <form method="POST" action="{{ route('online.reship', $o) }}" style="display:inline"
          onsubmit="if (!confirm(@js(__('online.reship_confirm', ['number' => $o->number])))) return false; this.querySelector('button').disabled = true;">
        @csrf
        <button class="btn sm gold" type="submit">🔁 {{ __('online.act_reship') }}</button>
    </form>
@endif
