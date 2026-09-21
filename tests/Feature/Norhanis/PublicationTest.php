<?php

namespace Tests\Feature\Norhanis;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Publication funding — `docs/scope/norhanis.md`.
 *
 * One narrow thing, for now: the author list is read *before* validate() runs,
 * because its size decides whether the Authorship Contribution Form is
 * mandatory. Anything read before validation is unchecked input, and this is
 * the only place in the portal that does it.
 */
class PublicationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_a_scalar_author_list_is_a_validation_error_not_a_server_error(): void
    {
        // count() on a string is a TypeError in PHP 8, so an unvalidated
        // scalar here used to return a blank 500 instead of a form error.
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
