<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * โปรเจกต์ไม่เคยมีโฟลเดอร์ lang/ เลย และ APP_LOCALE ถูกตั้งเป็น en
 * ผู้ใช้จึงเห็น "The username has already been taken." ในหน้าเพิ่มพนักงานที่เป็น UI ไทย
 * แล้วเข้าใจผิดว่าระบบบังคับรูปแบบชื่อบัญชี ทั้งที่ปัญหาคือชื่อซ้ำ
 */
class ValidationLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
    }

    public function test_application_runs_in_thai_with_an_english_fallback(): void
    {
        $this->assertSame('th', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
    }

    public function test_duplicate_username_reports_a_thai_message_that_names_the_real_problem(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $admin = $this->admin();
        User::factory()->create(['username' => 'beam', 'role' => 'user', 'department_id' => $department->id]);

        $response = $this->actingAs($admin)->post(route('employees.store'), $this->payload($department, 'beam'));

        $message = $response->baseResponse->getSession()->get('errors')->first('username');

        $this->assertSame('บัญชีผู้ใช้งานนี้มีคนใช้แล้ว กรุณาเปลี่ยนเป็นชื่ออื่น', $message);
        $this->assertStringNotContainsString('has already been taken', $message);
    }

    public function test_a_username_without_a_dot_is_accepted_because_the_dot_was_never_required(): void
    {
        $department = Department::create(['department_name' => 'IT']);

        $this->actingAs($this->admin())
            ->post(route('employees.store'), $this->payload($department, 'beam'))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('users', ['username' => 'beam']);
    }

    public function test_other_field_messages_are_thai_too(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $payload = $this->payload($department, 'beam');
        unset($payload['name']);

        $response = $this->actingAs($this->admin())->post(route('employees.store'), $payload);
        $errors = $response->baseResponse->getSession()->get('errors');

        $this->assertSame('กรุณากรอก ชื่อ', $errors->first('name'));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Department $department, string $username): array
    {
        return [
            'name' => 'Beam Tester',
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => 'SmartGoal!12345',
            'role' => 'user',
            'department_id' => $department->id,
            'is_department_head' => 0,
            'is_active' => 1,
        ];
    }
}
