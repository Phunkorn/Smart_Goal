<?php

use App\Support\ProtectedMedia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $this->moveTable('job_images', 'job_id', 'job-attachments/');
        $this->moveTable(
            'work_order_list_attachments',
            'work_order_list_id',
            'project-attachments/'
        );
    }

    public function down(): void
    {
        // Intentionally keep attachments private on rollback. Moving them back
        // under public/storage would recreate the authorization bypass.
    }

    private function moveTable(string $table, string $parentColumn, string $directory): void
    {
        DB::table($table)
            ->select(['id', $parentColumn, 'file_path'])
            ->orderBy('id')
            ->chunkById(200, function ($attachments) use ($directory, $parentColumn, $table): void {
                foreach ($attachments as $attachment) {
                    $path = $attachment->file_path;
                    $parentId = $attachment->{$parentColumn};
                    $expectedPrefix = $directory.$parentId.'/';

                    if (
                        ! is_string($path)
                        || ! is_numeric($parentId)
                        || ! ProtectedMedia::isSafeRelativePath($path)
                        || ! str_starts_with($path, $expectedPrefix)
                    ) {
                        throw new RuntimeException(
                            "Attachment {$table}#{$attachment->id} has an unsafe or unexpected path."
                        );
                    }

                    $this->movePath($path);
                }
            });
    }

    private function movePath(string $path): void
    {
        $private = Storage::disk('local');
        $public = Storage::disk('public');

        if ($private->exists($path)) {
            if (! $public->exists($path)) {
                return;
            }

            $this->assertFilesMatch($public, $path, $private, $path);
            $this->deletePublicSource($public, $path);

            return;
        }

        if (! $public->exists($path)) {
            return;
        }

        $temporaryPath = $this->temporaryPath($path);
        $this->deleteTemporaryFile($private, $temporaryPath);
        $stream = $public->readStream($path);

        if ($stream === false) {
            throw new RuntimeException('Unable to read legacy attachment: '.$path);
        }

        try {
            $stored = $private->writeStream($temporaryPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            $this->deleteTemporaryFile($private, $temporaryPath);

            throw new RuntimeException('Unable to move attachment to private storage: '.$path);
        }

        try {
            $this->assertFilesMatch($public, $path, $private, $temporaryPath);

            if (! $private->move($temporaryPath, $path) || ! $private->exists($path)) {
                throw new RuntimeException('Unable to promote verified attachment: '.$path);
            }

            $this->assertFilesMatch($public, $path, $private, $path);
        } catch (\Throwable $exception) {
            $this->deleteTemporaryFile($private, $temporaryPath);

            if ($private->exists($path)) {
                $private->delete($path);
            }

            throw $exception;
        }

        $this->deletePublicSource($public, $path);
    }

    private function assertFilesMatch(
        FilesystemAdapter $sourceDisk,
        string $sourcePath,
        FilesystemAdapter $destinationDisk,
        string $destinationPath
    ): void {
        $source = $this->fingerprint($sourceDisk, $sourcePath);
        $destination = $this->fingerprint($destinationDisk, $destinationPath);

        if ($source !== $destination) {
            throw new RuntimeException('Attachment verification failed: '.$sourcePath);
        }
    }

    /** @return array{size: int, sha256: string} */
    private function fingerprint(FilesystemAdapter $disk, string $path): array
    {
        if (! $disk->exists($path)) {
            throw new RuntimeException('Unable to verify missing attachment: '.$path);
        }

        $size = $disk->size($path);
        $stream = $disk->readStream($path);

        if ($stream === false) {
            throw new RuntimeException('Unable to read attachment for verification: '.$path);
        }

        try {
            $hash = hash_init('sha256');
            $bytesRead = hash_update_stream($hash, $stream);

            if ($bytesRead === false) {
                throw new RuntimeException('Unable to hash attachment: '.$path);
            }

            if ($bytesRead !== $size) {
                throw new RuntimeException('Attachment size verification failed: '.$path);
            }

            return [
                'size' => $size,
                'sha256' => hash_final($hash),
            ];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function deletePublicSource(FilesystemAdapter $public, string $path): void
    {
        if (! $public->delete($path) || $public->exists($path)) {
            throw new RuntimeException('Unable to remove verified public attachment: '.$path);
        }
    }

    private function deleteTemporaryFile(FilesystemAdapter $private, string $temporaryPath): void
    {
        if ($private->exists($temporaryPath) && ! $private->delete($temporaryPath)) {
            throw new RuntimeException('Unable to remove temporary attachment: '.$temporaryPath);
        }
    }

    private function temporaryPath(string $path): string
    {
        return '.attachment-migration/'.hash('sha256', $path).'.part';
    }
};
