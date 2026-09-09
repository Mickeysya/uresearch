<?php

namespace App\Providers;

use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catches a mass-assignment typo at development time instead of
        // silently dropping the attribute.
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());

        // The sidebar is built from the module registry rather than a
        // hardcoded if/elseif chain, so every module's links appear for the
        // right roles without Core knowing the module exists.
        View::composer('core::partials.sidebar', function ($view) {
            $user = auth()->user();

            $view->with([
                'submittable' => $user?->isStudent()
                    ? app(ModuleRegistry::class)->submittable()
                    : [],
                'queues' => $user && ! $user->isStudent()
                    ? app(ModuleRegistry::class)->queuesForRole($user->role)
                    : [],
                'extraLinks' => $user
                    ? app(ModuleRegistry::class)->linksFor($user)
                    : [],
            ]);
        });
    }
}
