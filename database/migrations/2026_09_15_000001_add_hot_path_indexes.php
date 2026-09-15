<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إندكسات المسارات الحارّة (تدقيق الأداء ١٥ سبتمبر ٢٠٢٦).
 *
 * كل إندكس على الأعمدة اللي الكويريات بتفلتر بيها فعلاً: كشف الحساب
 * والأعمار (`transactions` بالعميل والتاريخ)، تحصيلات الـKPI والداشبورد
 * (`kind` + المصدر + التاريخ)، آخر زيارة لكل عميل في بوت ستراب المناطق،
 * جرس الإشعارات، التراكينج، وأوامر التوريد بالمندوب والحالة.
 *
 * ⚠️ محروس بالكامل (`hasTable` + `hasIndex`) — اللايف بيتحدّث بإيد المالك.
 */
return new class extends Migration
{
    /** [table, columns, name] */
    private const INDEXES = [
        ['transactions', ['client_id', 'date'], 'transactions_client_id_date_index'],
        ['transactions', ['kind', 'source_type', 'created_at'], 'transactions_kind_source_created_index'],
        ['invoices', ['client_id', 'created_at'], 'invoices_client_id_created_at_index'],
        ['visits', ['client_id', 'checked_in_at'], 'visits_client_id_checked_in_at_index'],
        ['app_notifications', ['user_id', 'created_at'], 'app_notifications_user_created_index'],
        ['app_notifications', ['user_id', 'read_at'], 'app_notifications_user_read_index'],
        ['track_events', ['user_id', 'happened_at'], 'track_events_user_happened_index'],
        ['purchase_orders', ['assigned_to', 'status', 'delivered_at'], 'purchase_orders_assigned_status_delivered_index'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $cols, $name]) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                continue;
            }
            foreach ($cols as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue 2;
                }
            }
            Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$table, $cols, $name]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
