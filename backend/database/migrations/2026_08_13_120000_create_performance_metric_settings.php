<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_metric_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 24);
            $table->string('metric_key', 48);
            $table->string('label_ar');
            $table->decimal('weight', 6, 2);
            $table->decimal('target', 14, 2);
            $table->string('direction', 8)->default('higher');
            $table->unsignedInteger('minimum_sample')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['category', 'metric_key']);
        });

        $now = now();
        $rows = [
            ['technicians', 'completed_visits', 'الزيارات المنجزة', 10, 20, 'higher', 4],
            ['technicians', 'sla_rate', 'الالتزام بوقت SLA', 20, 95, 'higher', 5],
            ['technicians', 'first_time_fix', 'الإصلاح من أول زيارة', 20, 90, 'higher', 5],
            ['technicians', 'customer_rating', 'رضا العميل', 15, 90, 'higher', 3],
            ['technicians', 'documentation', 'اكتمال التقرير والأدلة', 15, 95, 'higher', 5],
            ['technicians', 'completion_rate', 'إنجاز الزيارات المسندة', 10, 95, 'higher', 5],
            ['technicians', 'cost_per_visit', 'ضبط تكلفة الزيارة', 10, 350, 'lower', 4],

            ['supervisors', 'team_sla', 'التزام الفريق بـ SLA', 20, 95, 'higher', 8],
            ['supervisors', 'team_first_time_fix', 'إصلاح الفريق من أول زيارة', 15, 90, 'higher', 8],
            ['supervisors', 'team_rating', 'رضا عملاء الفريق', 15, 90, 'higher', 5],
            ['supervisors', 'complaint_response', 'معالجة الشكاوى خلال 24 ساعة', 20, 90, 'higher', 2],
            ['supervisors', 'operational_actions', 'الإجراءات الإشرافية الموثقة', 15, 12, 'higher', 3],
            ['supervisors', 'overdue_control', 'السيطرة على الزيارات المتأخرة', 15, 95, 'higher', 8],

            ['branches', 'sla_rate', 'الالتزام بوقت SLA', 15, 95, 'higher', 10],
            ['branches', 'first_time_fix', 'الإصلاح من أول زيارة', 15, 90, 'higher', 10],
            ['branches', 'customer_rating', 'رضا العملاء', 15, 90, 'higher', 6],
            ['branches', 'collection_rate', 'نسبة التحصيل المستحق', 20, 95, 'higher', 3],
            ['branches', 'profit_margin', 'هامش المساهمة التشغيلي', 20, 20, 'higher', 5],
            ['branches', 'completion_rate', 'إنجاز الزيارات المجدولة', 15, 95, 'higher', 10],

            ['marketers', 'lead_conversion', 'تحويل العملاء المحتملين', 25, 30, 'higher', 5],
            ['marketers', 'quote_acceptance', 'قبول عروض الأسعار', 20, 35, 'higher', 4],
            ['marketers', 'won_value', 'قيمة المبيعات المعتمدة', 20, 100000, 'higher', 1],
            ['marketers', 'collections', 'التحصيل المنسوب للمبيعات', 15, 75000, 'higher', 1],
            ['marketers', 'follow_up', 'أنشطة المتابعة', 10, 20, 'higher', 5],
            ['marketers', 'pipeline_hygiene', 'وجود خطوة متابعة قادمة', 10, 90, 'higher', 3],
        ];

        DB::table('performance_metric_settings')->insert(array_map(fn (array $row): array => [
            'category' => $row[0], 'metric_key' => $row[1], 'label_ar' => $row[2],
            'weight' => $row[3], 'target' => $row[4], 'direction' => $row[5],
            'minimum_sample' => $row[6], 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ], $rows));
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_metric_settings');
    }
};
