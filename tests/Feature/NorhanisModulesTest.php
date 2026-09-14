<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Norhanis' Claims and Publication modules.
 *
 * Narrow on purpose: the two things a student's browser can lie about. Claims
 * computes money from a repeating section the page's own JavaScript builds,
 * and Publication reads that section's size *before* validate() runs to
 * decide whether the Authorship Contribution Form is mandatory. Both are
 * places where a hand-rolled POST reaches code that has not been checked yet.
 */
class NorhanisModulesTest extends TestCase
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
        ]);
    }

    /** A valid claim: four items summing to RM 900. */
    protected function claim(array $overrides = []): array
    {
        return array_replace([
            'purpose_of_claim' => 'Conference travel reimbursement',
            'bank_account_no' => '1234567890',
            'items' => [[
                'item_date' => '2026-09-01',
                'travel_from' => 'Ipoh',
                'travel_to' => 'Kuala Lumpur',
                'flight_train_amount' => 500,
                'meal_allowance' => 100,
                'lodging_amount' => 300,
                'misc_amount' => 0,
            ]],
        ], $overrides);
    }

    /* -----------------------------------------------------------------
     | Claims — the money path
     |------------------------------------------------------------------*/

    public function test_the_total_and_balance_are_summed_server_side(): void
    {
        $this->actingAs($this->student())
            ->post(route('claims.store'), $this->claim([
                // Both are derived columns. A student editing the DOM to
                // inflate them must have no effect on what is stored.
                'total_claim_amount' => 999999,
                'claim_balance' => 999999,
            ]))
            ->assertRedirect();

        $detail = ClaimsDetail::sole();

        $this->assertEquals(900, $detail->total_claim_amount);
        $this->assertEquals(900, $detail->claim_balance);
    }

    public function test_a_cash_advance_larger_than_the_claim_is_refused(): void
    {
        $this->actingAs($this->student())
            ->post(route('claims.store'), $this->claim(['less_cash_advance' => 1000]))
            ->assertSessionHasErrors('less_cash_advance');

        // Nothing is stored: a negative claim_balance must never reach an approver.
        $this->assertSame(0, ClaimsDetail::count());
    }

    public function test_a_cash_advance_equal_to_the_claim_is_allowed(): void
    {
        $this->actingAs($this->student())
            ->post(route('claims.store'), $this->claim(['less_cash_advance' => 900]))
            ->assertSessionHasNoErrors();

        $this->assertEquals(0, ClaimsDetail::sole()->claim_balance);
    }

    public function test_an_unbounded_item_list_is_refused(): void
    {
        $items = array_fill(0, 101, [
            'item_date' => '2026-09-01', 'flight_train_amount' => 1,
            'meal_allowance' => 0, 'lodging_amount' => 0, 'misc_amount' => 0,
        ]);

        $this->actingAs($this->student())
            ->post(route('claims.store'), $this->claim(['items' => $items]))
            ->assertSessionHasErrors('items');
    }

    /* -----------------------------------------------------------------
     | Publication — input read before validate()
     |------------------------------------------------------------------*/

    public function test_a_scalar_author_list_is_a_validation_error_not_a_server_error(): void
    {
        // count() on a string is a TypeError in PHP 8, and this field is read
        // before validate() to decide whether the Authorship Contribution
        // Form is required -- so an unvalidated scalar used to 500.
        $response = $this->actingAs($this->student())
            ->post(route('publication.store'), ['authors' => 'not-an-array']);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('authors');
    }

    public function test_a_missing_author_list_is_a_validation_error(): void
    {
        $this->actingAs($this->student())
            ->post(route('publication.store'), [])
            ->assertSessionHasErrors('authors');
    }
}
