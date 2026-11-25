<?php

namespace App\Console\Commands;

use App\Models\WorkspaceBoard;
use App\Services\WorkspaceAttachmentLifecycle;
use Illuminate\Console\Command;

class PruneWorkspaceAttachments extends Command
{
    protected $signature = 'workspace:prune-attachments {--dry-run : Count eligible unused files without changing data}';

    protected $description = 'Move unused board images older than seven days to recoverable trash';

    public function handle(WorkspaceAttachmentLifecycle $lifecycle): int
    {
        $count = 0;
        WorkspaceBoard::query()->eachById(function (WorkspaceBoard $board) use ($lifecycle, &$count): void {
            $count += $lifecycle->prune($board, (bool) $this->option('dry-run'));
        });
        $this->info("Eligible unused image(s): {$count}");

        return self::SUCCESS;
    }
}
