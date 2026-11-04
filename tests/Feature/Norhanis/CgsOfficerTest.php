<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Notifications\ApplicationAwaitingAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Each team member's modules have their own Non-Executive CGS officer, and
 * Travel, Claims and Publication are Norshahira's: only she is emailed at the
 * CGS stage. Every CGS officer still sees and acts on all three, so each
 * one's dashboard, queues and lists cover every module. With no such
 * account, every Non-Executive CGS is emailed (the rest of the suite).
 */
class CgsOfficerTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_the_modules_own_officer_is_emailed_and_every_cgs_officer_can_work_it(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computing']);
        $officer = $this->user(Role::NON_EXEC_CGS, ['name' => 'Norshahira', 'email' => 'norshahira.cgs@utp.edu.my']);
        $other = $this->user(Role::NON_EXEC_CGS, ['name' => 'Waheda', 'email' => 'waheda.cgs@utp.edu.my']);
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Computing']);

        $application = $this->claim($student);
        $this->actingAs($supervisor)->post(route('claims.decide', $application), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('claims.decide', $application), ['decision' => 'approve']);
        $this->assertSame('cgs_review', $application->fresh()->current_stage);

        // Emailed to her alone.
        Notification::assertSentTo($officer, ApplicationAwaitingAction::class);
        Notification::assertNotSentTo($other, ApplicationAwaitingAction::class);

        // Yet any CGS officer has the lists and the queues, and can decide.
        $labels = fn (User $user) => collect(app(ModuleRegistry::class)->linksFor($user))->pluck('label')->all();

        foreach ([$officer, $other] as $cgs) {
            $this->assertContains('Claims Applications', $labels($cgs));

            foreach ([route('claims.queue'), route('travel.queue'), route('publication.queue'), route('norhanis.overview'),
                      route('norhanis.report'), route('norhanis.pack.zip', $application)] as $url) {
                $this->actingAs($cgs)->get($url)->assertOk();
            }
        }

        $this->actingAs($other)->get(route('claims.queue'))->assertSee('Ahmad Danial');
        $this->actingAs($other)->post(route('claims.decide', $application), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('manager', $application->fresh()->current_stage);
    }

    protected function claim(User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'claims_student',
            'status' => Application::STATUS_DRAFT,
        ]);

        ClaimsDetail::create([
            'application_id' => $application->id,
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
            'total_claims' => 500,
        ]);

        return app(WorkflowEngine::class)->submit($application);
    }
}
