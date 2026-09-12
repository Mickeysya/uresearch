<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The profile screen.
 *
 * The interesting tests here are the negative ones. A profile page is the one
 * screen where a user posts data about *themselves*, which makes it the
 * natural place for a privilege-escalation attempt: if the contact form
 * mass-assigned whatever it was given, a student could post `role=admin` or
 * `supervisor_id=…` and rewrite their own standing in the portal.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function student(): User
    {
        return User::create([
            'name' => 'Ahmad Danial',
            'email' => 'student@test.my',
            'password' => 'password',
            'role' => Role::STUDENT,
            'matric_no' => '22001001',
            'programme' => 'MSc Full-Time',
            'department' => 'Computer & Information Sciences',
        ]);
    }

    public function test_the_page_renders_for_every_role(): void
    {
        foreach ([Role::STUDENT, Role::SUPERVISOR, Role::NON_EXEC_CGS, Role::ADMIN, Role::DEAN_PGR] as $role) {
            $user = User::create([
                'name' => 'Test '.$role,
                'email' => $role.'@test.my',
                'password' => 'password',
                'role' => $role,
            ]);

            $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertSee('Change Password');
        }
    }

    public function test_it_shows_the_users_own_record(): void
    {
        $this->actingAs($this->student())
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Ahmad Danial')
            ->assertSee('22001001')
            ->assertSee('MSc Full-Time');
    }

    /** A row with nothing in it is noise, not an empty state. */
    public function test_it_omits_fields_the_user_does_not_have(): void
    {
        $supervisor = User::create([
            'name' => 'Dr Aisyah', 'email' => 'sup@test.my',
            'password' => 'password', 'role' => Role::SUPERVISOR,
        ]);

        $this->actingAs($supervisor)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertDontSee('Matric No.');
    }

    public function test_signing_out_is_required_to_reach_it(): void
    {
        $this->get(route('profile.show'))->assertRedirect(route('login'));
    }

    /* ---- contact details -------------------------------------------- */

    public function test_a_user_can_update_their_own_contact_number(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->patch(route('profile.contact'), ['contact_no' => '012-345 6789'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('012-345 6789', $student->fresh()->contact_no);
    }

    public function test_clearing_the_contact_number_is_allowed(): void
    {
        $student = $this->student();
        $student->update(['contact_no' => '0123456789']);

        $this->actingAs($student)->patch(route('profile.contact'), ['contact_no' => '']);

        $this->assertNull($student->fresh()->contact_no);
    }

    public function test_a_contact_number_must_look_like_one(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->patch(route('profile.contact'), ['contact_no' => '<script>alert(1)</script>'])
            ->assertSessionHasErrors('contact_no');

        $this->assertNull($student->fresh()->contact_no);
    }

    /**
     * The escalation attempt. Everything except the contact number is an
     * administrative fact, and the form must ignore anything else posted --
     * particularly role and supervisor_id, which decide what this person can
     * do and whose approval queue their applications land in.
     */
    public function test_the_contact_form_cannot_rewrite_administrative_fields(): void
    {
        $realSupervisor = User::create([
            'name' => 'Dr Aisyah', 'email' => 'sup@test.my',
            'password' => 'password', 'role' => Role::SUPERVISOR,
        ]);

        $student = $this->student();

        $this->actingAs($student)->patch(route('profile.contact'), [
            'contact_no' => '0123456789',
            'role' => Role::ADMIN,
            'supervisor_id' => $realSupervisor->id,
            'matric_no' => '99999999',
            'programme' => 'PhD Full-Time',
            'email' => 'attacker@test.my',
            'name' => 'Someone Else',
        ]);

        $student->refresh();

        $this->assertSame('0123456789', $student->contact_no, 'The one editable field should still save.');
        $this->assertSame(Role::STUDENT, $student->role);
        $this->assertNull($student->supervisor_id);
        $this->assertSame('22001001', $student->matric_no);
        $this->assertSame('MSc Full-Time', $student->programme);
        $this->assertSame('student@test.my', $student->email);
        $this->assertSame('Ahmad Danial', $student->name);
    }

    /* ---- password ---------------------------------------------------- */

    public function test_changing_a_password_requires_the_current_one(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->put(route('profile.password'), [
                'current_password' => 'not-the-password',
                'password' => 'a-new-secret-123',
                'password_confirmation' => 'a-new-secret-123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $student->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->put(route('profile.password'), [
                'current_password' => 'password',
                'password' => 'a-new-secret-123',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($student)
            ->put(route('profile.password'), [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $student->fresh()->password));
    }

    public function test_a_valid_password_change_is_saved_and_audited(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->put(route('profile.password'), [
                'current_password' => 'password',
                'password' => 'a-new-secret-123',
                'password_confirmation' => 'a-new-secret-123',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('a-new-secret-123', $student->fresh()->password));

        // The audit trail must record it -- and must never record the password.
        $entry = Activity::where('log_name', 'auth')
            ->where('causer_id', $student->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('Changed password', $entry->description);
        $this->assertStringNotContainsString('a-new-secret-123', json_encode($entry->properties));
    }
}
