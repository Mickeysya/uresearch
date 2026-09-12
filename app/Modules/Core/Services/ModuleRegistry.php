<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use InvalidArgumentException;

/**
 * The list of every application type the portal knows about.
 *
 * Modules register themselves from their own ServiceProvider, so adding a
 * module is a one-line change inside your own folder -- Core is never edited
 * and never conflicts. The sidebar, the tracking page and the approval queues
 * all read from here, which is why a new module's links appear by themselves.
 */
class ModuleRegistry
{
    /** @var array<string, WorkflowModule> */
    protected array $modules = [];

    public function register(WorkflowModule $module): void
    {
        $key = $module->key();

        if (isset($this->modules[$key])) {
            throw new InvalidArgumentException(
                "Two modules both claim module_type '{$key}'. Pick a unique key "
                ."-- check with the team before renaming, it is stored in the database."
            );
        }

        $this->modules[$key] = $module;
    }

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    public function get(string $key): WorkflowModule
    {
        return $this->modules[$key]
            ?? throw new InvalidArgumentException("No module registered for module_type '{$key}'.");
    }

    /**
     * The display name for a module_type, for rows read back from the
     * database rather than handed to us by a registered module.
     *
     * `applications.module_type` is permanent and outlives any one module:
     * a teammate can rename a class, hand a module over, or comment out a
     * registration, and the rows they already created still have to render.
     * Falling back to a readable form of the key means an unregistered
     * module shows as "Ga extension" rather than throwing on a dashboard.
     *
     * Three call sites derived this separately before it lived here.
     */
    public function labelFor(string $key): string
    {
        return $this->has($key)
            ? $this->get($key)->label()
            : ucfirst(str_replace('_', ' ', $key));
    }

    /** @return array<string, WorkflowModule> */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Modules a student can start an application in.
     *
     * @return array<string, WorkflowModule>
     */
    public function submittable(): array
    {
        return array_filter($this->modules, fn (WorkflowModule $m) => $m->createRoute() !== null);
    }

    /**
     * Extra sidebar links declared by modules for this user, beyond the ones
     * derived from approval chains.
     *
     * @return array<int, array{label: string, route: string, params?: array}>
     */
    public function linksFor(\App\Modules\Core\Models\User $user): array
    {
        $links = [];

        foreach ($this->modules as $module) {
            if ($module instanceof ProvidesLinks) {
                $links = array_merge($links, $module->links($user));
            }
        }

        return $links;
    }

    /**
     * Every (module, stage) pair this role is responsible for.
     *
     * Drives the sidebar and gates the approval queues. Asks each module for
     * its stage superset (stages(null)), not the chain for any one
     * application: a stage that only appears on some applications -- CGS and
     * the Dean on international travel, say -- must still be reachable by the
     * role that owns it.
     *
     * @return array<int, array{module: WorkflowModule, stage: \App\Modules\Core\Support\Stage}>
     */
    public function queuesForRole(string $role): array
    {
        $queues = [];

        foreach ($this->modules as $module) {
            foreach ($module->stages(null) as $stage) {
                if ($stage->role === $role) {
                    $queues[] = ['module' => $module, 'stage' => $stage];
                }
            }
        }

        return $queues;
    }
}
