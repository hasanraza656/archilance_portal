<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Support\DatabaseUpdates;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * System settings — admin only (mounted in the role:2 group, deliberately NOT
 * duplicated for executives).
 *
 *   GET  /admin/system/info        read-only facts about the server
 *   GET  /admin/system/db-status   which database updates are pending
 *   POST /admin/system/db-update   apply the pending ones (see DatabaseUpdates)
 */
class SystemSettingsController extends Controller
{
    public function info()
    {
        $database = null;
        $driver = null;
        try {
            $conn = DB::connection();
            $driver = $conn->getDriverName();
            $database = $conn->getDatabaseName();
        } catch (\Throwable $e) {
            // reported as unknown
        }

        return response()->json([
            'app_name' => config('app.name'),
            'environment' => app()->environment(),
            'laravel_version' => Application::VERSION,
            'php_version' => PHP_VERSION,
            'database_driver' => $driver,
            'database_name' => $database,
            'timezone' => config('app.timezone'),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function dbStatus()
    {
        return response()->json(DatabaseUpdates::status());
    }

    public function dbUpdate()
    {
        $outcome = DatabaseUpdates::apply();

        return response()->json($outcome + ['status' => DatabaseUpdates::status()], $outcome['ok'] ? 200 : 500);
    }
}
