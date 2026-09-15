<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Services\RoutineAccountabilityService;
use App\Services\RoutineAttentionService;
use App\Support\WorkLogWeekdays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ปิดรอบงานประจำ 17:00, เหตุผลย้อนหลัง และการระบุว่าผู้ร่วมงานไม่มา
 *
 * ทุกกติกาตรวจผ่าน endpoint จริง (ไม่ใช่แค่ Swal): เริ่มงานไม่ได้จนกว่าจะตอบครบ
 * วันที่ใช้: จันทร์ 7 – พฤหัส 10 ก.ย. 2026 ตามเวลาไทย
 */
class WorkLogRoutineAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutoff_separates_not_started_from_started_but_not_completed(): void
    {
        $this->at('2026-09-07 07:00');
        $owner = $this->user();
        $this->template($owner, ['title' => 'ตรวจเช็กคอม']);
        $this->template($owner, ['title' => 'สำรองข้อมูล']);

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();
        $backup = $this->logOf($owner, 'สำรองข้อมูล', '2026-09-07');
        $this->startJson($owner, $backup)->assertOk();

        $this->at('2026-09-07 16:59');
        $this->assertSame(0, app(RoutineAccountabilityService::class)->closeFor($owner), 'ก่อน 17:00 ยังไม่ปิดรอบ');

        $this->at('2026-09-07 17:00');
        $this->artisan('worklogs:close-routine-day')->assertSuccessful();
        $this->artisan('worklogs:close-routine-day')->assertSuccessful();

        $check = $this->logOf($owner, 'ตรวจเช็กคอม', '2026-09-07');
        $this->assertSame('not_started', $check->status);
        $this->assertNotNull($check->cutoff_closed_at);
        $this->assertSame('unfinished', $backup->refresh()->status);
        $this->assertSame(1, SystemNotification::where('user_id', $owner->id)->where('type', 'work_log_routine_cutoff')->count());

        // หลังปิดรอบกดเริ่ม/เสร็จไม่ได้
        $this->startJson($owner, $check)->assertStatus(422)->assertJsonPath('errors.routine.0', fn ($message) => str_contains($message, 'ปิดรอบ'));
        $this->actingAs($owner)->postJson(route('daily-logs.complete', $backup))->assertStatus(422);
        $this->assertSame('unfinished', $backup->refresh()->status);
    }

    public function test_a_single_person_must_explain_every_missed_day_in_one_request_before_starting(): void
    {
        $this->at('2026-09-07 07:00');
        $owner = $this->user();
        $this->template($owner, ['title' => 'ตรวจเช็กคอม']);

        // ไม่ได้เปิดระบบเลย จันทร์–พุธ
        $this->at('2026-09-10 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'))->assertOk();
        $today = $this->logOf($owner, 'ตรวจเช็กคอม', '2026-09-10');

        $response = $this->startJson($owner, $today)->assertStatus(422);
        $this->assertSame(['2026-09-07', '2026-09-08', '2026-09-09'], array_column($response->json('requirements.backlog'), 'date'));
        $this->assertSame('7 กันยายน 2569', $response->json('requirements.backlog.0.date_label'));
        $this->assertSame('not_started', $response->json('requirements.backlog.0.type'));
        $this->assertSame([], $response->json('requirements.attendance'));

        // ตอบไม่ครบ = ไม่บันทึกอะไรเลย
        $this->startJson($owner, $today, ['backlog_reasons' => ['2026-09-07' => 'ลางาน', '2026-09-08' => 'ลางาน']])->assertStatus(422);
        $this->assertSame(0, WorkLog::whereNotNull('skip_reason')->count());
        $this->assertSame('open', $today->refresh()->status);

        // ฟอร์มปกติ (ไม่ใช่ AJAX) ก็ถูกบังคับเหมือนกัน
        $this->actingAs($owner)->post(route('daily-logs.start', $today))->assertSessionHasErrors('backlog_reasons');

        $this->startJson($owner, $today, ['backlog_reasons' => [
            '2026-09-07' => 'ลางาน', '2026-09-08' => 'ลางาน', '2026-09-09' => 'ติดงานด่วนอื่น',
        ]])->assertOk();

        $missed = WorkLog::where('user_id', $owner->id)->whereDate('work_date', '<', '2026-09-10')->orderBy('work_date')->get();
        $this->assertSame(['not_started', 'not_started', 'not_started'], $missed->pluck('status')->all(), 'ต้องไม่ถูกแปลงเป็นไม่ได้ทำ');
        $this->assertSame(['ลางาน', 'ลางาน', 'ติดงานด่วนอื่น'], $missed->pluck('skip_reason')->all());
        $this->assertTrue($missed->every(fn (WorkLog $log) => $log->explained_at !== null && $log->unfinished_reason === null));
        $this->assertSame('in_progress', $today->refresh()->status);
    }

    public function test_started_but_not_completed_is_asked_and_reported_separately(): void
    {
        $this->at('2026-09-07 07:00');
        $owner = $this->user();
        $this->template($owner, ['title' => 'ตรวจเช็กคอม']);

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $monday = $this->logOf($owner, 'ตรวจเช็กคอม', '2026-09-07');
        $this->startJson($owner, $monday)->assertOk();

        $this->at('2026-09-08 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $tuesday = $this->logOf($owner, 'ตรวจเช็กคอม', '2026-09-08');

        $this->startJson($owner, $tuesday)->assertStatus(422)
            ->assertJsonPath('requirements.backlog.0.type', 'unfinished')
            ->assertJsonPath('requirements.backlog.0.status_label', 'เริ่มแล้วไม่กดเสร็จ');

        $this->startJson($owner, $tuesday, ['backlog_reasons' => ['2026-09-07' => 'ลืมกดเสร็จ']])->assertOk();

        $monday->refresh();
        $this->assertSame('unfinished', $monday->status);
        $this->assertSame('ลืมกดเสร็จ', $monday->unfinished_reason);
        $this->assertNull($monday->skip_reason);

        $events = $this->actingAs($owner)->get(route('reports.operational.delays'))->assertOk()->viewData('events');
        $this->assertSame(['unfinished'], $events->pluck('type')->all());
        $this->assertSame('เริ่มแล้วไม่กดเสร็จ', $events->first()['type_label']);
        $this->assertSame('ลืมกดเสร็จ', $events->first()['reason']);
    }

    public function test_a_starter_marks_an_absent_participant_who_must_explain_when_they_return(): void
    {
        $this->at('2026-09-07 07:00');
        [$owner, $mate] = $this->team();
        $this->template($owner, ['title' => 'ตรวจเช็กคอม'], [$mate]);

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $ownerMonday = $this->logOf($owner, 'ตรวจเช็กคอม', '2026-09-07');

        $this->startJson($owner, $ownerMonday)->assertStatus(422)
            ->assertJsonPath('requirements.attendance.0.id', $mate->id)
            ->assertJsonPath('requirements.backlog', []);

        $this->startJson($owner, $ownerMonday, ['attendance' => [$mate->id => 'absent']])->assertOk();

        $mateMonday = $this->logOf($mate, 'ตรวจเช็กคอม', '2026-09-07');
        $this->assertSame('absent', $mateMonday->status);
        $this->assertSame($owner->id, $mateMonday->absent_marked_by);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $mate->id, 'type' => 'work_log_routine_absent']);

        // วันต่อมา ผู้ร่วมงานกลับมา — ต้องตอบเหตุผลของวันที่ไม่มาก่อน และถูกถามว่าคนสร้างมาไหม
        $this->at('2026-09-08 09:00');
        $this->actingAs($mate)->get(route('daily-logs.index'));
        $mateTuesday = $this->logOf($mate, 'ตรวจเช็กคอม', '2026-09-08');

        $response = $this->startJson($mate, $mateTuesday)->assertStatus(422);
        $this->assertSame('absent', $response->json('requirements.backlog.0.type'));
        $this->assertSame($owner->name, $response->json('requirements.backlog.0.absent_marked_by'));
        $this->assertSame([$owner->id], array_column($response->json('requirements.attendance'), 'id'));

        $this->startJson($mate, $mateTuesday, [
            'backlog_reasons' => ['2026-09-07' => 'ลางาน'],
            'attendance' => [$owner->id => 'present'],
        ])->assertOk();

        $mateMonday->refresh();
        $this->assertSame('absent', $mateMonday->status);
        $this->assertSame('ลางาน', $mateMonday->skip_reason);
        $this->assertSame('in_progress', $mateTuesday->refresh()->status);
        $this->assertSame(0, WorkLog::where('user_id', $owner->id)->whereDate('work_date', '2026-09-08')->count(), 'ตอบว่ามา = ไม่แตะรายการของเขา');

        // วันที่ไม่มาบันทึกให้เห็นบนหน้าจอว่าใครระบุ
        $this->actingAs($mate)->get(route('daily-logs.index', ['date' => '2026-09-07']))
            ->assertOk()->assertSee($owner->name.' ระบุว่าไม่มา')->assertSee('ไม่มา: ลางาน');
    }

    public function test_each_member_starts_and_finishes_independently_and_cannot_be_marked_absent_after_starting(): void
    {
        $this->at('2026-09-07 07:00');
        [$owner, $mate] = $this->team();
        $this->template($owner, ['title' => 'ตรวจเช็กคอม'], [$mate]);

        $this->at('2026-09-07 09:00');
        $this->actingAs($mate)->get(route('daily-logs.index'));
        $mateLog = $this->logOf($mate, 'ตรวจเช็กคอม', '2026-09-07');
        $this->startJson($mate, $mateLog, ['attendance' => [$owner->id => 'present']])->assertOk();
        $this->actingAs($mate)->postJson(route('daily-logs.complete', $mateLog))->assertOk();
        $this->assertSame('done', $mateLog->refresh()->status, 'ไม่ต้องรอให้คนสร้างเริ่มก่อน');

        $this->actingAs($owner)->get(route('daily-logs.index'));
        $ownerLog = $this->logOf($owner, 'ตรวจเช็กคอม', '2026-09-07');
        // ผู้ร่วมงานเริ่มไปแล้ว จึงไม่ถูกถาม และส่ง "ไม่มา" มาเองก็ไม่มีผล
        $this->startJson($owner, $ownerLog, ['attendance' => [$mate->id => 'absent']])->assertOk();
        $this->assertSame('done', $mateLog->refresh()->status);
    }

    public function test_someone_marked_absent_who_arrives_the_same_day_can_still_start(): void
    {
        $this->at('2026-09-07 07:00');
        [$owner, $mate] = $this->team();
        $this->template($owner, ['title' => 'ตรวจเช็กคอม'], [$mate]);

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->startJson($owner, $this->logOf($owner, 'ตรวจเช็กคอม', '2026-09-07'), ['attendance' => [$mate->id => 'absent']])->assertOk();

        $this->at('2026-09-07 10:00');
        $mateLog = $this->logOf($mate, 'ตรวจเช็กคอม', '2026-09-07');
        $this->startJson($mate, $mateLog)->assertOk();

        $mateLog->refresh();
        $this->assertSame('in_progress', $mateLog->status);
        $this->assertNull($mateLog->absent_marked_by);
    }

    public function test_when_nobody_came_each_person_explains_their_own_day(): void
    {
        $this->at('2026-09-07 07:00');
        [$owner, $mate] = $this->team();
        $this->template($owner, ['title' => 'ตรวจเช็กคอม'], [$mate]);

        $this->at('2026-09-08 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->actingAs($mate)->get(route('daily-logs.index'));

        $ownerResponse = $this->startJson($owner, $this->logOf($owner, 'ตรวจเช็กคอม', '2026-09-08'))->assertStatus(422);
        $mateResponse = $this->startJson($mate, $this->logOf($mate, 'ตรวจเช็กคอม', '2026-09-08'))->assertStatus(422);

        $this->assertSame([['2026-09-07', 'not_started']], array_map(fn ($day) => [$day['date'], $day['type']], $ownerResponse->json('requirements.backlog')));
        $this->assertSame([['2026-09-07', 'not_started']], array_map(fn ($day) => [$day['date'], $day['type']], $mateResponse->json('requirements.backlog')));
        $this->assertSame(2, WorkLog::whereDate('work_date', '2026-09-07')->where('status', 'not_started')->count());
    }

    public function test_the_card_reason_button_explains_a_closed_day_without_changing_its_status(): void
    {
        $this->at('2026-09-07 07:00');
        $owner = $this->user();
        $this->template($owner, ['title' => 'ตรวจเช็กคอม']);

        $this->at('2026-09-08 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index', ['date' => '2026-09-07']))
            ->assertOk()->assertSee('data-explain-type="not_started"', false)->assertDontSee('data-row-start', false);

        $monday = $this->logOf($owner, 'ตรวจเช็กคอม', '2026-09-07');
        $this->actingAs($owner)->postJson(route('daily-logs.skip', $monday), ['skip_reason' => 'ลืมทำ'])->assertOk();
        $this->assertSame(['not_started', 'ลืมทำ'], [$monday->refresh()->status, $monday->skip_reason]);

        $this->actingAs($owner)->postJson(route('daily-logs.skip', $monday), ['skip_reason' => 'เปลี่ยนใจ'])->assertStatus(422);
        $this->assertSame('ลืมทำ', $monday->refresh()->skip_reason);
    }

    public function test_days_before_the_accountability_start_are_never_asked(): void
    {
        $this->at('2026-08-27 09:00');
        $owner = $this->user();
        // แม่แบบเก่าที่มีอยู่ก่อนกติกานี้ — migration ตั้ง accountable_from เป็นเวลาที่ deploy
        $this->template($owner, ['title' => 'ตรวจเช็กคอม', 'accountable_from' => CarbonImmutable::parse('2026-09-09 08:00', 'Asia/Bangkok')]);

        $this->at('2026-09-10 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));

        $this->assertSame(['2026-09-09', '2026-09-10'], WorkLog::orderBy('work_date')->get()->map(fn ($log) => $log->work_date->format('Y-m-d'))->all());
    }

    public function test_a_deleted_day_is_not_recreated_by_the_cutoff_and_attention_has_no_stale_evening_reminder(): void
    {
        $this->at('2026-09-07 07:00');
        $owner = $this->user();
        $this->template($owner, ['title' => 'ตรวจเช็กคอม']);
        $this->template($owner, ['title' => 'สำรองข้อมูล']);

        $this->at('2026-09-07 09:00');
        $this->actingAs($owner)->get(route('daily-logs.index'));
        $this->actingAs($owner)->delete(route('daily-logs.destroy', $this->logOf($owner, 'สำรองข้อมูล', '2026-09-07')));

        $this->at('2026-09-07 17:30');
        $summary = app(RoutineAttentionService::class)->summary($owner);

        $this->assertSame(0, $summary['total'], 'หลังปิดรอบไม่มีรายการรอเริ่ม/กำลังทำค้างอยู่');
        $this->assertSame(0, WorkLog::where('title', 'สำรองข้อมูล')->count());
        $this->assertSame(0, SystemNotification::where('type', 'work_log_routine_day_pending')->count());
        $this->assertSame(1, SystemNotification::where('type', 'work_log_routine_cutoff')->count());
    }

    private function startJson(User $user, WorkLog $log, array $payload = [])
    {
        return $this->actingAs($user)->postJson(route('daily-logs.start', $log), $payload);
    }

    private function at(string $bangkok): void
    {
        $this->travelTo(CarbonImmutable::parse($bangkok, 'Asia/Bangkok'));
    }

    private function logOf(User $user, string $title, string $date): WorkLog
    {
        return WorkLog::where('user_id', $user->id)->where('title', $title)->whereDate('work_date', $date)->firstOrFail();
    }

    /** @return array{0: User, 1: User} */
    private function team(): array
    {
        $department = Department::create(['department_name' => 'IT']);

        return [$this->user($department, 'ผู้สร้างงาน'), $this->user($department, 'ผู้ร่วมงาน')];
    }

    private function user(?Department $department = null, ?string $name = null): User
    {
        return User::factory()->create(array_filter([
            'role' => 'user',
            'department_id' => ($department ?? Department::create(['department_name' => 'IT-'.uniqid()]))->id,
            'is_active' => true,
            'must_change_password' => false,
            'name' => $name,
        ], fn ($value) => $value !== null));
    }

    /** @param  list<User>  $participants */
    private function template(User $owner, array $overrides = [], array $participants = []): WorkLogTemplate
    {
        $template = WorkLogTemplate::create(array_merge([
            'user_id' => $owner->id,
            'title' => 'งานประจำ',
            'kind' => 'routine',
            'weekday_mask' => WorkLogWeekdays::WORKWEEK,
            'is_active' => true,
        ], $overrides));

        foreach ($participants as $person) {
            $template->participants()->attach($person->id, ['added_by' => $owner->id]);
        }

        return $template;
    }
}
