<?php

namespace App\Traits;

use App\Models\ActivityLog;
use App\Models\TrackWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the `windows_activity` payload (the work-diary "app usage" section) from
 * `activity_logs` instead of the now-unused `track_windows` table.
 *
 * Shape returned per row matches what the React work-diary reads:
 *   { session_id, app_name, window_title, duration_seconds }
 *
 * activity_logs stores the window TITLE (`active_window_title`) but not the app
 * name, so the app name is derived from the title (Windows titles are
 * "Document Title - App Name"). If an `active_app_name` column/field is ever
 * added (and the tracker sends it), it is used directly and derivation is skipped.
 *
 * App usage must never exceed worked time, so each bucket is counted ONCE and only for time that is not idle:
 *   - the tracker can send the same bucket several times (offline-queue retries, two uploads), so a second row for
 *     time already counted is dropped (overlaps are clipped to what has not been counted yet);
 *   - the tracker only flags a bucket idle after its inactivity threshold (about 6 minutes), while the diary's idle
 *     time (session_time_adjustments) starts at the LAST activity, so those first minutes of every idle spell are
 *     logged as active but are not worked time. They are taken out here too, using the very same idle intervals the
 *     worked-time figure uses.
 *
 * Sessions that predate activity logging (they have track_windows rows but no
 * activity_logs) fall back to their legacy track_windows data, so historical
 * diaries don't go blank during the transition.
 */
trait BuildsWindowActivity
{
    /** A single activity bucket is ~30s; anything longer is a clock/sleep artifact. */
    private const MAX_BUCKET_SECONDS = 1800;

    protected function windowsActivity(array $sessionIds): array
    {
        $sessionIds = array_values(array_unique(array_filter($sessionIds)));
        if (empty($sessionIds)) {
            return [];
        }

        // Which of these sessions actually have activity logs?
        $coveredIds = ActivityLog::whereIn('session_id', $sessionIds)
            ->distinct()
            ->pluck('session_id')
            ->all();

        $fromLogs = $this->windowsActivityFromLogs($coveredIds);

        // Legacy sessions (no activity logs yet) keep their old track_windows data.
        $legacyIds = array_values(array_diff($sessionIds, $coveredIds));
        $fromTrack = !empty($legacyIds)
            ? TrackWindow::whereIn('session_id', $legacyIds)->get()->toArray()
            : [];

        return array_merge($fromLogs, $fromTrack);
    }

    /**
     * Aggregate activity_logs (active, titled buckets) by app into windows_activity rows.
     */
    protected function windowsActivityFromLogs(array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        $logs = ActivityLog::whereIn('session_id', $sessionIds)
            ->where(function ($q) {
                // Only ACTIVE buckets — idle time is reported separately by the diary.
                $q->where('is_idle', false)->orWhereNull('is_idle');
            })
            ->whereNotNull('active_window_title')
            ->where('active_window_title', '!=', '')
            ->orderBy('session_id')->orderBy('start_time')->orderBy('end_time')
            ->get();

        $idleBySession = $this->mergedIdleBySession($sessionIds);
        $countedUntil = []; // session_id => timestamp up to which activity has already been counted

        // app_name => ['duration' => int, 'session_id' => mixed, 'titles' => [title => seconds]]
        $apps = [];

        foreach ($logs as $log) {
            $title = trim((string) $log->active_window_title);
            if ($title === '') {
                continue;
            }

            $seconds = $this->countableSeconds($log, $idleBySession[$log->session_id] ?? [], $countedUntil);
            if ($seconds <= 0) {
                continue;
            }

            // Prefer a real app name if the tracker ever provides one; otherwise derive it.
            $app = trim((string) ($log->active_app_name ?? '')) ?: $this->deriveAppName($title);
            if ($app === '') {
                continue;
            }

            if (!isset($apps[$app])) {
                $apps[$app] = ['duration' => 0, 'session_id' => $log->session_id, 'titles' => []];
            }
            $apps[$app]['duration'] += $seconds;
            $apps[$app]['titles'][$title] = ($apps[$app]['titles'][$title] ?? 0) + $seconds;
        }

        $rows = [];
        foreach ($apps as $app => $data) {
            arsort($data['titles']); // representative title = the one used longest
            $rows[] = [
                'session_id'       => $data['session_id'],
                'app_name'         => $app,
                'window_title'     => (string) array_key_first($data['titles']),
                'duration_seconds' => (int) $data['duration'],
            ];
        }

        // Biggest first (cosmetic; the frontend re-aggregates by app_name anyway).
        usort($rows, fn($a, $b) => $b['duration_seconds'] <=> $a['duration_seconds']);

        return $rows;
    }

    /**
     * Seconds of this bucket that are new (not already counted from a duplicate / overlapping row) and not idle.
     * Buckets arrive ordered by start time per session, so a single "counted up to" marker per session is enough.
     */
    protected function countableSeconds($log, array $idle, array &$countedUntil): int
    {
        if (empty($log->start_time) || empty($log->end_time) || $this->bucketSeconds($log->start_time, $log->end_time) <= 0) {
            return 0;
        }

        try {
            $start = Carbon::parse($log->start_time)->getTimestamp();
            $end = Carbon::parse($log->end_time)->getTimestamp();
        } catch (\Throwable $e) {
            return 0;
        }

        $sid = $log->session_id;
        $from = max($start, $countedUntil[$sid] ?? $start);
        if ($end <= $from) {
            return 0; // the same time was already counted by an earlier row
        }
        $countedUntil[$sid] = max($countedUntil[$sid] ?? 0, $end);

        $seconds = $end - $from;
        foreach ($idle as [$is, $ie]) {
            $a = max($from, $is);
            $b = min($end, $ie);
            if ($b > $a) {
                $seconds -= ($b - $a);
            }
        }

        return max(0, $seconds);
    }

    /**
     * The idle intervals of each session (session_time_adjustments), merged so overlapping rows count once,
     * as sorted [startTimestamp, endTimestamp] pairs.
     *
     * @return array<int, array<int, array{0:int,1:int}>>
     */
    protected function mergedIdleBySession(array $sessionIds): array
    {
        $out = [];
        try {
            $rows = DB::table('session_time_adjustments')->whereIn('session_id', $sessionIds)->get(['session_id', 'start_time', 'end_time']);
        } catch (\Throwable $e) {
            return [];
        }

        $raw = [];
        foreach ($rows as $r) {
            try {
                $a = Carbon::parse($r->start_time)->getTimestamp();
                $b = Carbon::parse($r->end_time)->getTimestamp();
            } catch (\Throwable $e) {
                continue;
            }
            if ($b > $a) {
                $raw[$r->session_id][] = [$a, $b];
            }
        }

        foreach ($raw as $sid => $list) {
            usort($list, fn ($x, $y) => $x[0] <=> $y[0]);
            $merged = [];
            foreach ($list as $iv) {
                $n = count($merged);
                if ($n === 0 || $iv[0] > $merged[$n - 1][1]) {
                    $merged[] = $iv;
                } elseif ($iv[1] > $merged[$n - 1][1]) {
                    $merged[$n - 1][1] = $iv[1];
                }
            }
            $out[$sid] = $merged;
        }

        return $out;
    }

    /** Seconds spanned by one bucket, dropping non-positive and absurd (clock-jump) values. */
    protected function bucketSeconds($start, $end): int
    {
        if (empty($start) || empty($end)) {
            return 0;
        }
        try {
            $s = Carbon::parse($start);
            $e = Carbon::parse($end);
        } catch (\Throwable $ex) {
            return 0;
        }
        if ($e->lte($s)) {
            return 0;
        }
        $seconds = (int) abs($e->diffInSeconds($s));

        return $seconds > self::MAX_BUCKET_SECONDS ? 0 : $seconds;
    }

    /**
     * Derive an app name from a window title. On Windows the reliable convention is
     * "Document Title - App Name", so the app is the last segment after a hyphen /
     * en-dash / em-dash separator. We deliberately do NOT split on " | ": some apps
     * put the app name FIRST around a pipe (e.g. "Slack | workspace"), so splitting
     * would mis-attribute — keeping the whole title is the safer default there.
     * Falls back to the whole title when there's no separator.
     */
    protected function deriveAppName(string $title): string
    {
        $parts = preg_split('/\s+[\-\x{2013}\x{2014}]\s+/u', $title) ?: [$title];
        $last = trim((string) end($parts));

        return $last !== '' ? $last : trim($title);
    }
}
