<?php

namespace App\Services;

use App\Models\WorkspaceBoard;
use App\Support\AuditTrail;
use Illuminate\Support\Facades\DB;

/** All callers hold the board row lock shared with document saves and uploads. */
final class WorkspaceAttachmentLifecycle
{
    public const RECOVERY_DAYS = 7;

    public function references(array $document): array
    {
        return collect($document['elements'] ?? [])->where('type', 'image')->pluck('attachmentId')->unique()->values()->all();
    }

    public function reconcile(WorkspaceBoard $board, array $document, array $previous): void
    {
        $ids = $this->references($document);
        $oldIds = $this->references($previous);
        $added = array_values(array_diff($ids, $oldIds));
        $removed = array_values(array_diff($oldIds, $ids));
        if ($added !== []) {
            $board->attachments()->whereIn('id', $added)->update(['claimed_at' => DB::raw('COALESCE(claimed_at, created_at)'), 'released_at' => null]);
        }
        if ($removed !== []) {
            $board->attachments()->whereIn('id', $removed)->update(['claimed_at' => DB::raw('COALESCE(claimed_at, created_at)'), 'released_at' => now()]);
        }
    }

    public function quotaCount(WorkspaceBoard $board): int
    {
        $ids = $this->references($board->document()->value('document') ?? []);

        return $board->attachments()->where(fn ($q) => $q->whereIn('id', $ids)
            ->orWhere(fn ($pending) => $pending->whereNull('claimed_at')->where('created_at', '>=', now()->subMinutes(15))))->count();
    }

    public function prune(WorkspaceBoard $board, bool $dryRun = false): int
    {
        return DB::transaction(function () use ($board, $dryRun): int {
            $board = WorkspaceBoard::whereKey($board->id)->lockForUpdate()->firstOrFail();
            $ids = $this->references($board->document()->value('document') ?? []);
            $unused = $board->attachments()->whereNotIn('id', $ids)
                ->where('updated_at', '<', now()->subDays(self::RECOVERY_DAYS))->get();
            if (! $dryRun) {
                foreach ($unused as $attachment) {
                    // Standard recoverable trash retains the actual file until the existing purge workflow.
                    AuditTrail::trash($attachment, null, ['attachment' => $attachment->attributesToArray(), 'board' => ['id' => $board->id, 'title' => $board->title]]);
                    $attachment->delete();
                }
            }

            return $unused->count();
        });
    }
}
