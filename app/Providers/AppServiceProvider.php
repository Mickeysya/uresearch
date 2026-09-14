<?php

namespace App\Providers;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Csp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
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

        // `<script @cspNonce>` — the Content-Security-Policy allows inline
        // script only when it carries this request's nonce. An inline block
        // written without it simply will not run, and the console says so.
        // See Core\Http\Middleware\ContentSecurityPolicy.
        Blade::directive('cspNonce', fn () => "<?php echo 'nonce=\"'.\\App\\Modules\\Core\\Support\\Csp::nonce().'\"'; ?>");

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
                // Drives the badge on the Notification item, as in both
                // dashboard designs. One count query per page load.
                'unreadCount' => $user ? $user->unreadNotifications()->count() : 0,
            ]);
        });
    }
}
