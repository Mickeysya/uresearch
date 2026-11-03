<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    /** Round 3 PART A: Pipeline's retry backoff uses Sleep::sleep(), not a raw sleep() call, so every test gets instant (but still assertable) waits instead of really waiting 2-7+ seconds per forced-unavailable scenario. */
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }
}
