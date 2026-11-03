<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerContactHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Keeping examiner emails current: the Non-Exec email-only edit, the
 * contact history, the "not working" flag, freshness, and emails proposed
 * at nomination.
 */
class ExaminerContactTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function external(array $attributes = []): Examiner
    {
        return Examiner::create($attributes + [
            'name' => 'Prof Dr Zainal Ariffin',
            'email' => 'zainal@old.example.com',
            'department' => 'Mechanical Engineering',
            'type' => Examiner::TYPE_EXTERNAL,
            'institution' => 'Example University of Technology',
            'is_active' => true,
        ]);
    }

    public function test_non_exec_can_change_the_email_and_nothing_else(): void
    {
        $examiner = $this->external();
        $nonExec = $this->cgs();

        $this->actingAs($nonExec)->get(route('examiner-admin.show', $examiner))->assertOk()->assertSee('Update email')->assertDontSee('Edit examiner');
        $this->actingAs($nonExec)->get(route('examiner-admin.email.edit', $examiner))->assertOk();

        // Everything else posted alongside is ignored.
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), [
            'email' => 'Zainal@NEW.example.com',
            'reason' => 'New address from the university staff page',
            'name' => 'Someone Else',
            'department' => 'Petroleum Engineering',
            'is_active' => 0,
        ])->assertRedirect(route('examiner-admin.show', $examiner))->assertSessionHasNoErrors();

        $examiner->refresh();
        $this->assertSame('zainal@new.example.com', $examiner->email);
        $this->assertSame('Prof Dr Zainal Ariffin', $examiner->name);
        $this->assertSame('Mechanical Engineering', $examiner->department);
        $this->assertTrue($examiner->is_active);

        // The full form stays the Senior Executive's.
        $this->actingAs($nonExec)->put(route('examiner-admin.update', $examiner), ['name' => 'X'])->assertForbidden();

        // A reason is required, and an address another examiner has is refused.
        $this->external(['name' => 'Dr Other', 'email' => 'other@example.com']);
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), ['email' => 'x@example.com'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), ['email' => 'other@example.com', 'reason' => 'x'])
            ->assertSessionHasErrors('email');

        // Others cannot use it at all.
        $this->actingAs($this->supervisor())->put(route('examiner-admin.email.update', $examiner), ['email' => 'x@example.com', 'reason' => 'x'])->assertForbidden();
    }

    public function test_every_email_change_is_logged_and_shown_on_the_examiner_page(): void
    {
        $senior = $this->seniorExec();
        $nonExec = $this->cgs();

        // Added with an email on the full form.
        $this->actingAs($senior)->post(route('examiner-admin.store'), [
            'name' => 'Dr Farah Hanim', 'email' => 'farah@first.example.com', 'department' => 'Computing', 'type' => 'internal',
        ])->assertSessionHasNoErrors();
        $examiner = Examiner::where('name', 'Dr Farah Hanim')->firstOrFail();

        // Changed by Non-Exec, then by the Senior Exec on the full form.
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), [
            'email' => 'farah@second.example.com', 'reason' => 'Bounced; new address from her department',
        ]);
        $this->actingAs($senior)->put(route('examiner-admin.update', $examiner), [
            'name' => 'Dr Farah Hanim', 'email' => 'farah@third.example.com', 'department' => 'Computing', 'type' => 'internal',
            'email_change_reason' => 'Moved office',
        ])->assertSessionHasNoErrors();

        // Saving the form without touching the email logs nothing.
        $this->actingAs($senior)->put(route('examiner-admin.update', $examiner), [
            'name' => 'Dr Farah Hanim Binti Yusof', 'email' => 'farah@third.example.com', 'department' => 'Computing', 'type' => 'internal',
        ]);

        $lines = ExaminerContactHistory::where('examiner_id', $examiner->id)->orderBy('id')->get();
        $this->assertSame([
            [null, 'farah@first.example.com', 'manual', 'Senior Executive CGS'],
            ['farah@first.example.com', 'farah@second.example.com', 'email_form', 'Non-Executive CGS'],
            ['farah@second.example.com', 'farah@third.example.com', 'manual', 'Senior Executive CGS'],
        ], $lines->map(fn ($l) => [$l->old_email, $l->new_email, $l->source, \App\Modules\Core\Support\Role::label($l->changedBy->role)])->all());
        $this->assertSame('Bounced; new address from her department', $lines[1]->reason);

        $this->actingAs($nonExec)->get(route('examiner-admin.show', $examiner))
            ->assertOk()
            ->assertSee('Contact history')
            ->assertSee('farah@first.example.com')
            ->assertSee('Bounced; new address from her department')
            ->assertSee('M Syahmi Ifwat M Jafri');
    }
}
