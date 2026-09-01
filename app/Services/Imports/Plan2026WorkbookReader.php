<?php

namespace App\Services\Imports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class Plan2026WorkbookReader
{
    /**
     * @return array<string, mixed>
     */
    public function readConfiguredWorkbook(): array
    {
        return $this->read($this->configuredSourcePath());
    }

    public function configuredSourcePath(): string
    {
        $relativePath = (string) config('imports.plan_2026.source_path');
        $importsRoot = realpath(storage_path('app/imports'));
        $candidate = realpath(storage_path('app/'.ltrim(str_replace('\\', '/', $relativePath), '/')));

        if ($importsRoot === false || $candidate === false || ! is_file($candidate)) {
            throw new RuntimeException('ไม่พบไฟล์นำเข้าที่กำหนดภายใต้ storage/app/imports');
        }

        $rootPrefix = rtrim(strtolower(str_replace('\\', '/', $importsRoot)), '/').'/';
        $normalizedCandidate = strtolower(str_replace('\\', '/', $candidate));

        if (! str_starts_with($normalizedCandidate, $rootPrefix)) {
            throw new RuntimeException('พาธไฟล์นำเข้าต้องอยู่ภายใต้ storage/app/imports เท่านั้น');
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        $actualHash = strtolower((string) hash_file('sha256', $path));
        $expectedHash = strtolower((string) config('imports.plan_2026.expected_sha256'));

        if ($expectedHash === '' || ! hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException("SHA-256 ของ workbook ไม่ตรงกับค่าที่อนุมัติ (actual: {$actualHash})");
        }

        $spreadsheet = IOFactory::load($path);

        try {
            $this->validateSheets($spreadsheet->getSheetNames());

            $goalSheetName = (string) config('imports.plan_2026.goal_sheet', 'Goals');
            $goalSheet = $spreadsheet->getSheetByName($goalSheetName);

            if (! $goalSheet) {
                throw new RuntimeException("ไม่พบ sheet {$goalSheetName}");
            }

            $this->validateHeaders($goalSheet, [
                'เป้าหมาย',
                'รายการสำคัญ',
                'เจ้าของ',
                'ผู้ช่วย',
                'สถานะ',
                'วันที่เริ่มต้น',
                'วันที่สิ้นสุด',
                'ไฟล์ที่เกี่ยวข้อง',
                'โน้ต',
            ]);

            $goalRegistry = $this->readGoalRegistry($goalSheet);
            $projects = [];
            $skipped = [];
            $candidateCount = 0;

            foreach ((array) config('imports.plan_2026.projects', []) as $definition) {
                $sheetName = (string) $definition['sheet'];
                $sheet = $spreadsheet->getSheetByName($sheetName);

                if (! $sheet) {
                    throw new RuntimeException("ไม่พบ project sheet: {$sheetName}");
                }

                $hasCollaborator = filled($definition['collaborator_column'] ?? null);
                $this->validateHeaders($sheet, $hasCollaborator
                    ? ['เป้าหมาย', 'รายการสำคัญ', 'เจ้าของ', 'เจ้าของ 2', 'สถานะ', 'วันที่เริ่มต้น', 'วันที่สิ้นสุด', 'ไฟล์ที่เกี่ยวข้อง', 'โน้ต']
                    : ['เป้าหมาย', 'รายการสำคัญ', 'เจ้าของ', 'สถานะ', 'วันที่เริ่มต้น', 'วันที่สิ้นสุด', 'ไฟล์ที่เกี่ยวข้อง', 'โน้ต']);

                $goalKey = $this->normalizeLookup((string) $definition['goal_name']);
                $goal = $goalRegistry[$goalKey] ?? null;

                if (! $goal) {
                    throw new RuntimeException("ไม่พบ project mapping ใน Goals: {$definition['goal_name']}");
                }

                $tasks = [];

                for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                    $title = $this->cellText($sheet->getCell("A{$row}"));

                    if ($title === '') {
                        continue;
                    }

                    $candidateCount++;
                    $parsed = $this->readTaskRow($sheet, $row, $hasCollaborator);

                    if (isset($parsed['skip_reason'])) {
                        $skipped[] = [
                            'sheet' => $sheetName,
                            'row' => $row,
                            'title' => $title,
                            'reason' => $parsed['skip_reason'],
                        ];

                        continue;
                    }

                    $tasks[] = $parsed;
                }

                if ($tasks === []) {
                    continue;
                }

                $projects[] = [
                    'source_sheet' => $sheetName,
                    'source_row' => $goal['row'],
                    'name' => (string) $definition['name'],
                    'priority' => $goal['priority'],
                    'source_reference' => $goal['source_reference'],
                    'goal_order' => $goal['row'],
                    'tasks' => $tasks,
                ];
            }

            usort($projects, fn (array $left, array $right): int => $left['goal_order'] <=> $right['goal_order']);

            foreach ($projects as $index => &$project) {
                $project['sort_order'] = $index + 1;
                unset($project['goal_order']);
            }
            unset($project);

            if (count($projects) !== count((array) config('imports.plan_2026.projects', []))) {
                throw new RuntimeException('มี project sheet ที่ไม่มีงานซึ่งผ่านเงื่อนไขนำเข้า');
            }

            $tasks = collect($projects)->flatMap(fn (array $project) => $project['tasks']);
            $warnings = $tasks
                ->groupBy(fn (array $task) => $this->normalizeLookup($task['job_topic']))
                ->filter(fn ($group) => $group->count() > 1)
                ->map(fn ($group) => sprintf(
                    'พบชื่องานซ้ำ "%s" จำนวน %d แถว (นำเข้าแยกตาม source row)',
                    $group->first()['job_topic'],
                    $group->count()
                ))
                ->values()
                ->all();

            return [
                'source_path' => $path,
                'source_hash' => $actualHash,
                'source_version' => (string) config('imports.plan_2026.source_version'),
                'projects' => $projects,
                'skipped' => $skipped,
                'warnings' => $warnings,
                'stats' => [
                    'candidates' => $candidateCount,
                    'projects' => count($projects),
                    'tasks' => $tasks->count(),
                    'collaborators' => $tasks->sum(fn (array $task) => count($task['collaborator_emails'])),
                    'owners' => $tasks->countBy('owner_email')->all(),
                    'statuses' => $tasks->countBy('job_status')->all(),
                    'skipped' => count($skipped),
                    'skipped_reasons' => collect($skipped)->countBy('reason')->all(),
                ],
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  array<int, string>  $actualSheets
     */
    private function validateSheets(array $actualSheets): void
    {
        $expectedSheets = array_values((array) config('imports.plan_2026.expected_sheets', []));
        $missing = array_values(array_diff($expectedSheets, $actualSheets));
        $unexpected = array_values(array_diff($actualSheets, $expectedSheets));

        if ($missing !== [] || $unexpected !== []) {
            throw new RuntimeException(sprintf(
                'โครงสร้าง sheet ไม่ตรง: missing=[%s], unexpected=[%s]',
                implode(', ', $missing),
                implode(', ', $unexpected)
            ));
        }
    }

    /**
     * @param  array<int, string>  $expected
     */
    private function validateHeaders(Worksheet $sheet, array $expected): void
    {
        foreach ($expected as $offset => $header) {
            $column = Coordinate::stringFromColumnIndex($offset + 1);
            $actual = $this->cellText($sheet->getCell("{$column}1"));

            if ($this->normalizeLookup($actual) !== $this->normalizeLookup($header)) {
                throw new RuntimeException(sprintf(
                    'Header ไม่ตรงที่ %s!%s1: expected "%s", actual "%s"',
                    $sheet->getTitle(),
                    $column,
                    $header,
                    $actual
                ));
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readGoalRegistry(Worksheet $sheet): array
    {
        $registry = [];

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $name = $this->cellText($sheet->getCell("A{$row}"));

            if ($name === '') {
                continue;
            }

            $key = $this->normalizeLookup($name);

            if (isset($registry[$key])) {
                throw new RuntimeException("ชื่อ Project ใน Goals ซ้ำ: {$name}");
            }

            $priorityText = $this->cellText($sheet->getCell("B{$row}"));
            $registry[$key] = [
                'row' => $row,
                'priority' => $priorityText === '' ? 2 : $this->mapPriority($priorityText, "Goals!B{$row}"),
                'source_reference' => $this->referenceText($sheet->getCell("H{$row}")),
            ];
        }

        return $registry;
    }

    /**
     * @return array<string, mixed>
     */
    private function readTaskRow(Worksheet $sheet, int $row, bool $hasCollaborator): array
    {
        $sheetName = $sheet->getTitle();
        $title = $this->cellText($sheet->getCell("A{$row}"));
        $ownerCell = $sheet->getCell("C{$row}");
        $ownerDisplay = $this->cellText($ownerCell);
        $ownerEmail = $this->emailFromCell($ownerCell);

        if ($ownerDisplay === '' && $ownerEmail === null) {
            return ['skip_reason' => 'missing_owner'];
        }

        if ($ownerEmail === null) {
            throw new RuntimeException("ไม่พบ mailto owner ที่ {$sheetName}!C{$row}");
        }

        $this->assertAllowedEmail($ownerEmail, "{$sheetName}!C{$row}");

        $statusColumn = $hasCollaborator ? 'E' : 'D';
        $startColumn = $hasCollaborator ? 'F' : 'E';
        $dueColumn = $hasCollaborator ? 'G' : 'F';
        $referenceColumn = $hasCollaborator ? 'H' : 'G';
        $notesColumn = $hasCollaborator ? 'I' : 'H';
        $startCell = $sheet->getCell("{$startColumn}{$row}");
        $dueCell = $sheet->getCell("{$dueColumn}{$row}");

        if ($this->cellText($startCell) === '' || $this->cellText($dueCell) === '') {
            return ['skip_reason' => 'missing_required_date'];
        }

        $start = $this->parseDate($startCell, "{$sheetName}!{$startColumn}{$row}");
        $due = $this->parseDate($dueCell, "{$sheetName}!{$dueColumn}{$row}");

        if ($due->lt($start)) {
            throw new RuntimeException("วันที่สิ้นสุดก่อนวันที่เริ่มต้นที่ {$sheetName}!{$row}");
        }

        $collaboratorEmails = [];

        if ($hasCollaborator) {
            $collaboratorCell = $sheet->getCell("D{$row}");
            $collaboratorDisplay = $this->cellText($collaboratorCell);
            $collaboratorEmail = $this->emailFromCell($collaboratorCell);

            if ($collaboratorDisplay !== '' || $collaboratorEmail !== null) {
                if ($collaboratorEmail === null) {
                    throw new RuntimeException("ไม่พบ mailto collaborator ที่ {$sheetName}!D{$row}");
                }

                $this->assertAllowedEmail($collaboratorEmail, "{$sheetName}!D{$row}");

                if ($collaboratorEmail === $ownerEmail) {
                    throw new RuntimeException("assignee ซ้ำกับ collaborator ที่ {$sheetName}!D{$row}");
                }

                $collaboratorEmails[] = $collaboratorEmail;
            }
        }

        $notes = $this->cellText($sheet->getCell("{$notesColumn}{$row}"));
        $reference = $this->referenceText($sheet->getCell("{$referenceColumn}{$row}"));
        $details = collect([
            $notes !== '' ? $notes : null,
            $reference !== null ? 'ไฟล์ที่เกี่ยวข้อง: '.$reference : null,
        ])->filter()->implode("\n");

        if (mb_strlen($title) > 255) {
            throw new RuntimeException("ชื่องานยาวเกิน 255 ตัวอักษรที่ {$sheetName}!A{$row}");
        }

        return [
            'source_row' => $row,
            'job_topic' => $title,
            'job_details' => $details !== '' ? $details : null,
            'owner_email' => $ownerEmail,
            'owner_display' => $ownerDisplay,
            'collaborator_emails' => $collaboratorEmails,
            'job_priority' => $this->mapPriority(
                $this->cellText($sheet->getCell("B{$row}")),
                "{$sheetName}!B{$row}"
            ),
            'job_status' => $this->mapStatus(
                $this->cellText($sheet->getCell("{$statusColumn}{$row}")),
                "{$sheetName}!{$statusColumn}{$row}"
            ),
            'job_start_at' => $start,
            'job_due_at' => $due,
            'source_reference' => $reference,
        ];
    }

    private function mapPriority(string $value, string $cell): int
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', '', $value) ?? '');
        $map = [
            'routine' => 1,
            'สำคัญไม่ด่วน' => 2,
            'สำคัญด่วน' => 3,
            'ด่วนไม่ค่อยสำคัญ' => 4,
            'ไม่รีบไม่มีกำหนด' => 5,
        ];

        if (! isset($map[$normalized])) {
            throw new RuntimeException("ไม่รู้จัก priority '{$value}' ที่ {$cell}");
        }

        return $map[$normalized];
    }

    private function mapStatus(string $value, string $cell): int
    {
        $normalized = mb_strtoupper($this->normalizeLookup($value));
        $map = [
            'WAIT' => 2,
            'IN PROCESS' => 2,
            'DONE' => 4,
            'STRUCK!!' => 5,
        ];

        if (! isset($map[$normalized])) {
            throw new RuntimeException("ไม่รู้จัก status '{$value}' ที่ {$cell}");
        }

        return $map[$normalized];
    }

    private function parseDate(Cell $cell, string $coordinate): CarbonImmutable
    {
        $value = $cell->getValue();

        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->startOfDay();
            }

            if (is_numeric($value)) {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
            }

            $text = $this->cellText($cell);

            foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
                $date = CarbonImmutable::createFromFormat('!'.$format, $text);

                if ($date !== false && $date->format($format) === $text) {
                    return $date;
                }
            }
        } catch (Throwable) {
            // Converted to one deterministic validation error below.
        }

        throw new RuntimeException("รูปแบบวันที่ไม่ถูกต้องที่ {$coordinate}");
    }

    private function emailFromCell(Cell $cell): ?string
    {
        $url = trim((string) $cell->getHyperlink()->getUrl());

        if (! str_starts_with(strtolower($url), 'mailto:')) {
            return null;
        }

        $email = strtolower(trim((string) strtok(substr($url, 7), '?')));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function assertAllowedEmail(string $email, string $cell): void
    {
        $allowed = array_change_key_case((array) config('imports.plan_2026.owner_emails', []), CASE_LOWER);

        if (! array_key_exists($email, $allowed)) {
            throw new RuntimeException("ไม่พบ owner mapping สำหรับ {$email} ที่ {$cell}");
        }
    }

    private function referenceText(Cell $cell): ?string
    {
        $url = trim((string) $cell->getHyperlink()->getUrl());
        $value = $this->cellText($cell);
        $reference = $url !== '' ? $url : $value;

        return $reference !== '' ? $reference : null;
    }

    private function cellText(Cell $cell): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) $cell->getFormattedValue())));
    }

    private function normalizeLookup(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $value))));
    }
}
