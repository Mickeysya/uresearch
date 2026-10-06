<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The student's dashboard panels, where the figure is not always a figure
 * somebody uploaded -- see StudentDashboard::STARTING_PERCENTAGE.
 */
class StudentDashboardTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /**
     * A first-week student has missed nothing, so the dial is full. Opening
     * at 0% put every new account in the Critical band on day one.
     */
    public function test_the_attendance_gauge_starts_full_before_anything_is_uploaded(): void
    {
        $this->actingAs($this->student())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-count-to="100"', false)
            ->assertSee('Nothing has been uploaded yet, so you start at 100%.');
    }
}
