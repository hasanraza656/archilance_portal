<?php

namespace App\Services;

use App\Models\ExperienceLetter;
use App\Models\User;
use App\Traits\ResolvesTaskTree;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Experience letters: who can get one, what the certificate says by default, how many projects the person worked on,
 * and storing the issued letter.
 *
 * Shared by the web API controller and the Claude connector (MCP) so both behave identically.
 * Reads only existing tables (users, work_sessions, project_tasks, projects); writes only experience_letters.
 */
class ExperienceLetterService
{
    use ResolvesTaskTree;

    public const COMPANY = [
        'name' => 'Archilance LLC',
        'tagline' => 'Architecture Outsourcing Services',
        'address_us' => '611 South DuPont Highway, Suite 102, Dover, Delaware 19901, USA',
        'address_pk' => 'Office 106, 4th Floor, Mega Tower, Gulberg 3, Lahore, Pakistan',
        'phone' => '+1 934 204 2245',
        'website' => 'www.archilance.net',
    ];

    private static ?bool $ready = null;

    /** False until the "Update database" step has created the table, so the page can say so instead of erroring. */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('experience_letters');
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    public static function flush(): void
    {
        self::$ready = null;
    }

    /* ------------------------------------------------------------------ themes & wording */

    /** Colour themes. Keys of `colors`: heading (title/name), ink (body text), accent + accent2 (gold-like gradient), paper. */
    public static function presets(): array
    {
        return [
            ['id' => 'archilance-gold', 'name' => 'Archilance Gold', 'colors' => ['paper' => '#FFFEFB', 'ink' => '#2A2D35', 'heading' => '#14161C', 'accent' => '#D9922B', 'accent2' => '#F5C466']],
            ['id' => 'midnight-navy', 'name' => 'Midnight Navy', 'colors' => ['paper' => '#FFFFFF', 'ink' => '#26303F', 'heading' => '#0F2747', 'accent' => '#B8892E', 'accent2' => '#E3C170']],
            ['id' => 'emerald', 'name' => 'Emerald', 'colors' => ['paper' => '#FBFEFC', 'ink' => '#23332C', 'heading' => '#0E4A3A', 'accent' => '#B58A2F', 'accent2' => '#DDBF6A']],
            ['id' => 'burgundy', 'name' => 'Burgundy', 'colors' => ['paper' => '#FFFDFB', 'ink' => '#33262A', 'heading' => '#6B1F33', 'accent' => '#B58A3A', 'accent2' => '#E0C078']],
            ['id' => 'royal-blue', 'name' => 'Royal Blue', 'colors' => ['paper' => '#FFFFFF', 'ink' => '#1F2A44', 'heading' => '#1D3FA8', 'accent' => '#3B6FE0', 'accent2' => '#8FB1FF']],
            ['id' => 'terracotta', 'name' => 'Terracotta', 'colors' => ['paper' => '#FFFCFA', 'ink' => '#3A2A26', 'heading' => '#8A3B21', 'accent' => '#C8693A', 'accent2' => '#EFA56F']],
            ['id' => 'graphite', 'name' => 'Graphite', 'colors' => ['paper' => '#FFFFFF', 'ink' => '#2B2D31', 'heading' => '#111214', 'accent' => '#5B6068', 'accent2' => '#A5AAB2']],
        ];
    }

    public static function defaultTheme(): array
    {
        $p = self::presets()[0];

        return ['preset' => $p['id'], 'colors' => $p['colors'], 'watermark' => true];
    }

    /**
     * Default wording. {{tokens}} are filled in when the certificate is drawn, from the values:
     * name, designation, in_department, worked, period, start_date, end_date, projects, projects_word, he/He/his/His/him.
     * The verbs are chosen so they read correctly for he, she and they.
     */
    public static function defaultTexts(): array
    {
        return [
            'title' => 'Experience Letter',
            'kicker' => 'To whom it may concern',
            'opening' => 'This is to certify that {{name}} {{worked}} with Archilance LLC as {{designation}}{{in_department}}, {{period}}.',
            'body1' => 'During {{his}} time with us, {{name}} contributed to {{projects}} {{projects_word}} for our international clients, delivering work in line with the standards, deadlines and quality expectations of the company.',
            'body2' => '{{He}} showed professionalism, dedication and a strong sense of responsibility, and was a valued member of the team. {{His}} conduct throughout was exemplary.',
            'closing' => 'We thank {{him}} for {{his}} contributions and wish {{him}} every success in {{his}} future endeavours. This letter is issued on {{his}} request, for whatever purpose it may serve.',
            'signatory_name' => '',
            'signatory_title' => 'Authorized Signatory',
            'signatory_company' => 'Archilance LLC',
            'footer_note' => 'This is a computer-generated letter issued by Archilance LLC. Authenticity can be confirmed on request quoting the reference number.',
        ];
    }

    public static function config(): array
    {
        return [
            'company' => self::COMPANY,
            'presets' => self::presets(),
            'default_theme' => self::defaultTheme(),
            'default_texts' => self::defaultTexts(),
        ];
    }

    /* ------------------------------------------------------------------ people */

    /**
     * Staff who can be given a letter. Includes ex-employees (soft-deleted accounts), because letters are most often
     * requested after someone has left. Customers and customer team members are not offered.
     */
    public function people(?string $search = null, int $limit = 30): array
    {
        $q = User::withTrashed()->whereIn('user_role', [3, 6, 7]);

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('username', 'like', $like));
        }

        return $q->orderBy('name')->limit(max(1, min(500, $limit)))->get()->map(fn (User $u) => $this->personRow($u))->all();
    }

    private function personRow(User $u): array
    {
        return [
            'id' => (int) $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'team' => $u->employee_team ?? null,
            'type' => $u->employee_type ?? null,
            'joining_date' => $this->dateOnly($u->joining_date ?? null),
            'hire_end_date' => $this->dateOnly($u->hire_end_date ?? null),
            'former' => $u->deleted_at !== null,
        ];
    }

    /** Everything the certificate needs for one person, ready to edit. */
    public function prefill(int $userId): ?array
    {
        $u = User::withTrashed()->whereIn('user_role', [3, 6, 7])->find($userId);
        if (!$u) {
            return null;
        }

        $proj = $this->projectsWorkedOn((int) $u->id);
        $gender = strtolower(trim((string) ($u->gender ?? '')));
        $pronoun = $gender === 'male' ? 'he' : ($gender === 'female' ? 'she' : 'they');

        $warnings = [];
        if (empty($u->joining_date)) {
            $warnings[] = 'This person has no joining date on file. Enter the start date by hand.';
        }
        if (empty($u->hire_end_date)) {
            $warnings[] = $u->deleted_at ? 'No hire end date on file for this former employee. Enter the end date by hand.' : 'No hire end date on file, so the letter reads as "currently working". Set an end date if they have left.';
        }
        if ($proj['count'] === 0) {
            $warnings[] = 'No work-diary activity was found for this person, so the project count is 0. Adjust it if needed.';
        }

        return [
            'employee' => $this->personRow($u),
            'values' => [
                'name' => (string) $u->name,
                'designation' => $this->defaultDesignation($u->employee_type ?? null),
                'department' => (string) ($u->employee_team ?? ''),
                'start_date' => $this->dateOnly($u->joining_date ?? null),
                'end_date' => $this->dateOnly($u->hire_end_date ?? null),
                'issue_date' => Carbon::now()->toDateString(),
                'projects_count' => $proj['count'],
                'pronoun' => $pronoun,
            ],
            'projects' => $proj,
            'warnings' => $warnings,
        ];
    }

    private function defaultDesignation(?string $type): string
    {
        $t = strtolower(trim((string) $type));

        return match ($t) {
            'manager' => 'Manager',
            'supervisor' => 'Supervisor',
            'executive' => 'Executive',
            'internee', 'intern' => 'Intern',
            default => 'Team Member',
        };
    }

    private function dateOnly(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ project count */

    /**
     * Every distinct FIRST-LEVEL task (a task with no parent, inside a job) that the person logged time on, on any day,
     * according to the work diary. Sub-tasks roll up to the first-level task they sit under, however deep.
     * Sessions of zero length (start = end) are ignored; running timers count.
     *
     * @return array{count:int, jobs:int, items:array<int,array>, truncated:bool, missing_tasks:int}
     */
    public function projectsWorkedOn(int $userId): array
    {
        $sessions = DB::table('work_sessions')
            ->where('user_id', $userId)
            ->whereNotNull('task_id')
            ->where('task_id', '>', 0)
            ->whereRaw('NOT (end_time IS NOT NULL AND end_time = start_time AND (end_date IS NULL OR end_date = start_date))')
            ->groupBy('task_id')
            ->selectRaw('task_id, COUNT(*) as sessions, MIN(start_date) as first_day, MAX(start_date) as last_day')
            ->get();

        if ($sessions->isEmpty()) {
            return ['count' => 0, 'jobs' => 0, 'items' => [], 'truncated' => false, 'missing_tasks' => 0];
        }

        // Load every task involved, then walk up through parents in batches until every chain reaches its root.
        $tasks = [];
        $need = $sessions->pluck('task_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        for ($round = 0; $round < 45 && !empty($need); $round++) {
            $next = [];
            foreach (array_chunk($need, 800) as $chunk) {
                foreach (DB::table('project_tasks')->whereIn('id', $chunk)->get(['id', 'parent_task_id', 'project_id', 'task_title']) as $t) {
                    $tasks[(int) $t->id] = $t;
                    if (!empty($t->parent_task_id) && !isset($tasks[(int) $t->parent_task_id])) {
                        $next[] = (int) $t->parent_task_id;
                    }
                }
            }
            $need = array_values(array_unique($next));
        }

        $parentOf = [];
        foreach ($tasks as $id => $t) {
            $parentOf[$id] = $t->parent_task_id ? (int) $t->parent_task_id : null;
        }
        $rootOf = $this->rootTaskMap($parentOf);

        $roots = [];
        $missing = 0;
        foreach ($sessions as $s) {
            $tid = (int) $s->task_id;
            if (!isset($tasks[$tid])) {
                $missing++;     // the task was deleted from the portal; there is nothing left to count it under
                continue;
            }
            $root = $rootOf[$tid] ?? $tid;
            if (!isset($tasks[$root])) {
                $missing++;
                continue;
            }
            $r = $roots[$root] ??= ['task_id' => $root, 'sessions' => 0, 'first_day' => $s->first_day, 'last_day' => $s->last_day];
            $r['sessions'] += (int) $s->sessions;
            $r['first_day'] = min($r['first_day'], $s->first_day);
            $r['last_day'] = max($r['last_day'], $s->last_day);
            $roots[$root] = $r;
        }

        $projectIds = [];
        foreach ($roots as $r) {
            $projectIds[] = (int) $tasks[$r['task_id']]->project_id;
        }
        $jobNames = [];
        foreach (array_chunk(array_values(array_unique($projectIds)), 800) as $chunk) {
            foreach (DB::table('projects')->whereIn('id', $chunk)->get(['id', 'project_name']) as $p) {
                $jobNames[(int) $p->id] = (string) $p->project_name;
            }
        }

        $items = [];
        foreach ($roots as $r) {
            $t = $tasks[$r['task_id']];
            $items[] = [
                'task_id' => $r['task_id'],
                'task' => (string) ($t->task_title ?? ''),
                'job_id' => (int) $t->project_id,
                'job' => $jobNames[(int) $t->project_id] ?? '',
                'first_day' => $r['first_day'],
                'last_day' => $r['last_day'],
                'sessions' => $r['sessions'],
            ];
        }
        usort($items, fn ($a, $b) => [$a['first_day'], $a['task_id']] <=> [$b['first_day'], $b['task_id']]);

        $cap = 400;

        return [
            'count' => count($items),
            'jobs' => count(array_unique(array_column($items, 'job_id'))),
            'items' => array_slice($items, 0, $cap),
            'truncated' => count($items) > $cap,
            'missing_tasks' => $missing,
        ];
    }

    /* ------------------------------------------------------------------ storing */

    /**
     * Clean and bound whatever the client (or Claude) sends. Anything unknown is dropped; text is trimmed and length-limited;
     * colours must be #RRGGBB. Returns [data, error].
     *
     * @return array{0:?array,1:?string}
     */
    public function normalize(array $in): array
    {
        $v = (array) ($in['values'] ?? []);
        $name = $this->str($v['name'] ?? '', 150);
        if ($name === '') {
            return [null, 'The employee name is required.'];
        }

        $dates = [];
        foreach (['start_date', 'end_date', 'issue_date'] as $k) {
            $raw = trim((string) ($v[$k] ?? ''));
            if ($raw === '') {
                $dates[$k] = null;
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) || !checkdate((int) substr($raw, 5, 2), (int) substr($raw, 8, 2), (int) substr($raw, 0, 4))) {
                return [null, 'Dates must be real dates in the form YYYY-MM-DD (' . $k . ').'];
            }
            $dates[$k] = $raw;
        }
        if (!$dates['issue_date']) {
            $dates['issue_date'] = Carbon::now()->toDateString();
        }
        if ($dates['start_date'] && $dates['end_date'] && $dates['end_date'] < $dates['start_date']) {
            return [null, 'The end date cannot be before the start date.'];
        }

        $count = $v['projects_count'] ?? 0;
        if (!is_numeric($count) || (int) $count < 0 || (int) $count > 100000) {
            return [null, 'The number of projects must be a whole number from 0 up.'];
        }

        $pronoun = in_array($v['pronoun'] ?? null, ['he', 'she', 'they'], true) ? $v['pronoun'] : 'they';

        $defaults = self::defaultTexts();
        $texts = [];
        $inTexts = (array) ($in['texts'] ?? []);
        foreach ($defaults as $k => $def) {
            $max = in_array($k, ['title', 'kicker', 'signatory_name', 'signatory_title', 'signatory_company'], true) ? 160 : 1200;
            $texts[$k] = array_key_exists($k, $inTexts) ? $this->str($inTexts[$k], $max, true) : $def;
        }

        $theme = (array) ($in['theme'] ?? []);
        $base = self::defaultTheme();
        $colors = $base['colors'];
        foreach ((array) ($theme['colors'] ?? []) as $k => $hex) {
            if (array_key_exists($k, $colors) && is_string($hex) && preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                $colors[$k] = strtoupper($hex);
            }
        }
        $presetId = is_string($theme['preset'] ?? null) && preg_match('/^[a-z0-9-]{1,30}$/', $theme['preset']) ? $theme['preset'] : 'custom';

        $items = [];
        foreach (array_slice((array) ($in['projects'] ?? []), 0, 400) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $items[] = ['job' => $this->str($p['job'] ?? '', 200), 'task' => $this->str($p['task'] ?? '', 250), 'first_day' => $this->str($p['first_day'] ?? '', 10), 'last_day' => $this->str($p['last_day'] ?? '', 10)];
        }

        $data = [
            'values' => [
                'name' => $name,
                'designation' => $this->str($v['designation'] ?? '', 150),
                'department' => $this->str($v['department'] ?? '', 150),
                'start_date' => $dates['start_date'],
                'end_date' => $dates['end_date'],
                'issue_date' => $dates['issue_date'],
                'projects_count' => (int) $count,
                'pronoun' => $pronoun,
            ],
            'texts' => $texts,
            'theme' => ['preset' => $presetId, 'colors' => $colors, 'watermark' => (bool) ($theme['watermark'] ?? true)],
            'projects' => $items,
        ];

        return [$data, null];
    }

    private function str(mixed $v, int $max, bool $keepNewlines = false): string
    {
        $s = is_scalar($v) ? (string) $v : '';
        $s = $keepNewlines ? str_replace("\r", '', $s) : preg_replace('/\s+/u', ' ', $s);
        $s = trim((string) $s);

        return mb_substr($s, 0, $max);
    }

    /** Create a letter for an employee. Returns [letter, error]. */
    public function create(int $employeeId, array $payload, ?int $createdBy, string $source = 'portal'): array
    {
        $employee = User::withTrashed()->whereIn('user_role', [3, 6, 7])->find($employeeId);
        if (!$employee) {
            return [null, 'That employee was not found.'];
        }

        [$data, $error] = $this->normalize($payload);
        if ($error) {
            return [null, $error];
        }

        for ($attempt = 0; $attempt < 6; $attempt++) {
            try {
                $letter = DB::transaction(function () use ($data, $employee, $createdBy, $source) {
                    $ref = $this->nextReference(substr((string) $data['values']['issue_date'], 0, 4));

                    return ExperienceLetter::create($this->withToken([
                        'reference_no' => $ref,
                        'employee_id' => $employee->id,
                        'employee_name' => $data['values']['name'],
                        'designation' => $data['values']['designation'] ?: null,
                        'start_date' => $data['values']['start_date'],
                        'end_date' => $data['values']['end_date'],
                        'issue_date' => $data['values']['issue_date'],
                        'projects_count' => $data['values']['projects_count'],
                        'data' => $data,
                        'source' => $source === 'mcp' ? 'mcp' : 'portal',
                        'created_by' => $createdBy,
                    ]));
                });

                return [$letter, null];
            } catch (QueryException $e) {
                // Two people generating at the same moment can pick the same number; take the next one.
                if ($attempt === 5 || !str_contains(strtolower($e->getMessage()), 'unique') && !str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
        }

        return [null, 'Could not allocate a reference number. Please try again.'];
    }

    /** Replace the content of an existing letter (reference number and employee stay). Returns [letter, error]. */
    public function update(ExperienceLetter $letter, array $payload): array
    {
        [$data, $error] = $this->normalize($payload);
        if ($error) {
            return [null, $error];
        }

        $letter->update([
            'employee_name' => $data['values']['name'],
            'designation' => $data['values']['designation'] ?: null,
            'start_date' => $data['values']['start_date'],
            'end_date' => $data['values']['end_date'],
            'issue_date' => $data['values']['issue_date'],
            'projects_count' => $data['values']['projects_count'],
            'data' => $data,
        ]);

        return [$letter->fresh(), null];
    }

    /** Adds the QR code value, but only once the column exists (so code can be uploaded before the database update). */
    private function withToken(array $row): array
    {
        try {
            if (Schema::hasColumn('experience_letters', 'verify_token')) {
                $row['verify_token'] = Str::random(40);
            }
        } catch (\Throwable $e) {
            // leave it out
        }

        return $row;
    }

    /** The public page address a QR code points to, or null if this letter has no code yet. */
    public static function verifyUrl(ExperienceLetter $l): ?string
    {
        $t = $l->getAttribute('verify_token');

        return $t ? frontendUrl('verify/' . $t) : null;
    }

    /** ALLC/EXP/2026/0001 - the next free number for that year (deleted letters keep their numbers). */
    private function nextReference(string $year): string
    {
        if (!preg_match('/^\d{4}$/', $year)) {
            $year = Carbon::now()->format('Y');
        }
        $prefix = "ALLC/EXP/{$year}/";
        $last = ExperienceLetter::withTrashed()->where('reference_no', 'like', $prefix . '%')->orderByDesc('reference_no')->value('reference_no');
        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** The JSON shape both the web UI and Claude receive. */
    public function present(ExperienceLetter $l, bool $withData = true): array
    {
        $row = [
            'id' => (int) $l->id,
            'reference_no' => $l->reference_no,
            'employee_id' => (int) $l->employee_id,
            'employee_name' => $l->employee_name,
            'designation' => $l->designation,
            'start_date' => $l->start_date?->toDateString(),
            'end_date' => $l->end_date?->toDateString(),
            'issue_date' => $l->issue_date?->toDateString(),
            'projects_count' => (int) $l->projects_count,
            'source' => $l->source,
            'created_by' => $l->created_by ? (int) $l->created_by : null,
            'created_by_name' => $l->creator?->name,
            'verify_url' => self::verifyUrl($l),
            'created_at' => $l->created_at?->toIso8601String(),
            'updated_at' => $l->updated_at?->toIso8601String(),
        ];
        if ($withData) {
            $row['data'] = $l->data;
        }

        return $row;
    }
}
