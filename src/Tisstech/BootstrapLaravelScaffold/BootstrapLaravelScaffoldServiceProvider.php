<?php
namespace Tisstech\BootstrapLaravelScaffold;

use Illuminate\Support\ServiceProvider;

class BootstrapLaravelScaffoldServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->package('tisstech/bootstrap-laravel-scaffold');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app['scaffold'] = $this->app->share(function($app)
        {
            return new ScaffoldCommand($app);
        });

        $this->app['scaffold.file'] = $this->app->share(function($app)
        {
            return new ScaffoldFromFileCommand($app);
        });

        $this->app['scaffold.model'] = $this->app->share(function($app)
        {
            return new ScaffoldModelCommand($app);
        });

        $this->app['scaffold.update'] = $this->app->share(function($app)
        {
            return new ScaffoldUpdateCommand($app);
        });

        $this->commands('scaffold', 'scaffold.file', 'scaffold.model', 'scaffold.update');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

}
