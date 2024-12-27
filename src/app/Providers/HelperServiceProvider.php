<?php

namespace PatrykSawicki\Helper\app\Providers;

use Illuminate\Support\ServiceProvider;
use PatrykSawicki\Helper\app\Console\Commands\FilesRebuildCommand;

class HelperServiceProvider extends ServiceProvider
{
    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot()
    {
        $path = realpath($raw = __DIR__ . '/../../');

        include $path . '/routes/web.php';

        if (file_exists($this->app->databasePath() . '/config/filesSettings.php') == false) {
            $this->publishes([$path . '/config/filesSettings.php' => config_path('filesSettings.php')], 'config');
        }

        $this->publishes([$path . '/database/migrations' => $this->app->databasePath() . '/migrations'], 'migrations');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $path = realpath($raw = __DIR__ . '/../../');
        $this->mergeConfigFrom($path . '/config/filesSettings.php', 'filesSettings');

        /*Add FilesRebuildCommand*/
        $this->commands(FilesRebuildCommand::class);

        /*Load Migrations*/
        $this->loadMigrationsFrom($path . '/database/migrations');
    }
}
