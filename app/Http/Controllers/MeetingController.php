<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Services\MeetingQueryService;
use App\Support\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PDOException;

class MeetingController extends Controller
{
    public function index(Request $request, MeetingQueryService $meetings): View
    {
        Gate::authorize('viewAny', Meeting::class);

        return view('meetings.index', $meetings->indexData($request, $request->user()));
    }

    public function store(Request $request, MeetingQueryService $meetings): RedirectResponse
    {
        Gate::authorize('create', Meeting::class);

        try {
            $data = $this->validatedMeeting($request, $meetings);
            $meeting = DB::transaction(function () use ($data, $request, $meetings): Meeting {
                $attendeeIds = $this->eligibleAttendeeIdsOrFail($data['attendees'] ?? [], $meetings);
                $meeting = Meeting::create([
                    ...$this->meetingAttributes($data),
                    'created_by' => $request->user()->id,
                ]);
                $meeting->attendees()->sync($attendeeIds);
                AuditTrail::log('created', $meeting, 'สร้างการประชุม: '.$meeting->title, [
                    'after' => $this->auditPayload($meeting, $attendeeIds),
                ]);

                return $meeting;
            });
        } catch (PDOException $exception) {
            return $this->persistenceFailure(
                $exception,
                'create',
                'ไม่สามารถสร้างการประชุมได้ กรุณาลองใหม่อีกครั้ง',
                'createMeetingModal'
            );
        }

        return redirect()->route('meetings.show', $meeting)->with('meeting_success', 'นัดประชุมเรียบร้อยแล้ว');
    }

    public function show(Request $request, Meeting $meeting, MeetingQueryService $meetings): View
    {
        Gate::authorize('view', $meeting);

        return view('meetings.show', $meetings->detailData($request, $request->user(), $meeting));
    }

    public function update(Request $request, Meeting $meeting, MeetingQueryService $meetings): RedirectResponse
    {
        Gate::authorize('update', $meeting);

        try {
            $data = $this->validatedMeeting($request, $meetings);
            DB::transaction(function () use ($meeting, $data, $meetings): void {
                $attendeeIds = $this->eligibleAttendeeIdsOrFail($data['attendees'] ?? [], $meetings);
                $before = $this->auditPayload($meeting, $meeting->attendees()->pluck('users.id')->all());
                $meeting->update($this->meetingAttributes($data));
                $meeting->attendees()->sync($attendeeIds);
                AuditTrail::log('updated', $meeting, 'แก้ไขการประชุม: '.$meeting->title, [
                    'before' => $before,
                    'after' => $this->auditPayload($meeting->fresh(), $attendeeIds),
                ]);
            });
        } catch (PDOException $exception) {
            return $this->persistenceFailure(
                $exception,
                'update',
                'ไม่สามารถแก้ไขการประชุมได้ กรุณาลองใหม่อีกครั้ง',
                'editMeetingModal',
                $meeting
            );
        }

        return redirect()->route('meetings.show', $meeting)->with('meeting_success', 'บันทึกการประชุมเรียบร้อยแล้ว');
    }

    public function destroy(Meeting $meeting): RedirectResponse
    {
        Gate::authorize('delete', $meeting);

        try {
            DB::transaction(function () use ($meeting): void {
                $payload = $this->auditPayload($meeting, $meeting->attendees()->pluck('users.id')->all());
                AuditTrail::log('deleted', $meeting, 'ลบการประชุม: '.$meeting->title, ['before' => $payload]);
                $meeting->delete();
            });
        } catch (PDOException $exception) {
            return $this->persistenceFailure(
                $exception,
                'delete',
                'ไม่สามารถลบการประชุมได้ กรุณาลองใหม่อีกครั้ง',
                meeting: $meeting
            );
        }

        return redirect()->route('meetings.index')->with('meeting_success', 'ลบการประชุมเรียบร้อยแล้ว');
    }

    private function validatedMeeting(Request $request, MeetingQueryService $meetings): array
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['integer'],
        ], [
            'title.required' => 'กรุณาระบุชื่อการประชุม',
            'starts_at.required' => 'กรุณาระบุเวลาเริ่มประชุม',
            'starts_at.date_format' => 'รูปแบบเวลาเริ่มประชุมไม่ถูกต้อง',
            'ends_at.required' => 'กรุณาระบุเวลาสิ้นสุดประชุม',
            'ends_at.after' => 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่มประชุม',
            'ends_at.date_format' => 'รูปแบบเวลาสิ้นสุดประชุมไม่ถูกต้อง',
        ]);

        $validator->after(function ($validator) use ($request, $meetings): void {
            $attendeeIds = $request->input('attendees', []);

            if (! is_array($attendeeIds)) {
                return;
            }

            [, $errors] = $this->attendeeEligibility($attendeeIds, $meetings);

            foreach ($errors as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });

        return $validator->validate();
    }

    private function eligibleAttendeeIdsOrFail(array $attendeeIds, MeetingQueryService $meetings): array
    {
        [$eligibleIds, $errors] = $this->attendeeEligibility($attendeeIds, $meetings);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $eligibleIds;
    }

    private function attendeeEligibility(array $attendeeIds, MeetingQueryService $meetings): array
    {
        $eligibleIds = $meetings->eligibleAttendeeIds($attendeeIds);
        $eligibleLookup = array_fill_keys($eligibleIds, true);
        $errors = [];

        foreach ($attendeeIds as $index => $attendeeId) {
            if (filter_var($attendeeId, FILTER_VALIDATE_INT) === false) {
                continue;
            }

            if (! isset($eligibleLookup[(int) $attendeeId])) {
                $errors['attendees.'.$index] = 'พบผู้เข้าร่วมที่ไม่สามารถใช้งานได้';
            }
        }

        return [$eligibleIds, $errors];
    }

    private function persistenceFailure(
        PDOException $exception,
        string $operation,
        string $message,
        ?string $modalId = null,
        ?Meeting $meeting = null
    ): RedirectResponse {
        Log::error('Meeting persistence operation failed.', [
            'operation' => $operation,
            'meeting_id' => $meeting?->id,
            'exception' => $exception,
        ]);

        $redirect = redirect()->back()
            ->withInput()
            ->with('meeting_error', $message);

        if ($modalId) {
            $redirect->with('meeting_open_modal', $modalId);
        }

        return $redirect;
    }

    private function meetingAttributes(array $data): array
    {
        return [
            'title' => trim($data['title']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'starts_at' => $this->toUtc($data['starts_at']),
            'ends_at' => $this->toUtc($data['ends_at']),
            'location' => filled($data['location'] ?? null) ? trim($data['location']) : null,
        ];
    }

    private function toUtc(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, MeetingQueryService::BUSINESS_TIMEZONE)->utc();
    }

    private function auditPayload(Meeting $meeting, array $attendeeIds): array
    {
        return [
            'title' => $meeting->title,
            'description' => $meeting->description,
            'starts_at' => $meeting->starts_at?->toIso8601String(),
            'ends_at' => $meeting->ends_at?->toIso8601String(),
            'location' => $meeting->location,
            'created_by' => $meeting->created_by,
            'attendee_ids' => collect($attendeeIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
        ];
    }
}
