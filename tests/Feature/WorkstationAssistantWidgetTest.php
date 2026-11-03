<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationPendingSelection;
use App\Modules\Chloe\Services\WorkstationAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * PART 2/3/4: the floating assistant button/panel partial
 * (workstation/_assistant-widget.blade.php) -- present (id="wsa-root") on
 * every page PART 2 #1 lists, for student and for CGS, and absent
 * everywhere else: login, the shared dashboard, another module's page, and
 * for a role the assistant does not support (an approver).
 */
class WorkstationAssistantWidgetTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function room(string $gender = 'male', string $code = 'N2-02-01', string $block = 'N2'): WorkstationLocation
    {
        return WorkstationLocation::firstOrCreate(
            ['room_code' => $code],
            ['block' => $block, 'gender' => $gender, 'name' => $code]
        );
    }

    protected function seat(WorkstationLocation $room, string $code = '1'): Workstation
    {
        return Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => $code, 'position' => 1]);
    }

    public function test_the_widget_renders_on_every_student_facing_workstation_page(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $room = $this->room();
        $seat = $this->seat($room);

        $this->actingAs($student)->get(route('workstation.select'))->assertOk()->assertSee('id="wsa-root"', false);
        $this->actingAs($student)->get(route('workstation.rooms', 'N2'))->assertOk()->assertSee('id="wsa-root"', false);
        $this->actingAs($student)->get(route('workstation.seats', $room))->assertOk()->assertSee('id="wsa-root"', false);

        $seat->update(['status' => Workstation::STATUS_PENDING]);
        $selection = \App\Modules\Chloe\Models\WorkstationPendingSelection::create([
            'workstation_id' => $seat->id, 'student_id' => $student->id, 'token' => str_repeat('a', 64),
            'status' => 'pending', 'expires_at' => now()->addMinutes(5),
        ]);
        $this->actingAs($student)->get(route('workstation.confirm', $selection->token))
            ->assertOk()->assertSee('id="wsa-root"', false);

        $this->actingAs($student)->get(route('workstation.terms'))->assertOk()->assertSee('id="wsa-root"', false);
    }

    public function test_the_widget_renders_on_every_cgs_facing_workstation_page(): void
    {
        $cgs = $this->cgs();
        $room = $this->room();
        $seat = $this->seat($room);

        $this->actingAs($cgs)->get(route('workstation.cgs.operations'))->assertOk()->assertSee('id="wsa-root"', false);
        $this->actingAs($cgs)->get(route('workstation.cgs.block', 'N2'))->assertOk()->assertSee('id="wsa-root"', false);
        $this->actingAs($cgs)->get(route('workstation.cgs.room', $room))->assertOk()->assertSee('id="wsa-root"', false);
        $this->actingAs($cgs)->get(route('workstation.cgs.seat', $seat))->assertOk()->assertSee('id="wsa-root"', false);
    }

    public function test_the_widget_is_absent_from_the_login_page(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('id="wsa-root"', false);
    }

    public function test_the_widget_is_absent_from_the_shared_dashboard(): void
    {
        $student = $this->eligibleStudent();

        $this->actingAs($student)->get(route('dashboard'))->assertOk()->assertDontSee('id="wsa-root"', false);
    }

    public function test_the_widget_is_absent_from_another_modules_page(): void
    {
        $student = $this->eligibleStudent();

        $this->actingAs($student)->get(route('travel.create'))->assertOk()->assertDontSee('id="wsa-root"', false);
    }

    /** An approver has no Workstation pages to visit at all (role middleware blocks them), so this checks the one shared page every role can reach. */
    public function test_the_widget_is_absent_for_an_approver_role(): void
    {
        $supervisor = $this->supervisor();

        $this->actingAs($supervisor)->get(route('dashboard'))->assertOk()->assertDontSee('id="wsa-root"', false);
    }

    public function test_the_widget_carries_its_data_attributes_and_no_inline_event_handlers(): void
    {
        $student = $this->eligibleStudent();

        $html = $this->actingAs($student)->get(route('workstation.select'))->getContent();

        $this->assertStringContainsString('data-char-limit="300"', $html);
        $this->assertStringContainsString('data-suggestions-url="', $html);
        $this->assertStringContainsString('data-suggestion-url="', $html);
        $this->assertStringContainsString('data-ask-url="', $html);
        $this->assertStringContainsString('data-csrf="', $html);

        $widgetStart = strpos($html, 'id="wsa-root"');
        $widgetEnd = strpos($html, '</script>', $widgetStart);
        $this->assertNotFalse($widgetStart);
        $this->assertNotFalse($widgetEnd);
        $widgetHtml = substr($html, $widgetStart, $widgetEnd - $widgetStart);

        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $widgetHtml);
    }

    public function test_the_widgets_inline_script_carries_a_csp_nonce(): void
    {
        $student = $this->eligibleStudent();

        $html = $this->actingAs($student)->get(route('workstation.select'))->getContent();

        $scriptNeedlePos = strpos($html, "getElementById('wsa-root')");
        $this->assertNotFalse($scriptNeedlePos);

        $scriptTagStart = strrpos(substr($html, 0, $scriptNeedlePos), '<script');
        $this->assertNotFalse($scriptTagStart);

        $scriptTagEnd = strpos($html, '>', $scriptTagStart);
        $scriptTag = substr($html, $scriptTagStart, $scriptTagEnd - $scriptTagStart);

        $this->assertMatchesRegularExpression('/\bnonce="[^"]+"/', $scriptTag);
    }

    public function test_the_hint_text_is_role_specific(): void
    {
        $student = $this->eligibleStudent();
        $cgs = $this->cgs();

        $this->actingAs($student)->get(route('workstation.select'))
            ->assertOk()->assertSee("Guidance only. I can&#039;t make changes.", false);

        $this->actingAs($cgs)->get(route('workstation.cgs.operations'))
            ->assertOk()->assertSee("Names aren&#039;t supported.", false);
    }

    /** PART E.3: the AI-mistakes disclaimer appears for BOTH roles, in addition to the role-specific note. */
    public function test_the_disclaimer_appears_for_both_roles(): void
    {
        $student = $this->eligibleStudent();
        $cgs = $this->cgs();

        $this->actingAs($student)->get(route('workstation.select'))
            ->assertOk()->assertSee('AI can make mistakes. Please check important information with CGS.');

        $this->actingAs($cgs)->get(route('workstation.cgs.operations'))
            ->assertOk()->assertSee('AI can make mistakes. Please check important information with CGS.');
    }

    /** PART E.2: the old "Suggested questions" link is gone -- chips live only in the first message and after "New chat" now. */
    public function test_the_suggested_questions_link_no_longer_exists(): void
    {
        $student = $this->eligibleStudent();

        $html = $this->actingAs($student)->get(route('workstation.select'))->getContent();

        $this->assertStringNotContainsString('wsa-suggested-link', $html);
        $this->assertStringNotContainsString('Suggested questions<', $html);
    }

    /**
     * Round 3 PART D: an original inline SVG robot icon (never a stock
     * image) -- the old "hop every 3 seconds" animation is gone
     * entirely, replaced by a calmer idle breathe/glow/blink, all
     * disabled under prefers-reduced-motion. No inline style="" is used
     * for any visual width or colour anywhere in the widget.
     */
    public function test_the_icon_is_an_original_svg_with_calm_idle_animations_disabled_under_reduced_motion(): void
    {
        $student = $this->eligibleStudent();

        $html = $this->actingAs($student)->get(route('workstation.select'))->getContent();

        $this->assertStringContainsString('wsa-robot-template', $html);
        $this->assertStringContainsString('wsa-robot-antenna-ball', $html);
        $this->assertStringContainsString('wsa-robot-ear', $html);
        $this->assertStringNotContainsString('@keyframes wsa-hop', $html, 'The old hop animation must be fully removed.');
        $this->assertStringContainsString('@keyframes wsa-breathe', $html);
        $this->assertStringContainsString('@keyframes wsa-glow', $html);
        $this->assertStringContainsString('@keyframes wsa-eye-blink', $html);
        $this->assertMatchesRegularExpression('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[^}]*animation:\s*none/s', $html);
    }

    /** PART E's own rule: no inline style="" attribute for any visual width or colour anywhere in the widget partial. */
    public function test_the_widget_never_uses_an_inline_style_attribute(): void
    {
        $student = $this->eligibleStudent();

        $html = $this->actingAs($student)->get(route('workstation.select'))->getContent();
        $widgetStart = strpos($html, 'id="wsa-root"');
        $widgetEnd = strpos($html, '</script>', $widgetStart);
        $widgetHtml = substr($html, $widgetStart, $widgetEnd - $widgetStart);

        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $widgetHtml);
    }

    /** PART D.2: the first-visit greeting markup exists, with a role-specific line and a close button, shown by JS rather than server-rendered visible. */
    public function test_the_first_visit_greeting_markup_exists_per_role(): void
    {
        $student = $this->eligibleStudent();
        $cgs = $this->cgs();

        $studentHtml = $this->actingAs($student)->get(route('workstation.select'))->getContent();
        $this->assertStringContainsString('id="wsa-greeting"', $studentHtml);
        $this->assertStringContainsString('data-greeting="Hi! Questions about seats or your locker key?"', $studentHtml);
        $this->assertStringContainsString('id="wsa-greeting-close"', $studentHtml);

        $cgsHtml = $this->actingAs($cgs)->get(route('workstation.cgs.operations'))->getContent();
        $this->assertStringContainsString('data-greeting="Hi! Ask me about seats, keys or the rules."', $cgsHtml);
    }

    /** PART E.4: the panel is about 400px wide. */
    public function test_the_panel_is_about_400px_wide(): void
    {
        $student = $this->eligibleStudent();

        $html = $this->actingAs($student)->get(route('workstation.select'))->getContent();

        $this->assertMatchesRegularExpression('/#wsa-root \.wsa-panel\s*\{[^}]*width:\s*400px/s', $html);
    }
}
