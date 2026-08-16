<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EnvelopeController;
use App\Http\Controllers\Api\MailSettingController;
use App\Http\Controllers\Api\SignerController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin surface — authenticated with Sanctum bearer tokens
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);

    Route::get('/settings/mail', [MailSettingController::class, 'show']);
    Route::put('/settings/mail', [MailSettingController::class, 'update']);
    Route::post('/settings/mail/test', [MailSettingController::class, 'test'])
        ->middleware('throttle:6,1');

    Route::get('/envelopes', [EnvelopeController::class, 'index']);
    Route::post('/envelopes', [EnvelopeController::class, 'store']);
    Route::get('/envelopes/{envelope}', [EnvelopeController::class, 'show']);
    Route::post('/envelopes/{envelope}/send', [EnvelopeController::class, 'send']);
    Route::post('/envelopes/{envelope}/void', [EnvelopeController::class, 'void']);
    Route::get('/envelopes/{envelope}/audit', [EnvelopeController::class, 'auditTrail']);
    Route::get('/envelopes/{envelope}/download', [EnvelopeController::class, 'download']);
});

/*
|--------------------------------------------------------------------------
| Signer surface — no account, authenticated by tokenised link + OTP
|--------------------------------------------------------------------------
|
| Signers are not users of this system. Every request re-establishes who is
| calling from the token in the URL, so these routes carry no session and share
| no middleware with the admin surface above.
|
*/

Route::prefix('sign/{uuid}')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [SignerController::class, 'show']);
    Route::get('/document', [SignerController::class, 'document']);

    Route::post('/otp', [SignerController::class, 'requestOtp'])->middleware('throttle:10,15');
    Route::post('/otp/verify', [SignerController::class, 'verifyOtp'])->middleware('throttle:20,15');

    Route::post('/consent', [SignerController::class, 'consent']);
    Route::post('/signature', [SignerController::class, 'createSignature']);
    Route::post('/fields', [SignerController::class, 'saveFields']);
    Route::post('/finish', [SignerController::class, 'finish']);
    Route::post('/decline', [SignerController::class, 'decline']);
});

/*
|--------------------------------------------------------------------------
| Public verification
|--------------------------------------------------------------------------
|
| Intentionally unauthenticated: a signature nobody can check without an
| account is not much of a signature.
|
*/

Route::post('/verify', [VerificationController::class, 'verify'])
    ->middleware('throttle:20,1');

/*
|--------------------------------------------------------------------------
| Reviewer demo
|--------------------------------------------------------------------------
|
| Registered only when demo mode is on. One of these returns a live one-time
| passcode, so the routes must not exist anywhere but a local evaluation
| environment — the controller re-checks the flag as a second line of defence.
|
*/

if (config('signing.demo.enabled')) {
    Route::prefix('demo')->middleware('throttle:30,1')->group(function () {
        Route::post('/envelope', [DemoController::class, 'provision']);
        Route::get('/otp/{uuid}', [DemoController::class, 'otp']);
    });
}
