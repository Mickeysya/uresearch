<?php

namespace App\Providers;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Finds every folder under app/Modules and wires it up by convention.
 *
 * This is what makes the per-person folders work: nobody has to edit a shared
 * file to add a module, so nobody conflicts with anybody. Drop a folder in and
 * these paths are picked up automatically if they exist:
 *
 *   app/Modules/<Name>/routes.php                -> web routes
 *   app/Modules/<Name>/Database/Migrations/      -> php artisan migrate
 *   app/Modules/<Name>/Resources/views/          -> view('<name>::some.view')
 *   app/Modules/<Name>/ModuleProvider.php        -> registers your workflow
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Both are singletons: the registry is built once per request and the
        // engine must share it, otherwise modules registered by one would be
        // invisible to the other.
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(WorkflowEngine::class);

        foreach ($this->moduleProviders() as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        foreach ($this->modulePaths() as $name => $path) {
            $namespace = strtolower($name);

            if (File::isDirectory($views = "{$path}/Resources/views")) {
                $this->loadViewsFrom($views, $namespace);
            }

            if (File::isDirectory($migrations = "{$path}/Database/Migrations")) {
                $this->loadMigrationsFrom($migrations);
            }

            if (File::exists($routes = "{$path}/routes.php")) {
                Route::middleware('web')->group($routes);
            }
        }
    }

    /**
     * Module directories, Core first so its views and migrations always
     * resolve before anything that depends on them.
     *
     * @return array<string, string>
     */
    protected function modulePaths(): array
    {
        $base = app_path('Modules');

        if (! File::isDirectory($base)) {
            return [];
        }

        $paths = [];

        foreach (File::directories($base) as $dir) {
            $paths[basename($dir)] = $dir;
        }

        ksort($paths);

        if (isset($paths['Core'])) {
            $paths = ['Core' => $paths['Core']] + $paths;
        }

        return $paths;
    }

    /** @return array<int, class-string> */
    protected function moduleProviders(): array
    {
        $providers = [];

        foreach ($this->modulePaths() as $name => $path) {
            $class = "App\\Modules\\{$name}\\ModuleProvider";

            if (File::exists("{$path}/ModuleProvider.php") && class_exists($class)) {
                $providers[] = $class;
            }
        }

        return $providers;
    }
}
