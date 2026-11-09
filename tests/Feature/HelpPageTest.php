<?php

namespace Tests\Feature;

use App\Modules\Core\Http\Controllers\HelpController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Help and Support (/help): one page for every role, its content chosen by
 * audience; wording from config/help.php; general questions only; links that
 * never point at a missing route.
 */
class HelpPageTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('help.index'))->assertRedirect(route('login'));
    }

    public function test_every_role_resolves_to_exactly_one_audience(): void
    {
        $this->assertSame('student', HelpController::audienceFor($this->student()));
        $this->assertSame('admin', HelpController::audienceFor($this->admin()));
        $this->assertSame('cgs', HelpController::audienceFor($this->cgs()));
        $this->assertSame('cgs', HelpController::audienceFor($this->seniorDirector()));
        $this->assertSame('approver', HelpController::audienceFor($this->supervisor()));
        $this->assertSame('approver', HelpController::audienceFor($this->chair()));
        $this->assertSame('approver', HelpController::audienceFor($this->dean()));
        $this->assertSame('approver', HelpController::audienceFor($this->academicExec()));
    }

    public function test_a_student_sees_student_questions_and_the_cgs_contact(): void
    {
        $this->actingAs($this->student())
            ->get(route('help.index'))
            ->assertOk()
            ->assertSee('How can we help you today?')
            ->assertSee('What do the application statuses mean?')
            ->assertSee('reach the Centre for Graduate Studies directly')
            ->assertSee('Submit an enquiry')
            ->assertSee('Open the application guides')
            ->assertDontSee('Where are my queues?')
            ->assertDontSee('Can I approve several applications at once?')
            ->assertDontSee("How do I add a user or change someone's role?")
            ->assertDontSee('System support');
    }

    public function test_a_supervisor_sees_approver_questions_but_not_student_or_cgs_ones(): void
    {
        $this->actingAs($this->supervisor())
            ->get(route('help.index'))
            ->assertOk()
            ->assertSee('Can I approve several applications at once?')
            ->assertSee('Submit an enquiry')
            ->assertDontSee('How do I submit an application?')
            ->assertDontSee('Where are my queues?')
            ->assertDontSee('Open the application guides');
    }

    public function test_cgs_staff_see_the_cgs_desk_and_system_support_not_the_enquiry_portal(): void
    {
        $this->actingAs($this->cgs())
            ->get(route('help.index'))
            ->assertOk()
            ->assertSee('Where are my queues?')
            ->assertSee('System support')
            ->assertSee('contact system support')
            ->assertDontSee('Submit an enquiry')
            ->assertDontSee('Can I approve several applications at once?')
            ->assertDontSee('How do I submit an application?');
    }

    public function test_the_admin_sees_administration_questions_only(): void
    {
        $this->actingAs($this->admin())
            ->get(route('help.index'))
            ->assertOk()
            ->assertSee("How do I add a user or change someone's role?")
            ->assertSee('System support')
            ->assertDontSee('Where are my queues?')
            ->assertDontSee('How do I submit an application?')
            ->assertDontSee('Submit an enquiry');
    }

    public function test_an_unset_support_email_says_so_and_a_set_one_is_shown(): void
    {
        config(['help.support.email' => null, 'help.support.hours' => null]);

        $this->actingAs($this->cgs())
            ->get(route('help.index'))
            ->assertSee('Support contact not set yet.')
            ->assertDontSee('>Hours<', false);

        config(['help.support.email' => 'uresearch-support@utp.edu.my', 'help.support.hours' => 'Weekdays, 9am to 5pm']);

        $this->get(route('help.index'))
            ->assertDontSee('Support contact not set yet.')
            ->assertSee('mailto:uresearch-support@utp.edu.my', false)
            ->assertSee('Weekdays, 9am to 5pm');
    }

    public function test_contact_details_come_from_config(): void
    {
        config([
            'help.contact.phone' => '1-800-TEST-123',
            'help.contact.email' => 'help-test@utp.edu.my',
        ]);

        $this->actingAs($this->student())
            ->get(route('help.index'))
            ->assertSee('1-800-TEST-123')
            ->assertSee('mailto:help-test@utp.edu.my', false);
    }

    public function test_the_question_count_and_anchors_match_what_the_viewer_gets(): void
    {
        $expected = collect(config('help.sections'))
            ->filter(fn ($s) => array_intersect($s['audiences'], ['all', 'student']) !== [])
            ->sum(fn ($s) => count($s['questions']));

        $this->actingAs($this->student())
            ->get(route('help.index'))
            ->assertSee("Search {$expected} common questions", false)
            ->assertSee('id="apps-4"', false)
            ->assertDontSee('id="approve-1"', false);
    }

    public function test_no_module_specific_section_exists(): void
    {
        $ids = array_column(config('help.sections'), 'id');

        $this->assertNotContains('attend', $ids);
        $this->assertNotContains('attendance', $ids);

        $this->actingAs($this->student())
            ->get(route('help.index'))
            ->assertDontSee('UTrace');
    }

    public function test_every_faq_link_points_to_an_existing_route_or_is_omitted(): void
    {
        $viewers = [
            'student' => $this->student(),
            'approver' => $this->supervisor(),
            'cgs' => $this->cgs(),
            'admin' => $this->admin(),
        ];

        foreach ($viewers as $audience => $user) {
            $html = $this->actingAs($user)->get(route('help.index'))->assertOk()->getContent();

            foreach (config('help.sections') as $section) {
                if (array_intersect($section['audiences'], ['all', $audience]) === []) {
                    continue;
                }

                foreach ($section['questions'] as $q) {
                    if (! isset($q['link'])) {
                        continue;
                    }

                    if (Route::has($q['link']['route'])) {
                        $this->assertStringContainsString('href="'.e(route($q['link']['route'])).'"', $html, "{$audience}: \"{$q['q']}\" lost its link.");
                    } else {
                        $this->assertStringNotContainsString(e($q['link']['label']), $html, "{$audience}: \"{$q['q']}\" links to a missing route.");
                    }
                }
            }
        }
    }

    public function test_a_link_to_a_route_that_does_not_exist_is_dropped_not_fatal(): void
    {
        $sections = config('help.sections');
        $sections[0]['questions'][0]['link'] = ['route' => 'no.such.route', 'label' => 'A link to nowhere'];
        config(['help.sections' => $sections]);

        $this->actingAs($this->student())
            ->get(route('help.index'))
            ->assertOk()
            ->assertDontSee('A link to nowhere');
    }

    public function test_initials_skip_honorifics_and_connecting_words(): void
    {
        $this->assertSame('MH', HelpController::initials('Professor Ts Dr Mohd Fadzil B Hassan'));
        $this->assertSame('BS', HelpController::initials('Assoc. Prof. Dr Balbir Singh A/L Mahinder Singh'));
        $this->assertSame('HY', HelpController::initials('Mr Ho Guan Yih'));
        $this->assertSame('ZD', HelpController::initials('Zulkifly B M Daud'));
        $this->assertSame('MJ', HelpController::initials('Mohammad Syahmi Ifwat bin Mohd Jafri'));
    }

    public function test_the_page_uses_no_inline_event_handlers(): void
    {
        foreach ([$this->student(), $this->admin()] as $user) {
            $html = $this->actingAs($user)->get(route('help.index'))->getContent();

            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        }
    }
}
