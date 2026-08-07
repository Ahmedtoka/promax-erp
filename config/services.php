<?php

/**
 * ⚠️ **الملف ده اتعمل مخصوص لإعداد فاير بيز (2026-08-07).**
 * مجلد `config/` كان فاضي — لارافيل 12 بيشتغل بالافتراضيات المدمجة،
 * وأي ملف بيتحط هنا بيدوس على الافتراضي بتاعه. فالملف ده لازم يفضل
 * فيه مفاتيح لارافيل الأصلية كمان (postmark/ses/resend/slack) وإلا
 * أي كود بينادي عليها هيلاقيها null.
 */

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | إشعارات فاير بيز (FCM HTTP v1)
    |--------------------------------------------------------------------------
    |
    | `project`     — رقم/اسم المشروع من كونسول فاير بيز (Project ID)
    | `credentials` — مسار مطلق لملف حساب الخدمة JSON على السيرفر
    |
    | ⚠️ **ملف الاعتماد ممنوع يتحط في مجلد المشروع المنشور ولا في Git.**
    | مكانه الصح على Cloudways: `/home/master/applications/<app>/private/`.
    | لو المفتاحين فاضيين، السيستم بيشتغل عادي والإشعارات بتفضل
    | داخلية بس (جرس الأبلكيشن) من غير أي خطأ.
    |
    */
    'fcm' => [
        'project' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'),
    ],

];
