<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\JobImage;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Services\Imports\Plan2026Importer;
use App\Services\Imports\Plan2026WorkbookReader;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class Import2026PlanCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryWorkbooks = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryWorkbooks as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_dry_run_validates_and_maps_without_database_writes(): void
    {
        $this->configureSyntheticWorkbook();
        [, $admin] = $this->createPrerequisiteAccounts();
        $workbook = app(Plan2026WorkbookReader::class)->readConfiguredWorkbook();
        $this->assertSame(5, $workbook['stats']['candidates']);
        $this->assertSame(3, $workbook['stats']['tasks']);
        $this->assertSame(2, $workbook['stats']['skipped']);
        $this->assertSame([
            'missing_owner' => 1,
            'missing_required_date' => 1,
        ], $workbook['stats']['skipped_reasons']);

        $this->artisan('smart-goal:import-2026-plan', [
            '--dry-run' => true,
            '--admin-email' => $admin->email,
        ])
            ->expectsOutputToContain('Task candidates')
            ->expectsOutputToContain('Tasks to import')
            ->expectsOutputToContain('Dry-run สำเร็จ')
            ->assertSuccessful();

        $this->assertDatabaseCount('work_order_lists', 0);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_collaborators', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('system_notifications', 0);
    }

    public function test_actual_import_creates_the_historical_graph_and_keeps_project_identity_visible(): void
    {
        $this->configureSyntheticWorkbook();
        [$department, $admin, $komkrit, $benz] = $this->createPrerequisiteAccounts();

        $this->artisan('smart-goal:import-2026-plan', [
            '--admin-email' => $admin->email,
            '--force' => true,
        ])->expectsOutputToContain('นำเข้าสำเร็จ: 2 Projects, 3 Tasks, 1 collaborator links')
            ->assertSuccessful();

        $this->assertDatabaseCount('work_order_lists', 2);
        $this->assertDatabaseCount('work_orders', 3);
        $this->assertDatabaseCount('work_order_collaborators', 1);
        $this->assertDatabaseCount('activity_logs', 5);
        $this->assertSame(0, SystemNotification::count());
        $this->assertSame(0, JobImage::count());

        $training = WorkOrderList::where('name', 'Training')->firstOrFail();
        $graphic = WorkOrderList::where('name', 'Graphic Design')->firstOrFail();
        $this->assertSame($admin->id, $training->user_id);
        $this->assertSame(3, $training->priority);
        $this->assertSame(2, $graphic->priority);
        $this->assertCount(0, $training->attachments);

        $done = WorkOrder::where('job_topic', 'Duplicate task')->where('job_status', 4)->firstOrFail();
        $wait = WorkOrder::where('job_topic', 'Duplicate task')->where('job_status', 1)->firstOrFail();
        $inProcess = WorkOrder::where('job_topic', 'Graphic queue')->firstOrFail();

        $this->assertSame($training->id, $done->work_order_list_id);
        $this->assertSame($training->id, $wait->work_order_list_id);
        $this->assertSame($graphic->id, $inProcess->work_order_list_id);
        $this->assertSame($komkrit->id, $done->user_id);
        $this->assertSame($benz->id, $inProcess->user_id);
        $this->assertSame($komkrit->department_id, $done->department_id);
        $this->assertSame($admin->id, $done->created_by);
        $this->assertSame($admin->id, $done->assigned_by);
        $this->assertSame('approved', $done->approval_status);
        $this->assertSame($admin->id, $done->approved_by);
        $this->assertSame($komkrit->id, $done->leader_user_id);
        $this->assertSame(100, $done->job_progress);
        $this->assertNull($done->job_completed_at);
        $this->assertSame('2026-06-25', $done->job_start_at->format('Y-m-d'));
        $this->assertSame('2026-07-09', $done->job_due_at->format('Y-m-d'));
        $this->assertStringContainsString('Historical note', (string) $done->job_details);
        $this->assertStringContainsString('https://example.com/task-reference', (string) $done->job_details);
        $this->assertNotNull($done->created_at);
        $this->assertSame(2, WorkOrder::where('job_topic', 'Duplicate task')->count());

        $collaborator = $done->collaborators()->firstOrFail();
        $this->assertSame($benz->id, $collaborator->id);
        $this->assertSame('accepted', $collaborator->pivot->status);
        $this->assertSame($admin->id, $collaborator->pivot->added_by);
        $this->assertNotNull($collaborator->pivot->responded_at);

        $projectLog = ActivityLog::where('action', Plan2026Importer::PROJECT_AUDIT_ACTION)
            ->where('subject_id', $training->id)
            ->firstOrFail();
        $this->assertSame('Goals', $projectLog->changes['source_sheet']);
        $this->assertSame('https://example.com/project-reference', $projectLog->changes['source_reference']);
        $this->assertNotEmpty($projectLog->changes['import_key']);

        $listIds = WorkOrderList::pluck('id')->sort()->values()->all();
        $taskListIds = WorkOrder::pluck('work_order_list_id')->sort()->values()->all();

        $this->actingAs($komkrit)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('Training')
            ->assertSee('Duplicate task');
        $this->actingAs($benz)
            ->get(route('mytasks.index'))
            ->assertOk()
            ->assertSee('Training')
            ->assertSee('Graphic Design');
        $this->actingAs($admin)
            ->get(route('board.index'))
            ->assertOk()
            ->assertViewHas('jobs', fn ($jobs) => $jobs->count() === 3
                && $jobs->contains('job_topic', 'Graphic queue'));
        $this->actingAs($admin)
            ->get(route('admin.work-board.member', [$department, $komkrit]))
            ->assertOk()
            ->assertSee('Training');

        $this->assertSame($listIds, WorkOrderList::pluck('id')->sort()->values()->all());
        $this->assertSame($taskListIds, WorkOrder::pluck('work_order_list_id')->sort()->values()->all());
    }

    public function test_repeated_complete_import_is_a_successful_no_op(): void
    {
        $this->configureSyntheticWorkbook();
        [, $admin] = $this->createPrerequisiteAccounts();

        $arguments = ['--admin-email' => $admin->email, '--force' => true];
        $this->artisan('smart-goal:import-2026-plan', $arguments)->assertSuccessful();
        $counts = [WorkOrderList::count(), WorkOrder::count(), ActivityLog::count()];

        $this->artisan('smart-goal:import-2026-plan', $arguments)
            ->expectsOutputToContain('ถูกนำเข้าครบแล้ว')
            ->assertSuccessful();

        $this->assertSame($counts, [WorkOrderList::count(), WorkOrder::count(), ActivityLog::count()]);
    }

    public function test_partial_import_key_state_is_rejected_without_new_writes(): void
    {
        $this->configureSyntheticWorkbook();
        [, $admin] = $this->createPrerequisiteAccounts();
        $workbook = app(Plan2026WorkbookReader::class)->readConfiguredWorkbook();
        $key = app(Plan2026Importer::class)->expectedImportKeys($workbook)[0];

        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => Plan2026Importer::PROJECT_AUDIT_ACTION,
            'changes' => ['import_key' => $key],
            'created_at' => now(),
        ]);

        $this->artisan('smart-goal:import-2026-plan', [
            '--admin-email' => $admin->email,
            '--force' => true,
        ])
            ->expectsOutputToContain('import key เพียงบางส่วน')
            ->assertFailed();

        $this->assertDatabaseCount('work_order_lists', 0);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_existing_same_named_project_without_import_key_is_a_conflict(): void
    {
        $this->configureSyntheticWorkbook();
        [, $admin] = $this->createPrerequisiteAccounts();
        WorkOrderList::create([
            'user_id' => $admin->id,
            'name' => 'training',
            'priority' => 2,
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        $this->artisan('smart-goal:import-2026-plan', [
            '--admin-email' => $admin->email,
            '--force' => true,
        ])
            ->expectsOutputToContain('WorkOrderList ชื่อซ้ำที่ไม่มี import key')
            ->assertFailed();

        $this->assertDatabaseCount('work_order_lists', 1);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_missing_prerequisite_account_stops_before_writes(): void
    {
        $this->configureSyntheticWorkbook();
        $department = Department::create(['department_name' => 'IT']);
        $admin = User::factory()->create([
            'role' => 'admin',
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $this->artisan('smart-goal:import-2026-plan', [
            '--admin-email' => $admin->email,
            '--force' => true,
        ])
            ->expectsOutputToContain('ไม่พบบัญชี User prerequisite: oamkomkrit@gmail.com')
            ->expectsOutputToContain('ไม่พบบัญชี User prerequisite: rgnr130845@gmail.com')
            ->assertFailed();

        $this->assertDatabaseCount('work_order_lists', 0);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_hash_header_and_sheet_contracts_are_rejected(): void
    {
        $this->configureSyntheticWorkbook();
        config()->set('imports.plan_2026.expected_sha256', str_repeat('0', 64));

        $this->artisan('smart-goal:import-2026-plan', [
            '--dry-run' => true,
            '--admin-email' => 'admin@example.com',
        ])->expectsOutputToContain('SHA-256 ของ workbook ไม่ตรง')->assertFailed();

        $this->configureSyntheticWorkbook(badHeader: true);
        $this->artisan('smart-goal:import-2026-plan', [
            '--dry-run' => true,
            '--admin-email' => 'admin@example.com',
        ])->expectsOutputToContain('Header ไม่ตรง')->assertFailed();

        $this->configureSyntheticWorkbook(omitGraphicSheet: true);
        $this->artisan('smart-goal:import-2026-plan', [
            '--dry-run' => true,
            '--admin-email' => 'admin@example.com',
        ])->expectsOutputToContain('โครงสร้าง sheet ไม่ตรง')->assertFailed();

        $this->assertDatabaseCount('work_order_lists', 0);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_exception_after_a_task_write_rolls_back_the_entire_graph_and_audit(): void
    {
        $this->configureSyntheticWorkbook();
        [, $admin] = $this->createPrerequisiteAccounts();
        $workbook = app(Plan2026WorkbookReader::class)->readConfiguredWorkbook();
        $importer = new class extends Plan2026Importer
        {
            protected function afterTaskImported(WorkOrder $workOrder): void
            {
                throw new RuntimeException('forced import failure');
            }
        };

        try {
            $importer->import($workbook, $admin->email);
            $this->fail('Expected forced import failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced import failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('work_order_lists', 0);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_collaborators', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('system_notifications', 0);
        $this->assertNull(Auth::user());
    }

    /**
     * @return array{Department, User, User, User}
     */
    private function createPrerequisiteAccounts(): array
    {
        $department = Department::create(['department_name' => 'IT']);
        $admin = User::factory()->create([
            'name' => 'Plan Import Admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $komkrit = User::factory()->create([
            'name' => 'Komkrit Lerkparnee',
            'email' => 'oamkomkrit@gmail.com',
            'role' => 'user',
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $benz = User::factory()->create([
            'name' => 'Benz Pholdech',
            'email' => 'rgnr130845@gmail.com',
            'role' => 'user',
            'department_id' => $department->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        return [$department, $admin, $komkrit, $benz];
    }

    private function configureSyntheticWorkbook(bool $badHeader = false, bool $omitGraphicSheet = false): string
    {
        $spreadsheet = new Spreadsheet;
        $goals = $spreadsheet->getActiveSheet();
        $goals->setTitle('Goals');
        $this->writeRow($goals, 1, [
            $badHeader ? 'Wrong header' : 'เป้าหมาย',
            'รายการสำคัญ',
            'เจ้าของ',
            'ผู้ช่วย',
            'สถานะ',
            'วันที่เริ่มต้น',
            'วันที่สิ้นสุด',
            'ไฟล์ที่เกี่ยวข้อง',
            'โน้ต',
        ]);
        $this->writeRow($goals, 2, ['Training', 'สำคัญด่วน']);
        $goals->setCellValue('H2', 'Project source');
        $goals->getCell('H2')->getHyperlink()->setUrl('https://example.com/project-reference');
        $this->writeRow($goals, 3, ['Graphic Design']);

        $training = $spreadsheet->createSheet();
        $training->setTitle('Training');
        $this->writeRow($training, 1, [
            'เป้าหมาย', 'รายการสำคัญ', 'เจ้าของ', 'เจ้าของ 2', 'สถานะ',
            'วันที่เริ่มต้น', 'วันที่สิ้นสุด', 'ไฟล์ที่เกี่ยวข้อง', 'โน้ต',
        ]);
        $this->writeRow($training, 2, [
            'Duplicate task', 'สำคัญด่วน', 'Komkrit Lerkparnee', 'Rg nr', 'DONE',
            ExcelDate::PHPToExcel(new DateTimeImmutable('2026-06-25')),
            ExcelDate::PHPToExcel(new DateTimeImmutable('2026-07-09')),
            'Task source', 'Historical note',
        ]);
        $training->getCell('C2')->getHyperlink()->setUrl('mailto:oamkomkrit@gmail.com');
        $training->getCell('D2')->getHyperlink()->setUrl('mailto:rgnr130845@gmail.com');
        $training->getCell('H2')->getHyperlink()->setUrl('https://example.com/task-reference');
        $this->writeRow($training, 3, [
            'Duplicate task', 'สำคัญ ไม่ด่วน', 'Komkrit Lerkparnee', null, 'WAIT',
            ExcelDate::PHPToExcel(new DateTimeImmutable('2026-07-10')),
            ExcelDate::PHPToExcel(new DateTimeImmutable('2026-07-12')),
        ]);
        $training->getCell('C3')->getHyperlink()->setUrl('mailto:oamkomkrit@gmail.com');
        $this->writeRow($training, 4, [
            'No owner', 'สำคัญด่วน', null, null, 'DONE',
            ExcelDate::PHPToExcel(new DateTimeImmutable('2026-07-10')),
            ExcelDate::PHPToExcel(new DateTimeImmutable('2026-07-12')),
        ]);
        $this->writeRow($training, 5, [
            'Missing due', 'Routine', 'Benz Pholdech', null, 'WAIT',
            ExcelDate::PHPToExcel(new DateTimeImmutable('2026-07-10')),
        ]);
        $training->getCell('C5')->getHyperlink()->setUrl('mailto:rgnr130845@gmail.com');

        if (! $omitGraphicSheet) {
            $graphic = $spreadsheet->createSheet();
            $graphic->setTitle('Graphic Design');
            $this->writeRow($graphic, 1, [
                'เป้าหมาย', 'รายการสำคัญ', 'เจ้าของ', 'สถานะ',
                'วันที่เริ่มต้น', 'วันที่สิ้นสุด', 'ไฟล์ที่เกี่ยวข้อง', 'โน้ต',
            ]);
            $this->writeRow($graphic, 2, [
                'Graphic queue', 'ด่วนไม่ค่อยสำคัญ', 'Rg nr', 'In Process',
                ExcelDate::PHPToExcel(new DateTimeImmutable('2026-08-01')),
                ExcelDate::PHPToExcel(new DateTimeImmutable('2026-08-02')),
            ]);
            $graphic->getCell('C2')->getHyperlink()->setUrl('mailto:rgnr130845@gmail.com');
        }

        File::ensureDirectoryExists(storage_path('app/imports'));
        $filename = 'test-2026-plan-'.Str::uuid().'.xlsx';
        $path = storage_path('app/imports/'.$filename);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryWorkbooks[] = $path;

        config()->set('imports.plan_2026.source_path', 'imports/'.$filename);
        config()->set('imports.plan_2026.expected_sha256', hash_file('sha256', $path));
        config()->set('imports.plan_2026.source_version', '2026-plan-v1-test');
        config()->set('imports.plan_2026.department_name', 'IT');
        config()->set('imports.plan_2026.goal_sheet', 'Goals');
        config()->set('imports.plan_2026.expected_sheets', ['Goals', 'Training', 'Graphic Design']);
        config()->set('imports.plan_2026.projects', [
            [
                'sheet' => 'Training',
                'goal_name' => 'Training',
                'name' => 'Training',
                'collaborator_column' => 'D',
            ],
            [
                'sheet' => 'Graphic Design',
                'goal_name' => 'Graphic Design',
                'name' => 'Graphic Design',
                'collaborator_column' => null,
            ],
        ]);
        config()->set('imports.plan_2026.owner_emails', [
            'oamkomkrit@gmail.com' => 'Komkrit Lerkparnee',
            'rgnr130845@gmail.com' => 'Benz Pholdech',
        ]);

        return $path;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $offset => $value) {
            $column = Coordinate::stringFromColumnIndex($offset + 1);
            $sheet->setCellValue("{$column}{$row}", $value);
        }
    }
}
