<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

class MeetingManagementTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 12:00:00', MeetingQueryService::BUSINESS_TIMEZONE));
        $this->department = Department::create(['department_name' => 'Technology']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_role_permissions_and_organization_visibility_are_enforced(): void
    {
        $admin = $this->user('admin');
        $viewer = $this->user('viewer');
        $creator = $this->user();
        $attendee = $this->user();
        $unrelated = $this->user();
        $meeting = $this->meeting($creator, ['title' => 'Organization meeting']);
        $meeting->attendees()->attach($attendee);

        $this->actingAs($admin)->get(route('meetings.index', ['period' => 'all']))->assertOk()->assertSee('Organization meeting');
        $this->actingAs($viewer)->get(route('meetings.index', ['period' => 'all']))->assertOk()->assertSee('Organization meeting');
        $this->actingAs($creator)->get(route('meetings.index', ['period' => 'all']))->assertOk()->assertSee('Organization meeting');
        $this->actingAs($attendee)->get(route('meetings.index', ['period' => 'all']))->assertOk()->assertSee('Organization meeting');
        $this->actingAs($unrelated)->get(route('meetings.index', ['period' => 'all']))->assertOk()->assertDontSee('Organization meeting');
        $this->actingAs($unrelated)->get(route('meetings.show', $meeting))->assertForbidden();

        $payload = $this->meetingPayload();
        $this->actingAs($viewer)->post(route('meetings.store'), $payload)->assertForbidden();
        $this->actingAs($viewer)->patch(route('meetings.update', $meeting), $payload)->assertForbidden();
        $this->actingAs($viewer)->delete(route('meetings.destroy', $meeting))->assertForbidden();
    }

    public function test_user_crafted_employee_filter_never_expands_visibility(): void
    {
        $person = $this->user();
        $other = $this->user();
        $visible = $this->meeting($person, ['title' => 'My visible meeting']);
        $hidden = $this->meeting($other, ['title' => 'Other private meeting']);

        $response = $this->actingAs($person)->get(route('meetings.index', [
            'period' => 'all',
            'employee' => $other->id,
        ]));

        $response->assertOk()->assertSee($visible->title)->assertDontSee($hidden->title);
        $this->assertNull($response->viewData('filters')['employee_id']);
    }

    public function test_admin_employee_filter_includes_creator_and_attendee_without_duplicates(): void
    {
        $admin = $this->user('admin');
        $employee = $this->user();
        $other = $this->user();
        $created = $this->meeting($employee, ['title' => 'Created by employee']);
        $attending = $this->meeting($other, ['title' => 'Employee attends']);
        $attending->attendees()->attach($employee);
        $both = $this->meeting($employee, ['title' => 'Employee both roles']);
        $both->attendees()->attach($employee);
        $this->meeting($other, ['title' => 'Unrelated meeting']);

        $response = $this->actingAs($admin)->get(route('meetings.index', [
            'period' => 'all',
            'employee' => $employee->id,
        ]));
        $ids = $response->viewData('meetings')->getCollection()->pluck('id');

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$created->id, $attending->id, $both->id], $ids->all());
        $this->assertSame($ids->unique()->count(), $ids->count());
        $response->assertSee('ผู้สร้าง');

        $nextSevenDays = $this->actingAs($admin)->get(route('meetings.index', [
            'period' => 'next_7_days',
            'employee' => $employee->id,
        ]));
        $this->assertEqualsCanonicalizing([$created->id, $attending->id, $both->id], $nextSevenDays->viewData('meetings')->getCollection()->pluck('id')->all());

        $craftedDetail = $this->actingAs($admin)->get(route('meetings.show', ['meeting' => $created, 'employee' => $other->id]));
        $craftedDetail->assertOk();
        $this->assertNull($craftedDetail->viewData('inspectedEmployee'));
    }

    public function test_invalid_employee_filter_is_safely_ignored_for_admin_and_viewer(): void
    {
        $meeting = $this->meeting($this->user(), ['title' => 'Still visible']);

        foreach ([$this->user('admin'), $this->user('viewer')] as $actor) {
            $response = $this->actingAs($actor)->get(route('meetings.index', ['period' => 'all', 'employee' => 999999]));
            $response->assertOk()->assertSee($meeting->title);
            $this->assertNull($response->viewData('filters')['employee_id']);
        }
    }

    public function test_create_normalizes_duplicate_attendees_and_accepts_active_organizational_roles(): void
    {
        $creator = $this->user();
        $admin = $this->user('admin');
        $viewer = $this->user('viewer');

        $response = $this->actingAs($creator)->post(route('meetings.store'), $this->meetingPayload([
            'title' => 'Cross role meeting',
            'attendees' => [$creator->id, $admin->id, $admin->id, $viewer->id],
        ]));

        $meeting = Meeting::where('title', 'Cross role meeting')->firstOrFail();
        $response->assertRedirect(route('meetings.show', $meeting));
        $this->assertSame('2026-08-24 03:00', $meeting->starts_at->format('Y-m-d H:i'));
        $this->assertEqualsCanonicalizing([$creator->id, $admin->id, $viewer->id], $meeting->attendees()->pluck('users.id')->all());
        $this->assertDatabaseHas('activity_logs', ['action' => 'created', 'subject_type' => Meeting::class, 'subject_id' => $meeting->id]);

        $adminResponse = $this->actingAs($admin)->post(route('meetings.store'), $this->meetingPayload(['title' => 'Admin meeting']));
        $adminResponse->assertRedirect(route('meetings.show', Meeting::where('title', 'Admin meeting')->firstOrFail()));
    }

    public function test_validation_rejects_invalid_dates_and_ineligible_attendees(): void
    {
        $creator = $this->user();
        $inactive = $this->user(isActive: false);

        $this->actingAs($creator)->post(route('meetings.store'), $this->meetingPayload([
            'title' => '',
            'starts_at' => 'invalid',
            'ends_at' => '2026-08-23T09:00',
            'attendees' => [$inactive->id, 999999],
        ]))->assertSessionHasErrors(['title', 'starts_at', 'ends_at', 'attendees.0', 'attendees.1']);

        $this->actingAs($creator)->post(route('meetings.store'), $this->meetingPayload([
            'starts_at' => '2026-08-23T10:00',
            'ends_at' => '2026-08-23T10:00',
        ]))->assertSessionHasErrors('ends_at');
    }

    public function test_soft_deleted_attendee_is_rejected_on_create_without_creating_a_pivot(): void
    {
        $creator = $this->user();
        $deletedAttendee = $this->user();
        $deletedAttendee->delete();

        $response = $this->actingAs($creator)->post(route('meetings.store'), $this->meetingPayload([
            'title' => 'Rejected deleted attendee',
            'attendees' => [$deletedAttendee->id],
        ]));

        $response->assertSessionHasErrors('attendees.0');
        $this->assertDatabaseMissing('meetings', ['title' => 'Rejected deleted attendee']);
        $this->assertDatabaseMissing('meeting_attendees', ['user_id' => $deletedAttendee->id]);
    }

    public function test_soft_deleted_attendee_is_rejected_on_update_without_changing_the_meeting_or_pivot(): void
    {
        $creator = $this->user();
        $currentAttendee = $this->user();
        $deletedAttendee = $this->user();
        $meeting = $this->meeting($creator, ['title' => 'Original meeting title']);
        $meeting->attendees()->attach($currentAttendee);
        $deletedAttendee->delete();

        $response = $this->actingAs($creator)->patch(route('meetings.update', $meeting), $this->meetingPayload([
            'title' => 'Should not be persisted',
            'attendees' => [$currentAttendee->id, $deletedAttendee->id],
        ]));

        $response->assertSessionHasErrors('attendees.1');
        $this->assertSame('Original meeting title', $meeting->fresh()->title);
        $this->assertDatabaseHas('meeting_attendees', ['meeting_id' => $meeting->id, 'user_id' => $currentAttendee->id]);
        $this->assertDatabaseMissing('meeting_attendees', ['meeting_id' => $meeting->id, 'user_id' => $deletedAttendee->id]);
    }

    public function test_edit_hydrates_and_syncs_multiple_attendees_with_add_remove_and_deduplication(): void
    {
        $creator = $this->user();
        $attendeeA = $this->user();
        $attendeeB = $this->user();
        $attendeeC = $this->user();
        $attendeeD = $this->user();
        $meeting = $this->meeting($creator);
        $meeting->attendees()->attach([$attendeeA->id, $attendeeB->id, $attendeeC->id]);

        $editHtml = $this->actingAs($creator)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->getContent();
        foreach ([$attendeeA, $attendeeB, $attendeeC] as $selectedAttendee) {
            $this->assertMatchesRegularExpression(
                '/value="'.$selectedAttendee->id.'"[^>]*data-people-checkbox[^>]*checked/',
                $editHtml
            );
        }

        $this->actingAs($creator)
            ->patch(route('meetings.update', $meeting), $this->meetingPayload([
                'attendees' => [$attendeeA->id, $attendeeC->id, $attendeeD->id, $attendeeD->id],
            ]))
            ->assertRedirect(route('meetings.show', $meeting));

        $this->assertEqualsCanonicalizing(
            [$attendeeA->id, $attendeeC->id, $attendeeD->id],
            $meeting->attendees()->pluck('users.id')->all()
        );
        $this->assertDatabaseMissing('meeting_attendees', ['meeting_id' => $meeting->id, 'user_id' => $attendeeB->id]);
    }

    public function test_attendee_selector_uses_real_departments_checkboxes_and_backend_eligibility(): void
    {
        $creator = $this->user();
        $marketing = Department::create(['department_name' => 'Marketing']);
        $marketingUser = User::factory()->create([
            'role' => 'user',
            'department_id' => $marketing->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $inactive = $this->user(isActive: false);
        $deleted = $this->user();
        $deleted->delete();

        $response = $this->actingAs($creator)
            ->get(route('meetings.index', ['period' => 'all']))
            ->assertOk()
            ->assertSee('data-people-department', false)
            ->assertSee('data-department-id="'.$this->department->id.'"', false)
            ->assertSee($this->department->department_name)
            ->assertSee('data-department-id="'.$marketing->id.'"', false)
            ->assertSee($marketing->department_name)
            ->assertDontSee('data-meeting-attendee-select', false);

        $html = $response->getContent();
        // ตัวเลือกผู้เข้าร่วมใช้ component กลาง (data-people-*) ร่วมกับผู้ร่วมงานของงานแล้ว
        $this->assertMatchesRegularExpression('/value="'.$marketingUser->id.'"[^>]*data-people-checkbox/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$inactive->id.'"[^>]*data-people-checkbox/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$deleted->id.'"[^>]*data-people-checkbox/', $html);
        $this->assertSame(0, preg_match('/<select[^>]*name="attendees\[\]"[^>]*multiple/i', $html));
    }

    public function test_creator_and_admin_can_update_delete_but_attendee_and_unrelated_user_cannot(): void
    {
        $creator = $this->user();
        $attendee = $this->user();
        $other = $this->user();
        $admin = $this->user('admin');
        $meeting = $this->meeting($creator);
        $meeting->attendees()->attach($attendee);

        $this->actingAs($attendee)->patch(route('meetings.update', $meeting), $this->meetingPayload())->assertForbidden();
        $this->actingAs($other)->delete(route('meetings.destroy', $meeting))->assertForbidden();

        $this->actingAs($creator)->patch(route('meetings.update', $meeting), $this->meetingPayload(['title' => 'Creator updated']))
            ->assertRedirect(route('meetings.show', $meeting));
        $this->assertSame('Creator updated', $meeting->fresh()->title);

        $this->actingAs($admin)->patch(route('meetings.update', $meeting), $this->meetingPayload(['title' => 'Admin updated']))
            ->assertRedirect(route('meetings.show', $meeting));
        $this->actingAs($admin)->delete(route('meetings.destroy', $meeting))->assertRedirect(route('meetings.index'));
        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
        $this->assertSame(1, ActivityLog::where('subject_type', Meeting::class)->where('subject_id', $meeting->id)->where('action', 'deleted')->count());

        $creatorOwned = $this->meeting($creator, ['title' => 'Creator deletes']);
        $this->actingAs($creator)->delete(route('meetings.destroy', $creatorOwned))->assertRedirect(route('meetings.index'));
        $this->assertDatabaseMissing('meetings', ['id' => $creatorOwned->id]);
    }

    public function test_search_and_employee_filter_combine_without_leaking_rows(): void
    {
        $admin = $this->user('admin');
        $employee = $this->user();
        $other = $this->user();
        $match = $this->meeting($other, ['title' => 'Planning', 'location' => 'สำนักงานใหญ่']);
        $match->attendees()->attach($employee);
        $this->meeting($employee, ['title' => 'Different title', 'location' => 'เชียงใหม่']);
        $descriptionMatch = $this->meeting($employee, ['title' => 'General sync', 'description' => 'Secret planning phrase']);
        $this->meeting($other, ['title' => 'Planning hidden', 'location' => 'สำนักงานใหญ่']);

        $response = $this->actingAs($admin)->get(route('meetings.index', [
            'period' => 'all', 'employee' => $employee->id, 'search' => 'สำนักงานใหญ่',
        ]));

        $ids = $response->viewData('meetings')->getCollection()->pluck('id');
        $this->assertSame([$match->id], $ids->all());

        $titleResponse = $this->actingAs($admin)->get(route('meetings.index', ['period' => 'all', 'search' => 'Different title']));
        $this->assertSame(1, $titleResponse->viewData('meetings')->total());
        $descriptionResponse = $this->actingAs($admin)->get(route('meetings.index', ['period' => 'all', 'search' => 'Secret planning']));
        $this->assertSame([$descriptionMatch->id], $descriptionResponse->viewData('meetings')->getCollection()->pluck('id')->all());
    }

    public function test_period_filters_use_bangkok_overlap_boundaries(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user();
        $todayOverlap = $this->meetingLocal($owner, 'Today overlap', '2026-08-22 23:30', '2026-08-23 00:30');
        $sevenDayBoundary = $this->meetingLocal($owner, 'Seven day boundary', '2026-08-30 23:00', '2026-08-31 01:00');
        $monthOverlap = $this->meetingLocal($owner, 'Month overlap', '2026-07-31 23:00', '2026-08-01 01:00');
        $past = $this->meetingLocal($owner, 'Past meeting', '2026-08-22 09:00', '2026-08-22 10:00');

        $todayIds = $this->idsFor($admin, 'today');
        $this->assertContains($todayOverlap->id, $todayIds);
        $this->assertNotContains($past->id, $todayIds);

        $nextIds = $this->idsFor($admin, 'next_7_days');
        $this->assertContains($sevenDayBoundary->id, $nextIds);
        $this->assertNotContains($past->id, $nextIds);

        $monthIds = $this->idsFor($admin, 'this_month');
        $this->assertContains($monthOverlap->id, $monthIds);
        $this->assertContains($sevenDayBoundary->id, $monthIds);

        $pastIds = $this->idsFor($admin, 'past');
        $this->assertContains($past->id, $pastIds);
        $this->assertContains($todayOverlap->id, $pastIds);

        $upcomingIds = $this->idsFor($admin, 'upcoming');
        $this->assertContains($sevenDayBoundary->id, $upcomingIds);
        $this->assertNotContains($todayOverlap->id, $upcomingIds);
    }

    public function test_meeting_list_query_count_does_not_grow_per_row(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user();
        $attendees = collect(range(1, 3))->map(fn () => $this->user());

        $measure = function (int $count) use ($admin, $owner, $attendees): int {
            Meeting::query()->delete();
            foreach (range(1, $count) as $number) {
                $meeting = $this->meeting($owner, ['title' => "Query {$number}"]);
                $meeting->attendees()->attach($attendees->pluck('id'));
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(MeetingQueryService::class)->indexData(Request::create('/meetings', 'GET', ['period' => 'all']), $admin);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($measure(1), $measure(10));
    }

    public function test_empty_meeting_index_has_one_create_cta_for_writers_and_none_for_viewer(): void
    {
        foreach ([$this->user('admin'), $this->user()] as $actor) {
            $html = $this->actingAs($actor)
                ->get(route('meetings.index', ['period' => 'all']))
                ->assertOk()
                ->getContent();

            $this->assertSame(1, substr_count($html, 'data-meeting-modal-trigger="createMeetingModal"'));
            $this->assertSame(1, substr_count($html, 'aria-controls="createMeetingModal"'));
            $this->assertSame(1, substr_count($html, 'data-meeting-create'));
            $this->assertSame(1, substr_count($html, 'id="createMeetingModal"'));
        }

        $viewerHtml = $this->actingAs($this->user('viewer'))
            ->get(route('meetings.index', ['period' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertSame(0, substr_count($viewerHtml, 'data-meeting-modal-trigger="createMeetingModal"'));
        $this->assertSame(0, substr_count($viewerHtml, 'id="createMeetingModal"'));
        $this->assertSame(0, substr_count($viewerHtml, 'data-meeting-create'));
    }

    public function test_create_and_edit_use_the_shared_non_scrollable_modal_layout(): void
    {
        $creator = $this->user();
        $meeting = $this->meeting($creator);

        $createHtml = $this->actingAs($creator)
            ->get(route('meetings.index', ['period' => 'all']))
            ->assertOk()
            ->getContent();
        $editHtml = $this->actingAs($creator)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->getContent();
        $adminEditHtml = $this->actingAs($this->user('admin'))
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->getContent();
        $viewerEditHtml = $this->actingAs($this->user('viewer'))
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->getContent();

        foreach ([$createHtml, $editHtml] as $html) {
            $this->assertStringContainsString('modal-dialog modal-lg modal-dialog-centered', $html);
            $this->assertStringNotContainsString('modal-dialog-scrollable', $html);
            $this->assertStringContainsString('data-meeting-form', $html);
        }

        $this->assertSame(1, substr_count($createHtml, 'data-meeting-modal-trigger="createMeetingModal"'));
        $this->assertSame(1, substr_count($createHtml, 'aria-controls="createMeetingModal"'));
        $this->assertSame(1, substr_count($createHtml, 'id="createMeetingModal"'));
        $this->assertSame(0, substr_count($createHtml, 'id="editMeetingModal"'));
        $this->assertStringContainsString('action="'.route('meetings.store').'"', $createHtml);
        $this->assertSame(1, substr_count($editHtml, 'data-meeting-modal-trigger="editMeetingModal"'));
        $this->assertSame(1, substr_count($editHtml, 'aria-controls="editMeetingModal"'));
        $this->assertSame(1, substr_count($editHtml, 'id="editMeetingModal"'));
        $this->assertSame(1, substr_count($adminEditHtml, 'data-meeting-modal-trigger="editMeetingModal"'));
        $this->assertSame(1, substr_count($adminEditHtml, 'id="editMeetingModal"'));
        $this->assertSame(0, substr_count($viewerEditHtml, 'data-meeting-modal-trigger="editMeetingModal"'));
        $this->assertSame(0, substr_count($viewerEditHtml, 'id="editMeetingModal"'));
        $this->assertStringNotContainsString('data-bs-toggle="modal"', $createHtml);
        $this->assertStringNotContainsString('data-bs-toggle="modal"', $editHtml);
    }

    public function test_create_database_failure_rolls_back_logs_and_flashes_safe_feedback(): void
    {
        $creator = $this->user();
        Log::spy();
        ActivityLog::created(fn () => throw $this->databaseFailure());

        try {
            $response = $this->actingAs($creator)
                ->from(route('meetings.index'))
                ->post(route('meetings.store'), $this->meetingPayload(['title' => 'Rollback create']));
        } finally {
            ActivityLog::flushEventListeners();
        }

        $response->assertRedirect(route('meetings.index'))
            ->assertSessionHas('meeting_error', 'ไม่สามารถสร้างการประชุมได้ กรุณาลองใหม่อีกครั้ง')
            ->assertSessionHas('meeting_open_modal', 'createMeetingModal')
            ->assertSessionDoesntHaveErrors();
        $this->assertDatabaseMissing('meetings', ['title' => 'Rollback create']);
        $this->assertStringNotContainsString('database unavailable', session('meeting_error'));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_update_database_failure_rolls_back_logs_and_flashes_safe_feedback(): void
    {
        $creator = $this->user();
        $meeting = $this->meeting($creator, ['title' => 'Before failed update']);
        Log::spy();
        ActivityLog::created(fn () => throw $this->databaseFailure());

        try {
            $response = $this->actingAs($creator)
                ->from(route('meetings.show', $meeting))
                ->patch(route('meetings.update', $meeting), $this->meetingPayload(['title' => 'After failed update']));
        } finally {
            ActivityLog::flushEventListeners();
        }

        $response->assertRedirect(route('meetings.show', $meeting))
            ->assertSessionHas('meeting_error', 'ไม่สามารถแก้ไขการประชุมได้ กรุณาลองใหม่อีกครั้ง')
            ->assertSessionHas('meeting_open_modal', 'editMeetingModal');
        $this->assertSame('Before failed update', $meeting->fresh()->title);
        $this->assertStringNotContainsString('database unavailable', session('meeting_error'));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_delete_database_failure_rolls_back_logs_and_flashes_safe_feedback(): void
    {
        $creator = $this->user();
        $meeting = $this->meeting($creator);
        Log::spy();
        ActivityLog::created(fn () => throw $this->databaseFailure());

        try {
            $response = $this->actingAs($creator)
                ->from(route('meetings.show', $meeting))
                ->delete(route('meetings.destroy', $meeting));
        } finally {
            ActivityLog::flushEventListeners();
        }

        $response->assertRedirect(route('meetings.show', $meeting))
            ->assertSessionHas('meeting_error', 'ไม่สามารถลบการประชุมได้ กรุณาลองใหม่อีกครั้ง');
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
        $this->assertDatabaseMissing('activity_logs', ['subject_type' => Meeting::class, 'subject_id' => $meeting->id, 'action' => 'deleted']);
        $this->assertStringNotContainsString('database unavailable', session('meeting_error'));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_user_hard_purge_cascades_owned_meetings_and_only_detaches_attendance_elsewhere(): void
    {
        $person = $this->user();
        $other = $this->user();
        $owned = $this->meeting($person, ['title' => 'Owned by purged account']);
        $attending = $this->meeting($other, ['title' => 'Attending only']);
        $attending->attendees()->attach($person);

        $person->forceDelete();

        $this->assertDatabaseMissing('meetings', ['id' => $owned->id]);
        $this->assertDatabaseHas('meetings', ['id' => $attending->id]);
        $this->assertDatabaseMissing('meeting_attendees', ['meeting_id' => $attending->id, 'user_id' => $person->id]);
    }

    private function user(string $role = 'user', bool $isActive = true): User
    {
        return User::factory()->create([
            'role' => $role,
            'department_id' => $role === 'user' ? $this->department->id : null,
            'must_change_password' => false,
            'is_active' => $isActive,
        ]);
    }

    private function meeting(User $creator, array $attributes = []): Meeting
    {
        return Meeting::create(array_merge([
            'title' => 'Team meeting',
            'description' => 'Meeting description',
            'starts_at' => $this->utc('2026-08-24 10:00'),
            'ends_at' => $this->utc('2026-08-24 11:00'),
            'location' => 'ห้องประชุมชั้น 2',
            'created_by' => $creator->id,
        ], $attributes));
    }

    private function meetingLocal(User $creator, string $title, string $start, string $end): Meeting
    {
        return $this->meeting($creator, [
            'title' => $title,
            'starts_at' => $this->utc($start),
            'ends_at' => $this->utc($end),
        ]);
    }

    private function meetingPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Product sync',
            'description' => 'Weekly product sync',
            'starts_at' => '2026-08-24T10:00',
            'ends_at' => '2026-08-24T11:00',
            'location' => 'ห้องใหญ่',
            'attendees' => [],
        ], $overrides);
    }

    private function utc(string $bangkok): CarbonImmutable
    {
        return CarbonImmutable::parse($bangkok, MeetingQueryService::BUSINESS_TIMEZONE)->utc();
    }

    private function databaseFailure(): QueryException
    {
        return new QueryException('sqlite', 'meeting persistence', [], new PDOException('database unavailable'));
    }

    private function idsFor(User $viewer, string $period): array
    {
        $response = $this->actingAs($viewer)->get(route('meetings.index', ['period' => $period]));
        $response->assertOk();

        return $response->viewData('meetings')->getCollection()->pluck('id')->all();
    }
}
