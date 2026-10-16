<?php

use App\Enums\AccountType;
use App\Http\Middleware\BlockIpAddress;
use App\Http\Middleware\EnsureSectionAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KeepPrivatePagesOut;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(prepend: [
            BlockIpAddress::class,
        ]);

        // After the cookie and session middleware, before anything renders text.
        $middleware->web(append: [
            SetLocale::class,
            SecurityHeaders::class,
            KeepPrivatePagesOut::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The courier companies send their progress messages without a page token: they are checked by their own password instead.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            'section' => EnsureSectionAccess::class,
            'two-factor.required' => \App\Http\Middleware\RequireTwoFactorForStaff::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every unknown page gets the same silent redirect as a denied section (spec VIS-4), so a
        // 404 or 403 can never reveal that a hidden section exists. The admin can already see every
        // section, so they get honest 404s (a mistyped customer id should not look like a bug).
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            $isAdmin = $request->user()?->account_type === AccountType::Admin;

            if ($e instanceof NotFoundHttpException && ! $isAdmin && ! $request->expectsJson()) {
                return redirect('/');
            }

            // A screen the visitor may not use answers like a missing one: a silent redirect, never a 403 page.
            if ($e->getPrevious() instanceof AuthorizationException && ! $request->expectsJson()) {
                return redirect('/');
            }

            return $response;
        });
    })->create();
