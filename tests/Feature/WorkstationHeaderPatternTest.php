<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The standard Workstation header -- a plain-text "← Back" above the title and the blue "Home"
 * button on the right (x-core::page-header) -- on the pages anyone can open: the Term of Use
 * (public, linked from emails) and the email-link result page; plus the audit of the module's
 * other pages. The Download PDF control must stay exactly as it was.
 */
class WorkstationHeaderPatternTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesUsers;
    use RefreshDatabase;

    /** Captured from the page before the header was added; must never change. */
    protected function downloadPdfMarkup(): string
    {
        return '<a href="'.route('workstation.terms.pdf').'"><button type="button">Download PDF</button></a>';
    }

    /** The href of the blue Home button in a page's header. */
    protected function homeHref(string $html): ?string
    {
        preg_match('/<div class="page-header-actions">.*?<a href="([^"]+)"><button type="button">Home<\/button><\/a>/s', $html, $m);

        return $m[1] ?? null;
    }

    protected function backHref(string $html): ?string
    {
        preg_match('/<a href="([^"]+)" class="page-header-back"/', $html, $m);

        return $m[1] ?? null;
    }

    protected function room(): WorkstationLocation
    {
        return WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-HD-01', 'gender' => 'male', 'name' => 'N2-HD-01']);
    }

    protected function studentWithGender(): \App\Modules\Core\Models\User
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        return $student;
    }

    // ---- Term of Use ------------------------------------------------------

    public function test_a_guest_sees_home_but_no_back(): void
    {
        $html = $this->get(route('workstation.terms'))->assertOk()->getContent();

        $this->assertNull($this->backHref($html));
        $this->assertStringNotContainsString('page-header-back', $html);
        $this->assertSame(route('workstation.select'), $this->homeHref($html));
        $this->assertStringNotContainsString('data-browser-back', $html);
    }

    public function test_a_student_sees_back_and_home_and_the_home_target_matches_the_other_student_pages(): void
    {
        $student = $this->studentWithGender();
        $room = $this->room();

        $terms = $this->actingAs($student)->get(route('workstation.terms'))->assertOk()->getContent();
        $rooms = $this->actingAs($student)->get(route('workstation.rooms', 'N2'))->assertOk()->getContent();

        $this->assertSame(route('workstation.select'), $this->backHref($terms), 'Back fallback for a student');
        $this->assertSame($this->homeHref($rooms), $this->homeHref($terms), "Home must be the same target the student's other pages use");
        $this->assertStringContainsString('data-browser-back', $terms);
    }

    public function test_cgs_sees_back_and_home_and_the_home_target_matches_the_other_cgs_pages(): void
    {
        $cgs = $this->cgs();
        $this->room();

        $terms = $this->actingAs($cgs)->get(route('workstation.terms'))->assertOk()->getContent();
        $block = $this->actingAs($cgs)->get(route('workstation.cgs.block', 'N2'))->assertOk()->getContent();

        $this->assertSame(route('workstation.cgs.operations'), $this->backHref($terms), 'Back fallback for CGS');
        $this->assertSame($this->homeHref($block), $this->homeHref($terms), "Home must be the same target the CGS pages use");
    }

    public function test_another_signed_in_role_goes_to_the_dashboard(): void
    {
        $html = $this->actingAs($this->supervisor())->get(route('workstation.terms'))->assertOk()->getContent();

        $this->assertSame(route('dashboard'), $this->backHref($html));
        $this->assertSame(route('dashboard'), $this->homeHref($html));
    }

    public function test_the_download_pdf_control_is_unchanged_for_every_visitor(): void
    {
        $this->assertStringContainsString($this->downloadPdfMarkup(), $this->get(route('workstation.terms'))->getContent());
        $this->assertStringContainsString($this->downloadPdfMarkup(), $this->actingAs($this->studentWithGender())->get(route('workstation.terms'))->getContent());
        $this->assertStringContainsString($this->downloadPdfMarkup(), $this->actingAs($this->cgs())->get(route('workstation.terms'))->getContent());

        // ...and exactly once, and its route and file handling still work.
        $this->assertSame(1, substr_count($this->get(route('workstation.terms'))->getContent(), 'Download PDF'));
        $this->get(route('workstation.terms.pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_new_markup_has_no_inline_handlers_or_style_attributes_and_the_script_has_a_nonce(): void
    {
        $html = $this->actingAs($this->cgs())->get(route('workstation.terms'))->getContent();

        $header = substr($html, strpos($html, '<header class="page-header'), strpos($html, '</header>') - strpos($html, '<header class="page-header'));
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $header);
        $this->assertStringNotContainsString(' style=', $header);

        preg_match_all('/<script(?![^>]*\ssrc=)([^>]*)>/', $html, $scripts);
        foreach ($scripts[1] as $attrs) {
            $this->assertStringContainsString('nonce=', $attrs, 'every inline script must carry the CSP nonce');
        }
        $this->assertStringContainsString('document.referrer', $html);
        $this->assertStringContainsString('previous.origin === window.location.origin', $html);
    }

    public function test_the_back_link_is_a_real_link_so_it_works_without_javascript(): void
    {
        $html = $this->actingAs($this->studentWithGender())->get(route('workstation.terms'))->getContent();

        $this->assertMatchesRegularExpression('/<a href="[^"]+workstation" class="page-header-back"\s+data-browser-back\s*>&larr; Back<\/a>/', $html);
    }

    public function test_the_header_sits_above_the_term_of_use_and_replaces_the_cards_own_headings(): void
    {
        $html = $this->get(route('workstation.terms'))->getContent();

        $this->assertLessThan(strpos($html, 'Any loan requisition'), strpos($html, '<header class="page-header'));
        $this->assertSame(1, substr_count($html, '<h2>Postgraduate Workstation Term of Use</h2>'));
        $this->assertStringNotContainsString('<h3 style="margin-top: 0;">Term of Use</h3>', $html);
    }

    // ---- email-link result page --------------------------------------------

    public function test_the_email_link_result_page_has_home_only_no_back(): void
    {
        foreach ([$this->get(route('workstation.confirm', 'not-a-real-token')), $this->actingAs($this->studentWithGender())->get(route('workstation.confirm', 'not-a-real-token'))] as $response) {
            $html = $response->assertOk()->getContent();

            $this->assertSame(route('workstation.select'), $this->homeHref($html));
            $this->assertStringNotContainsString('page-header-back', $html);
            $this->assertStringContainsString('Go to Workstation', $html, 'the existing button stays');
        }
    }

    // ---- the audit of the module's pages ----------------------------------

    public function test_pages_inside_the_module_have_both_and_the_first_pages_of_each_flow_have_neither(): void
    {
        $student = $this->studentWithGender();
        $cgs = $this->cgs();
        $room = $this->room();
        $seat = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '1', 'position' => 1, 'status' => Workstation::STATUS_AVAILABLE]);

        $both = [
            'student rooms' => [$student, route('workstation.rooms', 'N2')],
            'student seats' => [$student, route('workstation.seats', $room)],
            'cgs block' => [$cgs, route('workstation.cgs.block', 'N2')],
            'cgs room' => [$cgs, route('workstation.cgs.room', $room)],
            'cgs seat details' => [$cgs, route('workstation.cgs.seat', $seat)],
            'cgs insights' => [$cgs, route('workstation.cgs.insights')],
            'cgs catalogue' => [$cgs, route('workstation.cgs.catalog')],
        ];
        foreach ($both as $page => [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertNotNull($this->backHref($html), "{$page} has no Back");
            $this->assertNotNull($this->homeHref($html), "{$page} has no Home");
        }

        // The CGS results page (a search or filter is active) keeps its Back link but has no Home button.
        $results = $this->actingAs($cgs)->get(route('workstation.cgs.operations', ['q' => 'x']))->assertOk()->getContent();
        $this->assertNotNull($this->backHref($results), 'cgs search results has no Back');
        $this->assertNull($this->homeHref($results), 'cgs search results must not have Home');

        // First pages of their flows: neither Back nor the page-header Home.
        foreach (['student home' => [$student, route('workstation.select')], 'cgs landing' => [$cgs, route('workstation.cgs.operations')]] as $page => [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertNull($this->backHref($html), "{$page} must not have Back");
            $this->assertStringNotContainsString('page-header-back', $html);
            $this->assertNull($this->homeHref($html), "{$page} must not have Home");
        }
    }
}
