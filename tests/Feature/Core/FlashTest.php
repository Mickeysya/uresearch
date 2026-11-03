<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Validation errors show on every signed-in page, from the layout.
 *
 * Before 2026-10-06 the layout flashed session messages but never the error
 * bag, so a failed rule on a page with no field to show it beside (a queue,
 * a CGS screen) bounced back to an unchanged page with no explanation.
 */
class FlashTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_validation_errors_render_on_a_page_with_no_field_for_them(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'remarks' => ['Remarks are required when rejecting.'],
        ]));

        $this->actingAs($this->supervisor())
            ->withSession(['errors' => $errors])
            ->get(route('travel.queue'))
            ->assertOk()
            ->assertSee('Remarks are required when rejecting.');
    }

    public function test_an_error_message_is_escaped(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'q' => ['<script>alert(1)</script>'],
        ]));

        $this->actingAs($this->supervisor())
            ->withSession(['errors' => $errors])
            ->get(route('travel.queue'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
