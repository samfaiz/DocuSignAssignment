<?php

namespace App\Providers;

use App\Services\MailConfigurator;
use App\Services\SignServiceClient;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The client takes scalars, so the container cannot autowire it.
        // A singleton also means one HMAC secret read per request rather than
        // one per injection point.
        $this->app->singleton(
            SignServiceClient::class,
            static fn () => SignServiceClient::fromConfig()
        );
    }

    public function boot(): void
    {
        // Behind a load balancer or in a container, Laravel otherwise builds
        // links from the internal request and signer emails go out pointing at
        // http://localhost.
        if ($url = config('app.url')) {
            URL::forceRootUrl($url);
        }

        // Layers admin-managed SMTP settings over the .env defaults. Runs here
        // rather than in a middleware so it also applies inside the queue
        // worker, which is where mail is actually sent.
        MailConfigurator::apply();
    }
}
