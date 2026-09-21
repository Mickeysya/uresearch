<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Norhanis\Models\ClaimsDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Student Claims — `docs/scope/norhanis.md`.
 *
 * The money path. Both the total and the balance are derived from a repeating
 * expense section the page's own JavaScript builds, so every figure stored
 * here has to be recomputed server-side from the items -- a hand-rolled POST
 * reaches this controller just as easily as the form does.
 */
class ClaimsTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** A valid claim: one item totalling RM 900. */
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
}
