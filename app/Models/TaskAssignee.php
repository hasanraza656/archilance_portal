<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class TaskAssignee extends Model
{
    protected $guarded = [];

    public function task()
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * Whether the `is_manager` column exists yet. Everything that reads or
     * writes project managers checks this first, so the code can be deployed
     * before the column is added without breaking any existing screen.
     */
    public static function supportsManagers(): bool
    {
        static $supported = null;

        if ($supported === null) {
            try {
                $supported = Schema::hasColumn('task_assignees', 'is_manager');
            } catch (\Throwable $e) {
                $supported = false;
            }
        }

        return $supported;
    }

    /** Ids of the users who manage task $taskId (empty before the column exists). */
    public static function managerIds(int $taskId): array
    {
        if (!static::supportsManagers()) {
            return [];
        }

        return static::where('task_id', $taskId)
            ->where('is_manager', true)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
