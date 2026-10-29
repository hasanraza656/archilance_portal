<?php

namespace App\Services\Mcp\Tools;

use App\Models\ExperienceLetter;
use App\Services\ExperienceLetterService;
use App\Services\Mcp\Present;
use App\Services\Mcp\S;
use App\Services\Mcp\Tool;
use App\Services\Mcp\ToolContext;
use App\Services\Mcp\ToolError;

/**
 * Experience letters (certificates) for employees, including former employees. The same service the web page uses, so a
 * letter made here shows up in the portal's "Experience Letters" list and can be downloaded as PDF / image from there.
 */
final class ExperienceLetterTools
{
    /** @return Tool[] */
    public static function all(): array
    {
        return [self::prefill(), self::themes(), self::generate(), self::listLetters(), self::getLetter(), self::update()];
    }

    private static function svc(): ExperienceLetterService
    {
        return app(ExperienceLetterService::class);
    }

    private static function ready(): void
    {
        if (!ExperienceLetterService::ready()) {
            throw new ToolError('Experience letters are not set up yet: the database update is pending. Ask the user to open System settings in the portal and press "Update database".');
        }
    }

    private static function link(int $id): string
    {
        return function_exists('frontendUrl') ? frontendUrl('experience-letters/' . $id) : '/experience-letters/' . $id;
    }

    /** The optional fields shared by generate and update. */
    private static function editable(): array
    {
        return [
            'designation' => S::str('Job title printed on the letter, e.g. "BIM Modeler".', 150),
            'department' => S::str('Team / department, e.g. "BIM Team". Empty string removes it.', 150),
            'start_date' => S::date('Hire start date'),
            'end_date' => S::date('Hire end date. Leave out for someone still working here (the letter then says "currently working").'),
            'issue_date' => S::date('Date printed on the letter (default today)'),
            'projects_count' => S::int('Number of projects worked on. Default: counted automatically from the work diary.', 0, 100000),
            'theme' => S::str('Colour theme id: archilance-gold (default), midnight-navy, emerald, burgundy, royal-blue, terracotta, graphite. See experience_letter_themes.', 30),
            'custom_colors' => S::obj('Optional custom colours as #RRGGBB, any of: heading, ink, accent, accent2, paper. Overrides the theme.'),
            'watermark' => S::bool('Faint logo watermark behind the text (default true).'),
            'texts' => S::obj('Optional wording overrides. Keys: title, kicker, opening, body1, body2, closing, signatory_name, signatory_title, signatory_company, footer_note. Paragraphs may use {{name}} {{designation}} {{in_department}} {{worked}} {{period}} {{start_date}} {{end_date}} {{projects}} {{projects_word}} {{he}} {{He}} {{his}} {{His}} {{him}}.'),
        ];
    }

    private static function prefill(): Tool
    {
        return new Tool(
            'experience_letter_prefill',
            'Preview experience letter data',
            'Everything an experience letter for one person would say, WITHOUT creating it: name, default job title, team, hire start/end dates, and the number of projects they worked on (counted from the work diary: every distinct first-level task inside a job that they logged time on, on any day, sub-tasks rolled up; includes former employees). Also lists those projects and warns about missing dates. Use before generate_experience_letter to confirm the facts with the user.',
            [
                'employee_id' => S::int('Employee user id (former employees work too). Use find_people / list_users to get it.', 1),
                'include_projects' => S::bool('Also list the counted projects (job + first-level task). Default false.'),
            ],
            ['employee_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $out = self::svc()->prefill((int) $a['employee_id']);
                if (!$out) {
                    throw new ToolError('Employee not found. Experience letters are for staff accounts (employees, supervisors, executives).');
                }
                if (empty($a['include_projects'])) {
                    $out['projects'] = ['count' => $out['projects']['count'], 'jobs' => $out['projects']['jobs'], 'tasks_deleted_from_portal' => $out['projects']['missing_tasks']];
                } else {
                    $out['projects']['items'] = array_slice($out['projects']['items'], 0, 150);
                }

                return Present::clean($out);
            }
        );
    }

    private static function themes(): Tool
    {
        return new Tool(
            'experience_letter_themes',
            'Experience letter colour themes',
            'The ready-made colour themes for experience letters and the default wording. The default theme (archilance-gold) matches the Archilance brand.',
            [],
            [],
            Tool::READ,
            fn (array $a, ToolContext $c) => ['themes' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'colors' => $p['colors']], ExperienceLetterService::presets()), 'default_texts' => ExperienceLetterService::defaultTexts()]
        );
    }

    /** Turn tool arguments into the payload the service stores, on top of $base (prefill values or an existing letter). */
    private static function build(array $base, array $a): array
    {
        $values = $base['values'];
        foreach (['designation', 'department', 'start_date', 'end_date', 'issue_date', 'projects_count'] as $k) {
            if (array_key_exists($k, $a)) {
                $values[$k] = $a[$k];
            }
        }

        $theme = $base['theme'] ?? ExperienceLetterService::defaultTheme();
        if (!empty($a['theme'])) {
            $preset = null;
            foreach (ExperienceLetterService::presets() as $p) {
                if ($p['id'] === $a['theme']) {
                    $preset = $p;
                }
            }
            if (!$preset) {
                throw new ToolError('Unknown theme "' . $a['theme'] . '". Use experience_letter_themes to see the ids.');
            }
            $theme = ['preset' => $preset['id'], 'colors' => $preset['colors'], 'watermark' => $theme['watermark'] ?? true];
        }
        if (!empty($a['custom_colors']) && is_array($a['custom_colors'])) {
            foreach ($a['custom_colors'] as $k => $hex) {
                if (!in_array($k, ['heading', 'ink', 'accent', 'accent2', 'paper'], true) || !is_string($hex) || !preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                    throw new ToolError('custom_colors must use the keys heading, ink, accent, accent2, paper with #RRGGBB values.');
                }
                $theme['colors'][$k] = strtoupper($hex);
            }
            $theme['preset'] = 'custom';
        }
        if (array_key_exists('watermark', $a)) {
            $theme['watermark'] = (bool) $a['watermark'];
        }

        $texts = $base['texts'] ?? [];
        if (!empty($a['texts']) && is_array($a['texts'])) {
            $texts = array_merge($texts, array_intersect_key($a['texts'], ExperienceLetterService::defaultTexts()));
        }

        return ['values' => $values, 'texts' => $texts, 'theme' => $theme, 'projects' => $base['projects'] ?? []];
    }

    private static function generate(): Tool
    {
        return new Tool(
            'generate_experience_letter',
            'Generate an experience letter',
            'Create and STORE an experience letter (certificate) for one employee, current or former. Everything is filled in automatically (name, job title, team, hire start/end dates, projects worked on, default Archilance Gold theme); pass only what the user wants to change. Nothing is sent to anyone. The letter appears in the portal\'s Experience Letters list, where it can be opened, edited and downloaded as a high-resolution PDF or image - the result includes that link. Tip: run experience_letter_prefill first and confirm start/end dates with the user, because a letter is an official document.',
            ['employee_id' => S::int('Employee user id.', 1)] + self::editable(),
            ['employee_id'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                self::ready();
                $pre = self::svc()->prefill((int) $a['employee_id']);
                if (!$pre) {
                    throw new ToolError('Employee not found. Experience letters are for staff accounts (employees, supervisors, executives).');
                }

                $base = [
                    'values' => $pre['values'],
                    'texts' => ExperienceLetterService::defaultTexts(),
                    'theme' => ExperienceLetterService::defaultTheme(),
                    'projects' => array_map(fn ($p) => ['job' => $p['job'], 'task' => $p['task'], 'first_day' => $p['first_day'], 'last_day' => $p['last_day']], $pre['projects']['items']),
                ];
                [$letter, $err] = self::svc()->create((int) $a['employee_id'], self::build($base, $a), (int) $c->user->id, 'mcp');
                if ($err) {
                    throw new ToolError($err);
                }

                return Present::clean([
                    'created' => true,
                    'id' => (int) $letter->id,
                    'reference_no' => $letter->reference_no,
                    'employee' => $letter->employee_name,
                    'designation' => $letter->designation,
                    'start_date' => $letter->start_date?->toDateString(),
                    'end_date' => $letter->end_date?->toDateString() ?? 'still working (letter says "currently working")',
                    'projects_count' => (int) $letter->projects_count,
                    'theme' => $letter->data['theme']['preset'] ?? null,
                    'open_and_download' => self::link((int) $letter->id),
                    'warnings' => $pre['warnings'],
                ]);
            }
        );
    }

    private static function listLetters(): Tool
    {
        return new Tool(
            'list_experience_letters',
            'List experience letters',
            'Experience letters that have been issued, newest first. Optional search by employee name, reference number or job title, or by employee id.',
            ['search' => S::str('Name, reference number (e.g. ALLC/EXP/2026/0001) or job title.', 100), 'employee_id' => S::int('Only this employee.', 1), 'limit' => S::limit(20, 100)],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                self::ready();
                $q = ExperienceLetter::query()->latest('id');
                if (!empty($a['search'])) {
                    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($a['search'])) . '%';
                    $q->where(fn ($w) => $w->where('employee_name', 'like', $like)->orWhere('reference_no', 'like', $like)->orWhere('designation', 'like', $like));
                }
                if (!empty($a['employee_id'])) {
                    $q->where('employee_id', (int) $a['employee_id']);
                }
                $total = (clone $q)->count();
                $limit = $c->limit($a, 20, 100);
                $rows = $q->limit($limit)->get()->map(fn ($l) => Present::clean([
                    'id' => (int) $l->id,
                    'reference_no' => $l->reference_no,
                    'employee_id' => (int) $l->employee_id,
                    'employee' => $l->employee_name,
                    'designation' => $l->designation,
                    'start_date' => $l->start_date?->toDateString(),
                    'end_date' => $l->end_date?->toDateString(),
                    'issued' => $l->issue_date?->toDateString(),
                    'projects_count' => (int) $l->projects_count,
                    'link' => self::link((int) $l->id),
                ]))->all();

                return Present::page($rows, $limit, $total);
            }
        );
    }

    private static function getLetter(): Tool
    {
        return new Tool(
            'get_experience_letter',
            'Get one experience letter',
            'One issued experience letter: its values, wording, theme and the link where it can be opened and downloaded as PDF / image.',
            ['id' => S::int('Experience letter id.', 1)],
            ['id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                self::ready();
                $l = ExperienceLetter::with('creator:id,name')->find($a['id']);
                if (!$l) {
                    throw new ToolError('Experience letter not found.');
                }

                return Present::clean(self::svc()->present($l) + ['link' => self::link((int) $l->id)]);
            }
        );
    }

    private static function update(): Tool
    {
        return new Tool(
            'update_experience_letter',
            'Edit an experience letter',
            'Change an issued experience letter (dates, job title, number of projects, wording, theme). Its reference number stays the same. Only the fields you pass change.',
            ['id' => S::int('Experience letter id.', 1)] + self::editable(),
            ['id'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                self::ready();
                $l = ExperienceLetter::find($a['id']);
                if (!$l) {
                    throw new ToolError('Experience letter not found.');
                }
                [$updated, $err] = self::svc()->update($l, self::build((array) $l->data, $a));
                if ($err) {
                    throw new ToolError($err);
                }

                return Present::clean(['updated' => true, 'id' => (int) $updated->id, 'reference_no' => $updated->reference_no, 'employee' => $updated->employee_name, 'start_date' => $updated->start_date?->toDateString(), 'end_date' => $updated->end_date?->toDateString(), 'projects_count' => (int) $updated->projects_count, 'open_and_download' => self::link((int) $updated->id)]);
            }
        );
    }
}
