<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_log_templates', function (Blueprint $table): void {
            $table->foreignId('work_order_list_id')->nullable()->after('work_log_category_id')->constrained('work_order_lists')->nullOnDelete();
            $table->unsignedBigInteger('job_id')->nullable()->after('work_order_list_id');
            $table->foreign('job_id')->references('job_id')->on('work_orders')->nullOnDelete();
        });

        Schema::table('work_logs', function (Blueprint $table): void {
            $table->timestamp('planned_start_at')->nullable()->after('work_date');
            $table->timestamp('planned_end_at')->nullable()->after('planned_start_at');
            $table->string('late_start_reason', 500)->nullable()->after('duration_minutes');
            $table->string('late_completion_reason', 500)->nullable()->after('late_start_reason');
            $table->string('skip_reason', 500)->nullable()->after('late_completion_reason');
            $table->timestamp('skipped_at')->nullable()->after('skip_reason');
        });

        // งานแทรกเดิมยังเป็นประวัติที่ต้องเก็บไว้ จึงเปลี่ยนประเภทแทนการลบแถว
        DB::table('work_log_templates')->where('kind', 'interrupt')->update(['kind' => 'routine']);
        DB::table('work_logs')->where('kind', 'interrupt')->update(['kind' => 'routine']);

        // รายการจากแม่แบบรุ่นเดิมเก็บ "เวลาที่วางแผน" ไว้ใน started_at แม้ยังไม่เริ่มทำ
        // ย้ายค่านั้นมาไว้คอลัมน์ใหม่ แล้วคืน started_at ให้เป็นเวลาที่ผู้ใช้กดเริ่มจริง
        $templates = DB::table('work_log_templates')
            ->get(['id', 'default_start_time', 'default_duration_minutes'])
            ->keyBy('id');

        DB::table('work_logs')
            ->whereNotNull('work_log_template_id')
            ->orderBy('id')
            ->chunkById(200, function ($logs) use ($templates): void {
                foreach ($logs as $log) {
                    $template = $templates->get($log->work_log_template_id);
                    $plannedStart = $template?->default_start_time
                        ? CarbonImmutable::parse($log->work_date, 'Asia/Bangkok')
                            ->startOfDay()
                            ->setTimeFromTimeString((string) $template->default_start_time)
                            ->utc()
                        : ($log->started_at ? CarbonImmutable::parse($log->started_at, 'UTC') : null);
                    $plannedEnd = $plannedStart && $template?->default_duration_minutes
                        ? $plannedStart->addMinutes((int) $template->default_duration_minutes)
                        : null;

                    $changes = [
                        'planned_start_at' => $plannedStart?->format('Y-m-d H:i:s'),
                        'planned_end_at' => $plannedEnd?->format('Y-m-d H:i:s'),
                    ];

                    if ($log->status === 'open') {
                        $changes['started_at'] = null;
                        $changes['ended_at'] = null;
                    }

                    DB::table('work_logs')->where('id', $log->id)->update($changes);
                }
            });
    }

    public function down(): void
    {
        Schema::table('work_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'planned_start_at', 'planned_end_at', 'late_start_reason',
                'late_completion_reason', 'skip_reason', 'skipped_at',
            ]);
        });

        Schema::table('work_log_templates', function (Blueprint $table): void {
            $table->dropForeign(['job_id']);
            $table->dropForeign(['work_order_list_id']);
            $table->dropColumn(['job_id', 'work_order_list_id']);
        });
    }
};
