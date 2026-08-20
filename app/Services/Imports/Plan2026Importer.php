<?php

namespace App\Services\Imports;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Support\AuditTrail;
use App\Support\WorkOrderApprovalResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Plan2026Importer
{
    public const PROJECT_AUDIT_ACTION = 'import_2026_plan_project';

    public const TASK_AUDIT_ACTION = 'import_2026_plan_task';

    /**
     * @param  array<string, mixed>  $workbook
     * @return array<string, mixed>
     */
    public function preflight(array $workbook, string $adminEmail): array
    {
        $errors = [];
        $admin = $this->findUser($adminEmail);

        if (! $admin) {
            $errors[] = "ไม่พบบัญชี Admin: {$adminEmail}";
        } else {
            $this->validateAccount($admin, 'admin', 'Admin importer', $errors);
        }

        $requiredEmails = collect($workbook['projects'])
            ->flatMap(fn (array $project) => $project['tasks'])
            ->flatMap(fn (array $task) => [$task['owner_email'], ...$task['collaborator_emails']])
            ->filter()
            ->unique()
            ->values();
        $users = collect();

        foreach ($requiredEmails as $email) {
            $user = $this->findUser((string) $email);

            if (! $user) {
                $errors[] = "ไม่พบบัญชี User prerequisite: {$email}";

                continue;
            }

            $this->validateAccount($user, 'user', "User {$email}", $errors);
            $users->put(strtolower((string) $email), $user);
        }

        $expectedKeys = $this->expectedImportKeys($workbook);
        $existingKeyCounts = ActivityLog::query()
            ->whereIn('action', [self::PROJECT_AUDIT_ACTION, self::TASK_AUDIT_ACTION])
            ->get(['changes'])
            ->map(fn (ActivityLog $log) => $log->changes['import_key'] ?? null)
            ->filter()
            ->countBy();
        $matchedKeyCount = collect($expectedKeys)
            ->filter(fn (string $key) => $existingKeyCounts->has($key))
            ->count();
        $hasDuplicateKey = collect($expectedKeys)
            ->contains(fn (string $key) => (int) $existingKeyCounts->get($key, 0) !== 1 && $existingKeyCounts->has($key));
        $state = 'fresh';

        if ($matchedKeyCount === count($expectedKeys) && ! $hasDuplicateKey) {
            $state = 'complete';
        } elseif ($matchedKeyCount > 0 || $hasDuplicateKey) {
            $state = 'partial';
            $errors[] = sprintf(
                'พบ import key เพียงบางส่วนหรือซ้ำ (%d/%d) จึงไม่สามารถ resume อัตโนมัติได้',
                $matchedKeyCount,
                count($expectedKeys)
            );
        }

        if ($state === 'fresh') {
            $projectNames = collect($workbook['projects'])
                ->pluck('name')
                ->map(fn (string $name) => mb_strtolower(trim($name)))
                ->all();
            $conflicts = WorkOrderList::query()
                ->whereIn(DB::raw('LOWER(name)'), $projectNames)
                ->pluck('name')
                ->unique()
                ->values();

            if ($conflicts->isNotEmpty()) {
                $errors[] = 'พบ WorkOrderList ชื่อซ้ำที่ไม่มี import key: '.$conflicts->implode(', ');
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(implode(PHP_EOL, $errors));
        }

        return [
            'state' => $state,
            'admin' => $admin,
            'users' => $users,
            'expected_import_keys' => $expectedKeys,
        ];
    }

    /**
     * @param  array<string, mixed>  $workbook
     * @return array<string, mixed>
     */
    public function import(array $workbook, string $adminEmail): array
    {
        return DB::transaction(function () use ($workbook, $adminEmail): array {
            $preflight = $this->preflight($workbook, $adminEmail);

            if ($preflight['state'] === 'complete') {
                return [
                    'state' => 'no_op',
                    'projects_created' => 0,
                    'tasks_created' => 0,
                    'collaborators_created' => 0,
                ];
            }

            /** @var User $admin */
            $admin = $preflight['admin'];
            $users = $preflight['users'];
            $previousUser = Auth::user();
            $projectsCreated = 0;
            $tasksCreated = 0;
            $collaboratorsCreated = 0;
            $baseSortOrder = (int) WorkOrderList::query()
                ->where('user_id', $admin->id)
                ->max('sort_order');

            Auth::setUser($admin);

            try {
                foreach ($workbook['projects'] as $projectData) {
                    $project = WorkOrderList::create([
                        'user_id' => $admin->id,
                        'name' => $projectData['name'],
                        'priority' => $projectData['priority'],
                        'is_visible' => true,
                        'sort_order' => $baseSortOrder + $projectData['sort_order'],
                    ]);
                    $projectsCreated++;

                    AuditTrail::log(
                        self::PROJECT_AUDIT_ACTION,
                        $project,
                        'นำเข้า Project จาก 2026 PLAN.xlsx: '.$project->name,
                        $this->auditMetadata(
                            $workbook,
                            (string) config('imports.plan_2026.goal_sheet', 'Goals'),
                            $projectData['source_row'],
                            $projectData['source_reference']
                        )
                    );

                    foreach ($projectData['tasks'] as $taskData) {
                        /** @var User $assignee */
                        $assignee = $users->get($taskData['owner_email']);
                        $approval = WorkOrderApprovalResolver::resolve($admin, $assignee);
                        $attributes = [
                            'user_id' => $assignee->id,
                            'created_by' => $admin->id,
                            'assigned_by' => $admin->id,
                            'leader_user_id' => $approval['leader_user_id'],
                            'department_id' => $assignee->department_id,
                            'work_order_list_id' => $project->id,
                            'job_topic' => $taskData['job_topic'],
                            'job_details' => $taskData['job_details'],
                            'job_priority' => $taskData['job_priority'],
                            'job_status' => $taskData['job_status'],
                            'approval_status' => $approval['approval_status'],
                            'approved_by' => $approval['approved_by'],
                            'approved_at' => $approval['approved_at'],
                            'job_progress' => (int) $taskData['job_status'] === 4 ? 100 : 0,
                            'job_start_at' => $taskData['job_start_at'],
                            'job_due_at' => $taskData['job_due_at'],
                            'job_completed_at' => null,
                        ];

                        $workOrder = (int) $taskData['job_status'] === 4
                            ? WorkOrder::withoutEvents(fn () => WorkOrder::create($attributes))
                            : WorkOrder::create($attributes);
                        $tasksCreated++;

                        foreach ($taskData['collaborator_emails'] as $collaboratorEmail) {
                            /** @var User $collaborator */
                            $collaborator = $users->get($collaboratorEmail);
                            $workOrder->collaborators()->attach($collaborator->id, [
                                'added_by' => $admin->id,
                                'status' => 'accepted',
                                'responded_at' => now(),
                            ]);
                            $collaboratorsCreated++;
                        }

                        AuditTrail::log(
                            self::TASK_AUDIT_ACTION,
                            $workOrder,
                            'นำเข้างานย้อนหลังจาก 2026 PLAN.xlsx: '.$workOrder->job_topic,
                            $this->auditMetadata(
                                $workbook,
                                $projectData['source_sheet'],
                                $taskData['source_row'],
                                $taskData['source_reference']
                            )
                        );

                        $this->afterTaskImported($workOrder);
                    }
                }
            } finally {
                if ($previousUser) {
                    Auth::setUser($previousUser);
                } else {
                    Auth::guard()->forgetUser();
                }
            }

            return [
                'state' => 'imported',
                'projects_created' => $projectsCreated,
                'tasks_created' => $tasksCreated,
                'collaborators_created' => $collaboratorsCreated,
            ];
        });
    }

    protected function afterTaskImported(WorkOrder $workOrder): void
    {
        // Test seam for proving that every import write rolls back atomically.
    }

    /**
     * @param  array<string, mixed>  $workbook
     * @return array<int, string>
     */
    public function expectedImportKeys(array $workbook): array
    {
        $keys = [];

        foreach ($workbook['projects'] as $project) {
            $keys[] = $this->makeImportKey(
                $workbook['source_version'],
                (string) config('imports.plan_2026.goal_sheet', 'Goals'),
                $project['source_row']
            );

            foreach ($project['tasks'] as $task) {
                $keys[] = $this->makeImportKey(
                    $workbook['source_version'],
                    $project['source_sheet'],
                    $task['source_row']
                );
            }
        }

        return $keys;
    }

    public function makeImportKey(string $version, string $sheet, int $row): string
    {
        return hash('sha256', implode('|', [$version, $sheet, $row]));
    }

    private function findUser(string $email): ?User
    {
        $normalized = strtolower(trim($email));

        if ($normalized === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->with('department')
            ->first();
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validateAccount(User $user, string $role, string $label, array &$errors): void
    {
        if ($user->role !== $role) {
            $errors[] = "{$label} ต้องมี role={$role}";
        }

        if (! $user->is_active) {
            $errors[] = "{$label} ต้องเป็นบัญชี active";
        }

        $requiredDepartment = mb_strtolower(trim((string) config('imports.plan_2026.department_name', 'IT')));
        $actualDepartment = mb_strtolower(trim((string) $user->department?->department_name));

        if ($actualDepartment !== $requiredDepartment) {
            $errors[] = "{$label} ต้องอยู่แผนก ".config('imports.plan_2026.department_name', 'IT');
        }
    }

    /**
     * @param  array<string, mixed>  $workbook
     * @return array<string, mixed>
     */
    private function auditMetadata(array $workbook, string $sheet, int $row, ?string $reference): array
    {
        return [
            'import_key' => $this->makeImportKey($workbook['source_version'], $sheet, $row),
            'source_version' => $workbook['source_version'],
            'source_hash' => $workbook['source_hash'],
            'source_sheet' => $sheet,
            'source_row' => $row,
            'source_reference' => $reference,
        ];
    }
}
