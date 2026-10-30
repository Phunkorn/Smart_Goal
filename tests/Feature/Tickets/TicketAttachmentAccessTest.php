<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * รูปประกอบ Ticket เปิดได้เฉพาะผู้ที่เห็น Ticket นั้น (TicketPolicy::view) ผ่าน MediaController
 */
class TicketAttachmentAccessTest extends TestCase
{
    use BuildsTickets;
    use BuildsTicketUploads;
    use RefreshDatabase;

    private User $requester;

    private Ticket $ticket;

    private TicketAttachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->requester = $this->reporterIn($this->overseenDepartment('Sales'));
        $this->ticket = app(TicketSubmissionService::class)->submitInternal(
            $this->requester,
            $this->internalForm(['location_id' => null]),
            [$this->photo('screen.png')]
        );
        $this->attachment = $this->ticket->attachments()->sole();
    }

    public function test_people_who_can_see_the_ticket_can_open_its_photo(): void
    {
        $sales = $this->requester->department;

        foreach ([
            'requester' => $this->requester,
            'department head' => $this->overseeingHead($sales),
            'agent' => $this->agent(),
            'admin' => $this->staff($this->department('Management'), role: 'admin'),
        ] as $who => $user) {
            $response = $this->actingAs($user)->get($this->url());
            $response->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $who);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $who);
        }
    }

    public function test_everyone_else_is_refused(): void
    {
        foreach ([
            'colleague' => $this->reporterIn($this->requester->department),
            'other department head' => $this->staff($this->department('HR'), head: true),
            'viewer' => $this->staff($this->requester->department, role: 'viewer'),
        ] as $who => $user) {
            $this->actingAs($user)->get($this->url())->assertForbidden();
        }
    }

    public function test_guests_never_reach_the_file(): void
    {
        $this->get($this->url())->assertRedirect(route('login'));
    }

    public function test_the_file_is_private_and_its_path_is_never_public(): void
    {
        Storage::disk('local')->assertExists($this->attachment->file_path);
        $this->assertFalse(Storage::disk('public')->exists($this->attachment->file_path));
        // URL อ้างถึง id ของแถว ไม่ใช่ตำแหน่งไฟล์บนดิสก์
        $this->assertStringNotContainsString($this->attachment->file_path, $this->url());
        $this->assertStringNotContainsString(basename($this->attachment->file_path), $this->url());
    }

    private function url(): string
    {
        return route('media.ticket-attachments.show', $this->attachment);
    }
}
