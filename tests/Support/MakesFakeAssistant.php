<?php

namespace Tests\Support;

use App\Modules\Chloe\Assistant\FakeModel;

/**
 * Resets FakeModel's static queue before every test that uses it --
 * static state would otherwise leak from one test into the next, since
 * the container only resolves AssistantModel once per request/test but
 * FakeModel's queue is a class-level static, not an instance property.
 */
trait MakesFakeAssistant
{
    protected function setUpFakeAssistant(): void
    {
        FakeModel::reset();
    }
}
