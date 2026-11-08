<?php

namespace App\Modules\Jason;

use App\Modules\Core\Contracts\SuppliesAppointmentDispatch;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Jason\Listeners\RecordExaminerPackDelivery;
use App\Modules\Jason\Support\AppointmentDispatch;
use App\Modules\Jason\Workflows\AppointmentLetterWorkflow;
use App\Modules\Jason\Workflows\HardboundAppealWorkflow;
use App\Modules\Jason\Workflows\HardboundSubmissionWorkflow;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Jason's modules.
 *
 * Register each workflow you own here. This file is yours alone -- nobody
 * else edits it, so you will never hit a merge conflict adding a module.
 *
 * Copy app/Modules/Norhanis as a working example: it has a workflow, a model,
 * a migration, a controller, routes and views, all wired to the shared engine.
 */
class ModuleProvider extends ServiceProvider
{
    /**
     * Offers this module's dispatch dates to whoever owns the examiner
     * panel, so their page can show whether each pack actually went out.
     *
     * Bound outright now that Core has the interface. It was guarded with
     * interface_exists() while the file was only proposed, because binding
     * a class to a missing interface is a fatal error on every request in
     * the application, not only this module's pages.
     */
    public function register(): void
    {
        $this->app->bind(SuppliesAppointmentDispatch::class, AppointmentDispatch::class);
    }

    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new AppointmentLetterWorkflow());
        $registry->register(new HardboundSubmissionWorkflow());
        $registry->register(new HardboundAppealWorkflow());

        // Marks an examiner's appointment pack as delivered once the mail
        // transport accepts it. Scoped to this module: the listener ignores
        // every message that is not carrying an examiner header.
        Event::listen(MessageSent::class, RecordExaminerPackDelivery::class);
    }
}
