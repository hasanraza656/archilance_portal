<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\AuthController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use App\Services\OneDriveService;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/clear-cache', function () {
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    Artisan::call('config:cache');

    return '<h3>✅ All caches cleared successfully!</h3>';
});


Route::get('/test-email', function () {
    try {
        Mail::raw('This is a test email from Laravel using Microsoft 365 SMTP configuration.', function ($message) {
            $message->to('Ranahasanraza24@gmail.com')
                    ->subject('✅ Test Email from Laravel (Microsoft 365)');
        });

        return '<h3>✅ Test email has been sent successfully to Ranahasanraza24@gmail.com</h3>';
    } catch (\Exception $e) {
        return '<h3>❌ Error sending email:</h3><pre>' . $e->getMessage() . '</pre>';
    }
});

Route::get('/onedrive-image', function (Request $request) {

    $path = $request->query('path');

    if (!$path) {
        return response('File path required', 400);
    }

    try {
        $service = app(OneDriveService::class);

        $contents = $service->streamFile($path);

        // Guess MIME type from extension
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $mime = match(strtolower($ext)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };

        return response($contents)
            ->header('Content-Type', $mime);

    } catch (\Throwable $e) {
        // \Throwable, not \Exception: a PHP Error (bad method call, memory
        // exhaustion, type error) is NOT an Exception, so it escaped this
        // block and surfaced as Symfony's generic 500 page instead of the
        // message below — which is exactly what made this hard to diagnose.
        \Log::error('onedrive-image failed', [
            'path'  => $path,
            'error' => $e->getMessage(),
            'class' => get_class($e),
            'file'  => $e->getFile() . ':' . $e->getLine(),
        ]);

        return response()->json([
            'error' => 'Failed to fetch file',
            'type'  => get_class($e),
            'message' => $e->getMessage(),
            'at' => basename($e->getFile()) . ':' . $e->getLine(),
        ], 500);
    }

});




Route::get('/server-time', function () {
    return response()->json([
        'app_timezone' => config('app.timezone'),
        'server_time' => now()->toDateTimeString(),
        'utc_time' => now()->utc()->toDateTimeString(),
    ]);
});



Route::get('/run-scheduler', function () {
    // Run the scheduler
    Artisan::call('schedule:run');

    // Return a message indicating the scheduler has been run
    return 'Scheduler has been executed.';
});



