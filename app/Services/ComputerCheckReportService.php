<?php

namespace App\Services;

use App\Models\ComputerCheckResult;
use App\Models\ComputerCheckSession;
use App\Support\ComputerCheckDesign;
use App\Support\TodayWorkspace;
use Illuminate\Support\Collection;

/**
 * ประกอบข้อมูลของแบบตรวจหนึ่งใบ — ใช้ร่วมกันทั้งหน้าจอและแบบตรวจ A4
 *
 * กติกาสำคัญเรื่องการเปิดย้อนหลัง
 * ---------------------------------------------------------------
 * ใบตรวจประกอบจาก "แถวผลของรอบนั้น" (computer_check_results) เสมอ ไม่ใช่จาก
 * รายการเครื่องปัจจุบัน แถวผลถูกสร้างครบทุกเครื่องตั้งแต่เปิดรอบ จำนวนเครื่อง
 * ในใบเก่าจึงเท่ากับจำนวนเครื่อง ณ วันที่ตรวจจริง แม้ภายหลังจะมีการเพิ่มหรือปิด
 * เครื่องไปแล้วก็ตาม
 *
 * ตำแหน่งโต๊ะมาจาก computer_check_workstations ชุดเดียวกับผังบนเว็บ เพื่อให้ผัง
 * สองที่ไม่มีวันเพี้ยนออกจากกัน (ข้อกำหนดใน CLAUDE.md เรื่อง Floor Plan ชุดเดียว)
 */
class ComputerCheckReportService
{
    /**
     * ข้อมูลครบชุดของแบบตรวจหนึ่งใบ
     *
     * @return array<string, mixed>
     */
    public function build(ComputerCheckSession $session): array
    {
        $results = $this->resultsOf($session);
        $summary = ComputerCheckService::summarize($results);

        return [
            'session' => $session,
            'results' => $results,
            'stations' => $this->stations($results),
            'summary' => $summary,
            'issues' => $this->issues($results),
            'header' => $this->header($session),
        ];
    }

    /**
     * ผลของรอบนี้ เรียงตามลำดับเดียวกับที่แสดงในรายการเครื่อง
     *
     * @return Collection<int, ComputerCheckResult>
     */
    public function resultsOf(ComputerCheckSession $session): Collection
    {
        return $session->results()
            ->with(['workstation', 'checkedBy:id,name'])
            ->get()
            ->sortBy([
                static fn (ComputerCheckResult $result): int => (int) ($result->workstation?->display_order ?? 0),
                static fn (ComputerCheckResult $result): string => (string) ($result->workstation?->code ?? ''),
            ])
            ->values();
    }

    /**
     * ข้อมูลที่ผังห้องต้องใช้ต่อหนึ่งโต๊ะ — รูปแบบเดียวกันทั้งผัง SVG บนเว็บ
     * และผังในแบบตรวจ A4 เพื่อให้สองที่วาดจากชุดข้อมูลเดียวกันจริง ๆ
     *
     * @param  Collection<int, ComputerCheckResult>  $results
     * @return array<int, array<string, mixed>>
     */
    public function stations(Collection $results): array
    {
        return $results
            ->filter(static fn (ComputerCheckResult $result): bool => $result->workstation !== null)
            ->map(function (ComputerCheckResult $result): array {
                $workstation = $result->workstation;
                $statuses = $result->deviceStatuses();
                $rolledUp = $result->rolledUpStatus();
                $design = ComputerCheckDesign::workstationStatus($rolledUp);

                return [
                    'id' => (int) $workstation->id,
                    'code' => (string) $workstation->code,
                    'zone' => (string) $workstation->zone,
                    'x' => (int) $workstation->position_x,
                    'y' => (int) $workstation->position_y,
                    'width' => (int) $workstation->width,
                    'height' => (int) $workstation->height,
                    'status' => $rolledUp,
                    'status_label' => $design['label'],
                    'status_icon' => $design['icon'],
                    'devices' => $statuses,
                    'note' => $result->note,
                    'checked_by' => $result->checkedBy?->name,
                    'checked_at' => $result->checked_at === null
                        ? null
                        : TodayWorkspace::businessNow($result->checked_at)->format('H:i'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * รายการปัญหาท้ายแบบตรวจ — เครื่องไหน อุปกรณ์อะไร รายละเอียดว่าอย่างไร
     *
     * @param  Collection<int, ComputerCheckResult>  $results
     * @return array<int, array<string, mixed>>
     */
    public function issues(Collection $results): array
    {
        return $results
            ->filter(static fn (ComputerCheckResult $result): bool => $result->rolledUpStatus() === 'issue')
            ->map(static fn (ComputerCheckResult $result): array => [
                'code' => (string) ($result->workstation?->code ?? 'ไม่ทราบเครื่อง'),
                'devices' => $result->failedDeviceLabels(),
                'note' => trim((string) $result->note),
                'checked_by' => $result->checkedBy?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * หัวแบบตรวจ — ข้อมูลเดียวกับช่องที่ต้องกรอกด้วยมือบนกระดาษ
     *
     * @return array<string, mixed>
     */
    public function header(ComputerCheckSession $session): array
    {
        $clock = static fn ($value): ?string => $value === null
            ? null
            : TodayWorkspace::businessNow($value)->format('H:i');

        return [
            'form_code' => ComputerCheckDesign::FORM_CODE,
            'room' => ComputerCheckDesign::ROOM_NAME,
            'date_label' => $session->check_date === null
                ? '—'
                : TodayWorkspace::dateRangeLabel($session->check_date, $session->check_date),
            'date_value' => $session->check_date?->format('Y-m-d'),
            'shift' => $session->shift,
            'shift_label' => ComputerCheckDesign::shiftFullLabel($session->shift),
            'checker_1' => $session->checkerOne?->name ?? '—',
            'checker_2' => $session->checkerTwo?->name ?? '—',
            'started_label' => $clock($session->started_at) ?? '—',
            'completed_label' => $clock($session->completed_at) ?? '—',
            'status' => $session->status,
            'status_label' => ComputerCheckDesign::SESSION_STATUSES[$session->status]['label'] ?? $session->status,
        ];
    }

    /**
     * แถวของ "ประวัติการตรวจ"
     *
     * @param  Collection<int, ComputerCheckSession>  $sessions  ต้อง eager load results ไว้แล้ว
     * @return array<int, array<string, mixed>>
     */
    public function historyRows(Collection $sessions): array
    {
        return $sessions->map(function (ComputerCheckSession $session): array {
            $summary = ComputerCheckService::summarize($session->results);
            $clock = static fn ($value): ?string => $value === null
                ? null
                : TodayWorkspace::businessNow($value)->format('H:i');

            return [
                'id' => (int) $session->id,
                'date_label' => $session->check_date === null
                    ? '—'
                    : TodayWorkspace::dateRangeLabel($session->check_date, $session->check_date),
                'shift' => $session->shift,
                'shift_label' => ComputerCheckDesign::shiftLabel($session->shift),
                'checkers' => collect([$session->checkerOne?->name, $session->checkerTwo?->name])
                    ->filter()
                    ->join(', ') ?: '—',
                'total' => $summary['total'],
                'normal' => $summary['normal'],
                'issue' => $summary['issue'],
                'checked' => $summary['checked'],
                'time_range' => $clock($session->started_at) === null
                    ? '—'
                    : $clock($session->started_at).' - '.($clock($session->completed_at) ?? 'ยังไม่ปิดรอบ'),
                'status' => $session->status,
                'status_label' => ComputerCheckDesign::SESSION_STATUSES[$session->status]['label'] ?? $session->status,
            ];
        })->values()->all();
    }
}
