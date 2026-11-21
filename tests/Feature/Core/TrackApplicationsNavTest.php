<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Track My Applications in the sidebar is one link (no one-item dropdown),
 * and its "View details" page has exactly one way back.
 */
class TrackApplicationsNavTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_the_sidebar_shows_one_link_with_no_overview_sub_item(): void
    {
        $student = $this->student();
        $html = $this->actingAs($student)->get(route('dashboard'))->assertOk()->getContent();

        $link = '<a href="'.route('applications.index').'" class="nav-item ';
        $this->assertSame(1, substr_count($html, $link), 'one direct nav-item link');
        $this->assertStringNotContainsString('nav-tree-trigger" title="Track My Applications"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '#<a href="'.preg_quote(route('applications.index'), '#').'" class="nav-subitem[^"]*">Overview</a>#', $html);

        $this->actingAs($student)->get(route('applications.index'))->assertOk();
    }

    public function test_the_link_stays_active_on_the_list_and_on_a_detail_page(): void
    {
        $student = $this->student();
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'travel', 'status' => Application::STATUS_DRAFT]);
        foreach ([route('applications.index'), route('applications.show', $application)] as $url) {
            $html = $this->actingAs($student)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('applications.index'), '#').'" class="nav-item\s+active\s*"#', $html, $url);
        }
    }

    public function test_staff_do_not_get_the_item(): void
    {
        foreach ([$this->supervisor(), $this->cgs(), $this->admin()] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('Track My Applications');
        }
    }

    public function test_the_detail_page_has_exactly_one_back_link_to_track_my_applications(): void
    {
        $student = $this->student();
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'travel', 'status' => Application::STATUS_DRAFT]);

        // Arriving from somewhere else (the Documents page) must not change where it goes.
        $html = $this->actingAs($student)
            ->from(route('documents.index'))
            ->get(route('applications.show', $application))
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Back to Track My Applications'));
        $this->assertStringNotContainsString('class="page-header-back"', $html);
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('applications.index'), '#').'">&larr; Back to Track My Applications</a>#', $html);
    }
}
