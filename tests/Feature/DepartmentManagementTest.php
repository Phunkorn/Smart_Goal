<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_departments_with_reference_counts_and_navigation(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'Operations']);
        $employee = $this->user('user', $department);
        $this->workOrder($employee, $department);

        $response = $this->actingAs($admin)
            ->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee('จัดการแผนก')
            ->assertSee('Operations')
            ->assertSee(route('admin.departments.index'), false)
            ->assertSee('delete-department-form', false)
            ->assertSee('Swal.fire', false)
            ->assertSee('ยืนยันการลบแผนก')
            ->assertDontSee('window.confirm', false)
            ->assertDontSee('return confirm(', false);

        $listedDepartment = $response->viewData('departments')->firstWhere('id', $department->id);

        $this->assertSame(1, $listedDepartment->users_count);
        $this->assertSame(1, $listedDepartment->jobs_count);
    }

    public function test_user_and_viewer_cannot_access_any_department_management_route(): void
    {
        $department = Department::create(['department_name' => 'Finance']);

        foreach (['user', 'viewer'] as $role) {
            $actor = $this->user($role);

            $this->actingAs($actor)->get(route('admin.departments.index'))->assertForbidden();
            $this->actingAs($actor)->post(route('admin.departments.store'), [
                'department_name' => 'Blocked '.$role,
            ])->assertForbidden();
            $this->actingAs($actor)->patch(route('admin.departments.update', $department), [
                'department_name' => 'Blocked rename',
            ])->assertForbidden();
            $this->actingAs($actor)->delete(route('admin.departments.destroy', $department))->assertForbidden();
        }

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'department_name' => 'Finance',
        ]);
    }

    public function test_non_admin_sidebar_does_not_show_department_management_navigation(): void
    {
        $this->actingAs($this->user('viewer'))
            ->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee(route('admin.departments.index'), false);
    }

    public function test_admin_can_create_a_trimmed_department_and_audit_it(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), [
                'department_name' => '  Customer Success  ',
            ])
            ->assertRedirect(route('admin.departments.index'))
            ->assertSessionHas('success');

        $department = Department::where('department_name', 'Customer Success')->firstOrFail();
        $log = ActivityLog::where('action', 'created')
            ->where('subject_type', Department::class)
            ->where('subject_id', $department->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Customer Success', $log->changes['after']['department_name']);

        $this->actingAs($admin)
            ->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee("icon: 'success'", false);
    }

    public function test_department_name_is_required_and_limited_to_schema_length(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), ['department_name' => '   '])
            ->assertSessionHasErrors('department_name');

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), ['department_name' => str_repeat('a', 256)])
            ->assertSessionHasErrors('department_name');

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), ['department_name' => ['not-a-string']])
            ->assertSessionHasErrors('department_name');

        $this->assertDatabaseCount('departments', 0);
    }

    public function test_duplicate_department_name_is_rejected_case_insensitively_after_trimming(): void
    {
        $admin = $this->user('admin');
        Department::create(['department_name' => 'IT Support']);

        $this->actingAs($admin)
            ->post(route('admin.departments.store'), [
                'department_name' => '  it support ',
            ])
            ->assertSessionHasErrors('department_name');

        $this->actingAs($admin)
            ->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee("icon: 'error'", false);

        $this->assertDatabaseCount('departments', 1);
    }

    public function test_update_ignores_current_record_but_rejects_another_department_name(): void
    {
        $admin = $this->user('admin');
        $first = Department::create(['department_name' => 'Operations']);
        $second = Department::create(['department_name' => 'Finance']);

        $this->actingAs($admin)
            ->patch(route('admin.departments.update', $first), ['department_name' => ' operations '])
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($admin)
            ->patch(route('admin.departments.update', $first), ['department_name' => ' FINANCE '])
            ->assertSessionHasErrors('department_name');

        $this->actingAs($admin)
            ->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee("icon: 'error'", false);

        $this->assertDatabaseHas('departments', ['id' => $first->id, 'department_name' => 'operations']);
        $this->assertDatabaseHas('departments', ['id' => $second->id, 'department_name' => 'Finance']);
    }

    public function test_rename_preserves_department_and_foreign_keys_and_relations_show_new_name(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'Marketing']);
        $employee = $this->user('user', $department);
        $workOrder = $this->workOrder($employee, $department);

        $this->actingAs($admin)
            ->patch(route('admin.departments.update', $department), [
                'department_name' => 'Digital Marketing',
            ])
            ->assertRedirect(route('admin.departments.index'));

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'department_name' => 'Digital Marketing',
        ]);
        $this->assertSame($department->id, $employee->fresh()->department_id);
        $this->assertSame($department->id, $workOrder->fresh()->department_id);
        $this->assertSame('Digital Marketing', $employee->fresh()->department->department_name);
        $this->assertSame('Digital Marketing', $workOrder->fresh()->department->department_name);

        $log = ActivityLog::where('action', 'updated')
            ->where('subject_type', Department::class)
            ->where('subject_id', $department->id)
            ->firstOrFail();

        $this->assertSame('Marketing', $log->changes['before']['department_name']);
        $this->assertSame('Digital Marketing', $log->changes['after']['department_name']);

        $this->actingAs($admin)
            ->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee("icon: 'success'", false);
    }

    public function test_empty_department_can_be_deleted_and_is_audited(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'Temporary']);
        $departmentId = $department->id;

        $this->actingAs($admin)
            ->delete(route('admin.departments.destroy', $department))
            ->assertRedirect(route('admin.departments.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('departments', ['id' => $departmentId]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'deleted',
            'subject_type' => Department::class,
            'subject_id' => $departmentId,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee("icon: 'success'", false);
    }

    public function test_department_with_user_cannot_be_deleted_or_null_the_relationship(): void
    {
        $admin = $this->user('admin');
        $department = Department::create(['department_name' => 'People']);
        $employee = $this->user('user', $department);

        $this->actingAs($admin)
            ->delete(route('admin.departments.destroy', $department))
            ->assertSessionHasErrors('department');

        $this->actingAs($admin)
            ->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee("icon: 'error'", false);

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
        $this->assertSame($department->id, $employee->fresh()->department_id);
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'deleted',
            'subject_type' => Department::class,
            'subject_id' => $department->id,
        ]);
    }

    public function test_department_with_work_order_history_cannot_be_deleted_or_null_the_relationship(): void
    {
        $admin = $this->user('admin');
        $employee = $this->user('user');
        $department = Department::create(['department_name' => 'Historical Work']);
        $workOrder = $this->workOrder($employee, $department);
        $workOrder->delete();

        $this->actingAs($admin)
            ->delete(route('admin.departments.destroy', $department))
            ->assertSessionHasErrors('department');

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
        $this->assertSame($department->id, WorkOrder::withTrashed()->findOrFail($workOrder->job_id)->department_id);
    }

    public function test_new_department_is_available_to_employee_form_and_invalid_department_is_rejected(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('admin.departments.store'), [
            'department_name' => 'New Department',
        ]);

        $department = Department::where('department_name', 'New Department')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertViewHas('departments', fn ($departments) => $departments->contains('id', $department->id))
            ->assertSee('New Department');

        $this->actingAs($admin)
            ->post(route('employees.store'), [
                'name' => 'Invalid Department User',
                'username' => 'invalid-department-user',
                'email' => 'invalid-department@example.com',
                'password' => 'StrongPassword1!',
                'password_confirmation' => 'StrongPassword1!',
                'role' => 'user',
                'is_active' => true,
                'department_id' => 999999,
            ])
            ->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('users', ['email' => 'invalid-department@example.com']);
    }

    private function user(string $role, ?Department $department = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $department?->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function workOrder(User $assignee, Department $department): WorkOrder
    {
        return WorkOrder::create([
            'user_id' => $assignee->id,
            'department_id' => $department->id,
            'job_topic' => 'Department-linked task',
            'job_start_at' => now(),
            'job_due_at' => now()->addDay(),
        ]);
    }
}
