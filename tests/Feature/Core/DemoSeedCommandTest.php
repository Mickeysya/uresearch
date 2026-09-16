<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * The scenario name is validated in exactly one place -- DemoDataSeeder::run().
 * SeedDemoData used to repeat the same check, which is how the two error
 * messages drift apart. This asserts the surviving copy still aborts rather
 * than printing a complaint and exiting 0, which is what a script would read
 * as success.
 *
 * No RefreshDatabase: both paths return before the seeder touches a table.
 */
class DemoSeedCommandTest extends TestCase
{
    public function test_an_unknown_scenario_fails_instead_of_reporting_success(): void
    {
        $this->artisan('demo:seed', ['scenario' => 'no-such-scenario'])
            ->expectsOutputToContain("No scenario 'no-such-scenario'")
            ->assertFailed();
    }

    public function test_list_names_every_scenario_and_succeeds(): void
    {
        $this->artisan('demo:seed', ['--list' => true])->assertSuccessful();
    }
}
