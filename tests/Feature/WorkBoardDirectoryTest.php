<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Support\WorkBoardDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkBoardDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_themes_links_and_counts_use_live_department_data(): void
    {
        $it = Department::create(['department_name' => 'IT']);
        $marketing = Department::create(['department_name' => 'Marketing']);
        $future = Department::create(['department_name' => 'Future Team']);
        $viewer = User::factory()->create(['role' => 'user', 'department_id' => $it->id]);
        User::factory()->count(2)->create(['role' => 'user', 'department_id' => $it->id]);
        User::factory()->count(4)->create(['role' => 'user', 'department_id' => $marketing->id]);
        User::factory()->create(['role' => 'user', 'department_id' => $it->id, 'is_active' => false]);
        User::factory()->create(['role' => 'admin', 'department_id' => $it->id]);
        $this->acknowledgeDailyBrief($viewer);

        $response = $this->actingAs($viewer)->get(route('work-board.index'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $cards = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " wb-department-card ")]');
        $this->assertCount(3, $cards);

        foreach ([[$it, 'technology', 3], [$marketing, 'creative', 4], [$future, 'default', 0]] as [$department, $theme, $count]) {
            $card = $xpath->query('//a[@aria-labelledby="wb-department-name-'.$department->id.'"]')->item(0);
            $this->assertNotNull($card);
            $this->assertStringContainsString('wb-department-card--'.$theme, $card->getAttribute('class'));
            $this->assertSame(route('work-board.department', $department), $card->getAttribute('href'));
            $this->assertSame($department->department_name, $xpath->query('.//h3', $card)->item(0)->textContent);
            $this->assertSame($count.' สมาชิก', trim($xpath->query('.//p/span', $card)->item(0)->textContent));
            $this->assertCount(0, $xpath->query('.//a | .//button | .//img | .//script | .//*[@style] | .//*[@onclick]', $card));
        }

        $response->assertSee('3 แผนก · 7 สมาชิก')->assertDontSee('ข้อมูลล่าสุดจากระบบ Smart Goal');
        User::factory()->create(['role' => 'user', 'department_id' => $future->id]);
        $this->actingAs($viewer)->get(route('work-board.index'))->assertOk()->assertSee('3 แผนก · 8 สมาชิก');
        $this->actingAs($viewer)->get(route('work-board.department', $marketing))->assertOk();
    }

    public function test_directory_theme_normalization_and_default_need_no_database_queries(): void
    {
        DB::enableQueryLog();
        foreach (['  iT  ' => 'technology', 'Information Technology' => 'technology', 'เทคโนโลยีสารสนเทศ' => 'technology', 'MARKETING' => 'creative', 'การตลาด' => 'creative', 'New Department' => 'default'] as $name => $theme) {
            $this->assertSame($theme, WorkBoardDesign::departmentDirectoryTheme(new Department(['department_name' => $name])));
        }
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_rendering_the_shared_card_does_not_query_or_load_members(): void
    {
        $department = Department::create(['department_name' => 'Marketing']);
        $department->setAttribute('member_count', 23);
        DB::enableQueryLog();
        $html = view('work-board.components.department-card', compact('department'))->render();
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertStringContainsString('23 สมาชิก', $html);
        $this->assertFalse($department->relationLoaded('users'));
    }

    public function test_empty_directory_keeps_the_existing_empty_state(): void
    {
        $viewer = User::factory()->create(['role' => 'user', 'department_id' => null]);
        $this->actingAs($viewer)->get(route('work-board.index'))->assertOk()
            ->assertSee('ยังไม่มีข้อมูลแผนก')->assertSee('0 แผนก · 0 สมาชิก');
    }
}
