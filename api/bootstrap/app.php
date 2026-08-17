<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Where to send an unauthenticated *browser* to.
        //
        // Laravel's default is route('login'), which does not exist here: this
        // application is an API plus a single-page app, and the login screen is
        // a client-side route. Without this, any non-JSON request to a protected
        // endpoint crashes with a RouteNotFoundException while the Authenticate
        // middleware tries to build its redirect — a 500 where a 401 belongs.
        //
        // The failure is invisible to the SPA, which always sends
        // Accept: application/json and so takes the JSON branch. It only shows
        // up when a person, a crawler or a monitoring probe opens an API URL in
        // a browser, which is exactly the sort of request a deployment gets.
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
