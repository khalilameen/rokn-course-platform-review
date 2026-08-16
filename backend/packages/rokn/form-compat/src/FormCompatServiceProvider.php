<?php

namespace Rokn\FormCompat;

use Collective\Html\FormBuilder;
use Illuminate\Support\ServiceProvider;

final class FormCompatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('form', static fn ($app): FormBuilder => new FormBuilder(
            $app['url'],
            $app['request']
        ));
    }
}
