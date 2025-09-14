<?php

namespace Schildhain\ClassDiscovery;

use Illuminate\Support\ServiceProvider as Illuminate;

class ServiceProvider extends Illuminate
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/class-discovery.php', 'class-discovery'
        );

        $this->app->bind('class-discovery', function ($app) {
            return $app->make(ClassDiscovery::class);
        });

        $this->registerNamespaces();
    }

    public function registerNamespaces() {
        $namespaces = config('class-discovery.namespaces', [
            $this->app->path() => $this->app->getNamespace(),
        ]);

        if(is_array($namespaces)) {
            foreach($namespaces as $path => $namespace) {
                ClassDiscovery::addNamespace($path, rtrim($namespace, '\\'));
            }
        }
    }

    public function boot()
    {

        $this->publishes([
            __DIR__.'/../config/class-discovery.php' => config_path('class-discovery.php'),
        ]);
    }
}
