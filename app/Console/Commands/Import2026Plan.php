<?php

namespace App\Console\Commands;

use App\Services\Imports\Plan2026Importer;
use App\Services\Imports\Plan2026WorkbookReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class Import2026Plan extends Command
{
    protected $signature = 'smart-goal:import-2026-plan
        {--dry-run : ตรวจ workbook และ prerequisite โดยไม่เขียนฐานข้อมูล}
        {--admin-email= : Email ของ Admin importer}
        {--force : ข้าม confirmation สำหรับการรันแบบ non-interactive}';

    protected $description = 'นำเข้าข้อมูลงานย้อนหลังจาก 2026 PLAN.xlsx แบบตรวจสอบและทำ transaction เดียว';

    public function handle(Plan2026WorkbookReader $reader, Plan2026Importer $importer): int
    {
        $adminEmail = strtolower(trim((string) $this->option('admin-email')));

        if ($adminEmail === '') {
            $this->error('ต้องระบุ --admin-email');

            return self::INVALID;
        }

        try {
            $workbook = $reader->readConfiguredWorkbook();
            $this->renderSummary($workbook);
            $preflight = $importer->preflight($workbook, $adminEmail);
        } catch (Throwable $exception) {
            $this->renderErrors($exception);

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $message = $preflight['state'] === 'complete'
                ? 'Dry-run สำเร็จ: ชุดข้อมูลนี้ถูกนำเข้าครบแล้ว และไม่มีการเขียนฐานข้อมูล'
                : 'Dry-run สำเร็จ: prerequisite ผ่าน และไม่มีการเขียนฐานข้อมูล';
            $this->info($message);

            return self::SUCCESS;
        }

        if ($preflight['state'] === 'complete') {
            $this->info('ชุดข้อมูลนี้ถูกนำเข้าครบแล้ว ไม่มีข้อมูลใหม่ที่ต้องสร้าง');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('ยืนยันนำเข้าข้อมูลทั้งหมดด้วย transaction เดียวหรือไม่?')) {
            $this->warn('ยกเลิกการนำเข้าแล้ว ไม่มีการเขียนฐานข้อมูล');

            return self::SUCCESS;
        }

        $lock = Cache::lock('smart-goal:import-2026-plan', 600);

        if (! $lock->get()) {
            $this->error('มี process นำเข้า 2026 PLAN.xlsx ทำงานอยู่ กรุณาลองใหม่ภายหลัง');

            return self::FAILURE;
        }

        try {
            $result = $importer->import($workbook, $adminEmail);
        } catch (Throwable $exception) {
            $this->renderErrors($exception);

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        if ($result['state'] === 'no_op') {
            $this->info('ชุดข้อมูลนี้ถูกนำเข้าครบแล้ว ไม่มีข้อมูลใหม่ที่ต้องสร้าง');
        } else {
            $this->info(sprintf(
                'นำเข้าสำเร็จ: %d Projects, %d Tasks, %d collaborator links',
                $result['projects_created'],
                $result['tasks_created'],
                $result['collaborators_created']
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $workbook
     */
    private function renderSummary(array $workbook): void
    {
        $stats = $workbook['stats'];
        $this->line('SHA-256: '.$workbook['source_hash']);
        $this->table(
            ['Metric', 'Count'],
            [
                ['Task candidates', $stats['candidates']],
                ['Projects', $stats['projects']],
                ['Tasks to import', $stats['tasks']],
                ['Collaborator links', $stats['collaborators']],
                ['Skipped rows', $stats['skipped']],
            ]
        );

        $ownerLabels = (array) config('imports.plan_2026.owner_emails', []);
        $ownerRows = collect($stats['owners'])
            ->map(fn (int $count, string $email) => [$ownerLabels[$email] ?? $email, $email, $count])
            ->values()
            ->all();

        if ($ownerRows !== []) {
            $this->table(['Owner', 'Email', 'Tasks'], $ownerRows);
        }

        $statusLabels = [1 => 'WAIT', 2 => 'IN PROCESS', 4 => 'DONE', 5 => 'STRUCK!!'];
        $statusRows = collect($stats['statuses'])
            ->sortKeys()
            ->map(fn (int $count, int|string $status) => [$statusLabels[(int) $status] ?? $status, $count])
            ->values()
            ->all();

        if ($statusRows !== []) {
            $this->table(['Status', 'Tasks'], $statusRows);
        }

        if ($workbook['skipped'] !== []) {
            $this->table(
                ['Sheet', 'Row', 'Reason', 'Task'],
                collect($workbook['skipped'])
                    ->map(fn (array $row) => [$row['sheet'], $row['row'], $row['reason'], $row['title']])
                    ->all()
            );
        }

        foreach ($workbook['warnings'] as $warning) {
            $this->warn($warning);
        }
    }

    private function renderErrors(Throwable $exception): void
    {
        foreach (preg_split('/\R/u', $exception->getMessage()) ?: [] as $message) {
            if ($message !== '') {
                $this->error($message);
            }
        }
    }
}
