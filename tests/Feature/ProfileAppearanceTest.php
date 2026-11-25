<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\ProfileFrame;
use App\Models\User;
use App\Services\ProfileAppearanceService;
use App\Support\ProfileAppearance;
use Database\Seeders\ProfileFrameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProfileAppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_catalog_and_modal_offer_all_active_frames_to_every_role(): void
    {
        $frames = collect(ProfileAppearance::frames());
        $this->assertCount(15, $frames->where('animated', false));
        $this->assertCount(9, $frames->where('animated', true));

        foreach (['user', 'admin', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'avatar_frame' => 'crystal-energy']);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $html = $this->actingAs($user)->get(route('settings.index'))->assertOk()->getContent();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $frameQueries = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'profile_frames')));
            $this->assertCount(1, $frameQueries);
            $this->assertSame(['crystal-energy'], $frameQueries[0]['bindings']);
            $this->assertStringContainsString('limit 1', $frameQueries[0]['query']);
            $this->assertStringNotContainsString('id="settingsAppearanceModal"', $html);
            $this->assertStringContainsString('data-account-open="settingsAppearanceModal"', $html);
            $this->assertStringNotContainsString('data-frame-option', $html);
            $appearance = $this->getJson(route('settings.appearance.show'))->assertOk();
            $this->assertTrue($appearance->headers->hasCacheControlDirective('no-store'));
            $this->assertStringContainsString('data-profile-frame="crystal-energy"', $appearance->json('html'));
            $gallery = $this->getJson(route('settings.appearance.frames'))->assertOk();
            $this->assertTrue($gallery->headers->hasCacheControlDirective('private'));
            $this->assertTrue($gallery->headers->hasCacheControlDirective('no-store'));
            foreach ($frames as $frame) {
                $this->assertStringContainsString('data-frame-slug="'.$frame->slug.'"', $gallery->json('html'));
            }
        }
    }

    public function test_topbar_loads_only_the_selected_frame_once_and_reuses_the_relation(): void
    {
        foreach ([null, 'gold', 'rainbow-cloud', 'star-orbit', 'enchanted-forest'] as $slug) {
            $user = User::factory()->create(['avatar_frame' => $slug]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $html = view('layouts.components.user-dropdown', ['currentUser' => $user])->render();
            // Rendering again in the same request must reuse Eloquent's loaded relation.
            view('layouts.components.user-dropdown', ['currentUser' => $user])->render();
            $queries = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'profile_frames')));
            DB::disableQueryLog();
            $this->assertCount($slug ? 1 : 0, $queries);
            if ($slug) {
                $this->assertSame([$slug], $queries[0]['bindings']);
                $this->assertStringContainsString('limit 1', $queries[0]['query']);
            }
            $this->assertStringContainsString('data-profile-frame="'.($slug ?? 'none').'"', $html);
            $this->assertStringContainsString('data-account-avatar-content', $html);
            $this->assertStringNotContainsString('data-profile-cover', $html);
            $this->assertStringNotContainsString('data-frame-option', $html);
        }
    }

    public function test_enchanted_forest_gif_can_be_selected_and_has_a_transparent_static_poster(): void
    {
        $frame = ProfileFrame::where('slug', 'enchanted-forest')->firstOrFail();
        $this->assertTrue((bool) $frame->animated);
        $this->assertSame('animated/enchanted-forest-motion.gif', $frame->asset_path);
        $this->assertSame('animated/enchanted-forest.webp', $frame->preview_path);
        $directory = resource_path('images/profile-frames/');
        $gif = getimagesize($directory.$frame->asset_path);
        $poster = getimagesize($directory.$frame->preview_path);
        $this->assertSame([468, 468], array_slice($gif, 0, 2));
        $this->assertSame('image/gif', $gif['mime']);
        $this->assertSame('image/webp', $poster['mime']);
        $this->assertSame(array_slice($gif, 0, 2), array_slice($poster, 0, 2));
        $image = imagecreatefromwebp($directory.$frame->preview_path);
        $this->assertSame(127, (imagecolorat($image, 234, 234) >> 24) & 127);
        imagedestroy($image);

        $user = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($user)->patchJson(route('settings.appearance.update'), ['avatar_frame' => $frame->slug])
            ->assertOk()->assertJsonPath('appearance.avatar_frame', $frame->slug);
        $this->actingAs($user->fresh())->get(route('settings.index'))->assertOk()
            ->assertSee('data-profile-frame="enchanted-forest"', false)
            ->assertSee('data-profile-frame-asset="'.$frame->asset_path.'"', false)
            ->assertSee('data-profile-frame-poster="'.$frame->preview_path.'"', false);
    }

    public function test_animated_ring_openings_stay_aligned_with_the_avatar(): void
    {
        foreach (['star-orbit' => 'y', 'golden-flare' => 'y', 'cyber-neon' => 'x'] as $slug => $axis) {
            $bytes = file_get_contents(resource_path('images/profile-frames/animated/'.$slug.'-motion.webp'));
            $centers = [];
            for ($offset = 12; $offset < strlen($bytes);) {
                $type = substr($bytes, $offset, 4);
                $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
                $data = $offset + 8;
                if ($type === 'ANMF') {
                    $uint24 = fn (int $at): int => unpack('V', substr($bytes, $at, 3)."\0")[1];
                    $x = $uint24($data) * 2;
                    $y = $uint24($data + 3) * 2;
                    $width = $uint24($data + 6) + 1;
                    $height = $uint24($data + 9) + 1;
                    // Decode the existing frame payload without requiring an animated WebP extension.
                    $header = 'VP8X'.pack('V', 10)."\x10\0\0\0"
                        .substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);
                    $payload = $header.substr($bytes, $data + 16, $size - 16);
                    $frame = imagecreatefromstring('RIFF'.pack('V', strlen($payload) + 4).'WEBP'.$payload);
                    $canvas = imagecreatetruecolor(320, 320);
                    imagealphablending($canvas, false);
                    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
                    imagecopy($canvas, $frame, $x, $y, 0, 0, $width, $height);
                    $transparent = fn (int $point): bool => ((imagecolorat($canvas,
                        $axis === 'x' ? $point : 160, $axis === 'y' ? $point : 160) >> 24) & 127) > 115;
                    $low = $high = 160;
                    while ($low > 0 && $transparent($low - 1)) {
                        $low--;
                    }
                    while ($high < 319 && $transparent($high + 1)) {
                        $high++;
                    }
                    $centers[] = ($low + $high) / 2;
                    imagedestroy($canvas);
                    imagedestroy($frame);
                }
                $offset = $data + $size + ($size % 2);
            }
            $this->assertCount(6, $centers);
            foreach ($centers as $center) {
                $this->assertEqualsWithDelta(160, $center, 1.5, $slug.' opening moved away from the avatar');
            }
        }
    }

    public function test_seeding_preserves_legacy_choices_and_existing_catalog_metadata(): void
    {
        $user = User::factory()->create(['avatar_frame' => 'aurora']);
        ProfileFrame::query()->where('slug', 'aurora')->update(['is_active' => false]);
        $this->seed(ProfileFrameSeeder::class);
        $this->seed(ProfileFrameSeeder::class);

        $this->assertDatabaseCount('profile_frames', 24);
        $this->assertSame('aurora', $user->fresh()->avatar_frame);
        $this->assertFalse($user->fresh()->avatarFrame->is_active);
        $this->assertSame('aurora', ProfileAppearance::frame($user->fresh()));
        $this->assertFalse(ProfileAppearance::frames()->contains('slug', 'aurora'));
    }

    public function test_imported_frame_is_saved_rendered_and_disallowed_for_new_choices_when_inactive(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $actor = User::factory()->create(['role' => 'user', 'department_id' => $department->id, 'must_change_password' => false]);
        $this->actingAs($actor)->patchJson(route('settings.appearance.update'), ['avatar_frame' => 'crystal-energy'])
            ->assertOk()->assertJsonPath('appearance.avatar_frame', 'crystal-energy');
        $this->get(route('work-board.department', $department))->assertOk()
            ->assertSee('data-profile-frame-asset="animated/crystal-energy-motion.webp"', false);
        ProfileFrame::query()->where('slug', 'star-orbit')->update(['is_active' => false]);
        $this->patchJson(route('settings.appearance.update'), ['avatar_frame' => 'star-orbit'])
            ->assertUnprocessable()->assertJsonValidationErrors('avatar_frame');
        $this->assertSame('crystal-energy', $actor->fresh()->avatar_frame);
    }

    public function test_cover_upload_is_private_updates_only_the_actor_and_can_be_replaced_and_removed(): void
    {
        $actor = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $other = User::factory()->create(['role' => 'user']);
        $this->actingAs($actor)->patchJson(route('settings.appearance.update'), [
            'avatar_frame' => 'aurora', 'profile_cover' => UploadedFile::fake()->image('cover.png', 1200, 800),
            'user_id' => $other->id, 'role' => 'admin', 'is_department_head' => true,
        ])->assertOk()->assertJsonPath('appearance.avatar_frame', 'aurora');
        $actor->refresh();
        $old = $actor->profile_cover;
        $this->assertStringStartsWith('profile-covers/'.$actor->id.'/', $old);
        Storage::disk('local')->assertExists($old);
        $this->assertSame('image/webp', getimagesize(Storage::disk('local')->path($old))['mime']);
        Storage::disk('public')->assertMissing($old);
        $this->assertSame('user', $actor->role);
        $this->assertFalse($actor->is_department_head);
        $this->assertNull($other->fresh()->profile_cover);
        $this->assertNull($other->fresh()->avatar_frame);

        $this->patchJson(route('settings.appearance.update'), ['avatar_frame' => 'gold'])->assertOk();
        $this->assertSame($old, $actor->fresh()->profile_cover);
        $this->patchJson(route('settings.appearance.update'), [
            'avatar_frame' => 'pulse', 'profile_cover' => UploadedFile::fake()->image('next.jpg'),
        ])->assertOk();
        Storage::disk('local')->assertMissing($old);
        $replacement = $actor->fresh()->profile_cover;
        Storage::disk('local')->assertExists($replacement);
        $this->patchJson(route('settings.appearance.update'), [
            'avatar_frame' => 'none', 'remove_profile_cover' => true,
        ])->assertOk()->assertJsonPath('appearance.cover_url', null);
        Storage::disk('local')->assertMissing($replacement);
        $this->assertNull($actor->fresh()->avatar_frame);
    }

    public function test_cover_is_resized_once_and_over_budget_images_leave_the_saved_file_intact(): void
    {
        $actor = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($actor)->patchJson(route('settings.appearance.update'), [
            'avatar_frame' => 'none', 'profile_cover' => UploadedFile::fake()->image('cover.png', 2400, 1200),
        ])->assertOk();
        $stored = $actor->fresh()->profile_cover;
        $size = getimagesize(Storage::disk('local')->path($stored));
        $this->assertSame([1280, 640], array_slice($size, 0, 2));
        $this->patchJson(route('settings.appearance.update'), [
            'avatar_frame' => 'none', 'profile_cover' => UploadedFile::fake()->image('large.png', 4001, 3000),
        ])->assertUnprocessable()->assertJsonValidationErrors('profile_cover');
        $this->assertSame([$stored], Storage::disk('local')->allFiles());
        $this->assertSame($stored, $actor->fresh()->profile_cover);
    }

    public function test_cover_rejects_large_decode_before_allocating_on_a_constrained_worker(): void
    {
        $actor = User::factory()->create(['must_change_password' => false]);
        $upload = UploadedFile::fake()->image('cover.png', 3840, 2160);
        $memoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1024 * 1024));
        try {
            $this->actingAs($actor)->patchJson(route('settings.appearance.update'), [
                'avatar_frame' => 'none', 'profile_cover' => $upload,
            ])->assertUnprocessable()->assertJsonValidationErrors('profile_cover');
        } finally {
            ini_set('memory_limit', $memoryLimit);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_uploads_frames_and_conflicting_remove_are_rejected_without_changing_existing_files(): void
    {
        $actor = User::factory()->create(['avatar_frame' => 'gold']);
        foreach ([
            ['avatar_frame' => 'unknown'],
            ['avatar_frame' => 'gold', 'profile_cover' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')],
            ['avatar_frame' => 'gold', 'profile_cover' => UploadedFile::fake()->create('fake.png', 1, 'text/plain')],
            ['avatar_frame' => 'gold', 'profile_cover' => UploadedFile::fake()->image('huge.png')->size(4097)],
            ['avatar_frame' => 'gold', 'profile_cover' => UploadedFile::fake()->image('wide.png', 8193, 1)],
            ['avatar_frame' => 'gold', 'remove_profile_cover' => true, 'profile_cover' => UploadedFile::fake()->image('cover.png')],
        ] as $payload) {
            $this->actingAs($actor)->patchJson(route('settings.appearance.update'), $payload)->assertUnprocessable();
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame('gold', $actor->fresh()->avatar_frame);
    }

    public function test_cover_media_reuses_the_profile_auth_cache_contract_and_rejects_unsafe_or_foreign_paths(): void
    {
        $owner = User::factory()->create();
        $path = 'profile-covers/'.$owner->id.'/cover.png';
        Storage::disk('local')->put($path, 'cover bytes');
        $owner->update(['profile_cover' => $path]);
        $url = route('media.profile-cover', $owner);
        $this->getJson($url)->assertUnauthorized();
        $viewer = User::factory()->create();
        $first = $this->actingAs($viewer)->get($url)->assertOk();
        $this->assertTrue($first->headers->hasCacheControlDirective('private'));
        $this->assertTrue($first->headers->hasCacheControlDirective('must-revalidate'));
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));
        $etag = $first->headers->get('ETag');
        $this->withCookie(config('session.cookie'), $this->app['session.store']->getId());
        $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
        $this->actingAs($owner)->get($url, ['If-None-Match' => $etag])->assertOk();
        $this->get(route('media.show', ['path' => $path]))->assertNotFound();
        $owner->update(['profile_cover' => 'profile-covers/other/cover.png']);
        $this->get($url)->assertNotFound();
        $owner->update(['profile_cover' => 'profile-covers/'.$owner->id.'/../../secret']);
        $this->get($url)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($url, ['If-None-Match' => $etag])->assertUnauthorized();
    }

    public function test_failed_database_save_keeps_old_cover_and_removes_the_new_file(): void
    {
        $actor = User::factory()->create(['profile_cover' => 'profile-covers/old.png']);
        Storage::disk('local')->put($actor->profile_cover, 'original');
        User::saving(function (User $user): void {
            if ($user->avatar_frame === 'pulse') {
                throw new RuntimeException('Simulated save failure');
            }
        });
        try {
            app(ProfileAppearanceService::class)->update($actor, ['avatar_frame' => 'pulse'], UploadedFile::fake()->image('cover.png'));
            $this->fail('Save must fail');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated save failure', $error->getMessage());
        }
        $this->assertSame(['profile-covers/old.png'], Storage::disk('local')->allFiles());
        $this->assertSame('profile-covers/old.png', $actor->fresh()->profile_cover);
    }

    public function test_guest_inactive_and_password_setup_accounts_cannot_edit_or_read_covers(): void
    {
        $this->patchJson(route('settings.appearance.update'), ['avatar_frame' => 'gold'])->assertUnauthorized();
        foreach ([['is_active' => false], ['must_change_password' => true]] as $attributes) {
            $actor = User::factory()->create($attributes);
            $this->actingAs($actor)->getJson(route('media.profile-cover', $actor))->assertForbidden();
            $this->actingAs($actor)->patchJson(route('settings.appearance.update'), ['avatar_frame' => 'gold'])->assertForbidden();
            $this->assertNull($actor->fresh()->avatar_frame);
        }
    }

    public function test_non_json_validation_reopens_appearance_without_opening_another_account_dialog(): void
    {
        $actor = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($actor)->from(route('settings.index'))->patch(route('settings.appearance.update'), [
            'avatar_frame' => 'unknown',
        ])->assertRedirect(route('settings.index'))->assertSessionHasErrorsIn('appearance', ['avatar_frame']);
        $html = $this->get(route('settings.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-open-on-load="true"'));
        $this->assertMatchesRegularExpression('/id="settingsAppearanceModal"[^>]*data-open-on-load="true"|data-open-on-load="true"[^>]*id="settingsAppearanceModal"/', $html);
    }

    public function test_work_board_renders_saved_appearance_and_preserves_member_preview_contract(): void
    {
        $department = Department::create(['department_name' => 'IT']);
        $actor = User::factory()->create(['role' => 'user', 'department_id' => $department->id,
            'avatar_frame' => 'emerald', 'profile_cover' => 'profile-covers/test.png']);
        $response = $this->actingAs($actor)->get(route('work-board.department', $department))->assertOk();
        $response->assertSee(ProfileAppearance::coverUrl($actor), false)
            ->assertSee('data-profile-frame="emerald"', false)
            ->assertSee('bi-arrow-up-right', false)
            ->assertSee('data-bs-target="#workBoardMemberPreview"', false)
            ->assertDontSee('wb-member-card__summary', false)
            ->assertDontSee('มีงานที่กำลังดูแล');
    }
}
