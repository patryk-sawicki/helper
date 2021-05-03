<?php

namespace PatrykSawicki\Helper\Providers;

use Illuminate\Support\ServiceProvider;
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

        if(file_exists($this->app->databasePath() . '/config/filesSettings.php') == false){
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
        //
    }
}
