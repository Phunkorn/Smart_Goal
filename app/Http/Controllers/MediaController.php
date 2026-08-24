<?php

namespace App\Http\Controllers;

use App\Models\JobImage;
use App\Models\User;
use App\Models\WorkOrderListAttachment;
use App\Support\ProtectedMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function profile(User $user): BinaryFileResponse
    {
        $path = (string) $user->profile_image;

        abort_unless(str_starts_with($path, 'profiles/'), 404);

        return $this->fileResponse(ProtectedMedia::profileAbsolutePath($path));
    }

    public function taskAttachment(Request $request, JobImage $attachment): BinaryFileResponse
    {
        $job = $attachment->job()->with('collaborators')->firstOrFail();

        Gate::forUser($request->user())->authorize('view', $job);

        return $this->attachmentResponse(
            $attachment->file_path,
            'job-attachments/'.$job->job_id.'/'
        );
    }

    public function projectAttachment(
        Request $request,
        WorkOrderListAttachment $attachment
    ): BinaryFileResponse {
        $project = $attachment->project()->firstOrFail();

        Gate::forUser($request->user())->authorize('view', $project);

        return $this->attachmentResponse(
            $attachment->file_path,
            'project-attachments/'.$project->id.'/'
        );
    }

    /**
     * Compatibility endpoint for links generated before attachments received
     * typed, policy-aware routes. Database ownership is resolved before a file
     * is served, so an arbitrary public-disk path is never enough for access.
     */
    public function legacy(Request $request, string $path): BinaryFileResponse
    {
        abort_unless(ProtectedMedia::isSafeRelativePath($path), 404);

        $taskAttachment = JobImage::query()->where('file_path', $path)->first();
        if ($taskAttachment) {
            return $this->taskAttachment($request, $taskAttachment);
        }

        $projectAttachment = WorkOrderListAttachment::query()->where('file_path', $path)->first();
        if ($projectAttachment) {
            return $this->projectAttachment($request, $projectAttachment);
        }

        $profileOwner = User::query()->where('profile_image', $path)->first();
        if ($profileOwner) {
            return $this->profile($profileOwner);
        }

        abort(404);
    }

    private function attachmentResponse(string $path, string $expectedPrefix): BinaryFileResponse
    {
        abort_unless(
            ProtectedMedia::isSafeRelativePath($path) && str_starts_with($path, $expectedPrefix),
            404
        );

        return $this->fileResponse(ProtectedMedia::attachmentAbsolutePath($path));
    }

    private function fileResponse(?string $absolutePath): BinaryFileResponse
    {
        abort_unless($absolutePath !== null, 404);

        $response = response()->file($absolutePath, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
