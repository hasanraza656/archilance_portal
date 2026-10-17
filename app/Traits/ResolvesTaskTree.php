<?php

namespace App\Traits;

use App\Models\ProjectTask;

/**
 * Sub-task trees, resolved with a few batched queries (no recursive SQL, no
 * per-row lookups). Tasks nest to any depth via parent_task_id.
 *
 * Used so a parent task's logged time includes everything logged on its
 * sub-tasks, however deep.
 */
trait ResolvesTaskTree
{
    /**
     * The task itself plus every descendant, as plain ints (root first).
     *
     * @return int[]
     */
    protected function taskTreeIds(int $rootId): array
    {
        $ids = [$rootId];
        $seen = [$rootId => true];
        $frontier = [$rootId];

        // Depth guard so a corrupt parent loop can never spin forever.
        for ($depth = 0; $depth < 40 && !empty($frontier); $depth++) {
            $next = [];

            foreach (array_chunk($frontier, 1000) as $chunk) {
                foreach (ProjectTask::whereIn('parent_task_id', $chunk)->pluck('id') as $childId) {
                    $childId = (int) $childId;
                    if (!isset($seen[$childId])) {
                        $seen[$childId] = true;
                        $ids[] = $childId;
                        $next[] = $childId;
                    }
                }
            }

            $frontier = $next;
        }

        return $ids;
    }

    /**
     * For a set of tasks (id => parent_task_id), the root task each one rolls up to.
     * Walks parents in memory; a loop or missing parent just stops the walk.
     *
     * @param  array<int, int|null>  $parentOf
     * @return array<int, int>       task id => root task id
     */
    protected function rootTaskMap(array $parentOf): array
    {
        $rootOf = [];

        foreach (array_keys($parentOf) as $id) {
            $cur = $id;
            $guard = 0;

            // array_key_exists, not isset(): a first-level task's own parent is null, and isset() treats that as missing.
            while (!empty($parentOf[$cur]) && array_key_exists($parentOf[$cur], $parentOf) && $guard++ < 40) {
                $cur = (int) $parentOf[$cur];
            }

            $rootOf[$id] = (int) $cur;
        }

        return $rootOf;
    }
}
