{{-- دخول الويب/الأبلكيشن للرول المخصص — `$web` / `$app` القيم الحالية --}}
<div class="frow" style="align-items:flex-start">
    <label style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px;line-height:1.6">
        <input type="checkbox" name="web_login" value="1" @checked($web) style="margin-top:4px">
        <span><b>💻 {{ __('perm.web_login') }}</b><br><span class="pm-muted">{{ __('perm.web_login_hint') }}</span></span>
    </label>
    <label style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px;line-height:1.6">
        <input type="checkbox" name="app_login" value="1" @checked($app) style="margin-top:4px">
        <span><b>📱 {{ __('perm.app_login') }}</b><br><span class="pm-muted">{{ __('perm.app_login_hint') }}</span></span>
    </label>
</div>
