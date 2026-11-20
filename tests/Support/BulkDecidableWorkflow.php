<?php

namespace Tests\Support;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;

/**
 * A workflow that exists only in tests: supervisor, Chair, Non-Executive CGS,
 * and nothing in its decide() beyond the engine.
 *
 * Core's bulk-decide tests used Travel until Travel's controller started
 * checking which supervisor a row names and, correctly, took on
 * DecidesOneAtATime. Every real module may do the same one day, so the tests
 * of Core's own bulk route and engine run against this instead and no longer
 * depend on any teammate's module staying bulk-decidable.
 */
class BulkDecidableWorkflow implements WorkflowModule
{
    public const KEY = 'test_bulk';

    public static function register(): void
    {
        $registry = app(ModuleRegistry::class);

        if (! $registry->has(self::KEY)) {
            $registry->register(new self);
        }
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Bulk Test';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('supervisor', 'Supervisor', Role::SUPERVISOR, 'endorsed'),
            new Stage('chair', 'Chair of Department', Role::CHAIR, 'endorsed'),
            new Stage('cgs_review', 'Non-Executive CGS', Role::NON_EXEC_CGS, 'approved'),
        ];
    }

    public function summary(Application $application): string
    {
        return 'Bulk test application';
    }

    public function createRoute(): ?string
    {
        return null;
    }

    public function queueRoute(): string
    {
        return 'dashboard';
    }
}
