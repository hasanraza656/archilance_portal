<?php

namespace App\Support;

/**
 * The bi-weekly manager/executive rating: who rates whom, with which questions,
 * and how the scores are weighted into one final score.
 *
 * Four rating "kinds" (the question set depends on who is rating whom):
 *
 *   team             a team member rates a PROJECT MANAGER they worked for
 *   exec_to_manager  an executive rates a manager of their team
 *   manager_to_exec  a manager rates their team's executive
 *   peer             a manager rates another manager of the same team
 *
 * Every question is scored 1–10 and counts equally within its set. The wording
 * comes from the client's own question lists — change it here only.
 */
class ManagerEvaluationQuestions
{
    public const TEAM = 'team';
    public const EXEC_TO_MANAGER = 'exec_to_manager';
    public const MANAGER_TO_EXEC = 'manager_to_exec';
    public const PEER = 'peer';

    public const MAX_SCORE = 10;

    /** An area is "weak" (shown as needing improvement) when its average is below this. */
    public const WEAK_BELOW = 6.0;

    /** At most this many weak areas are listed to a person. */
    public const MAX_WEAK_AREAS = 3;

    /**
     * How a person's final score is built, by what they are:
     * [kind => weight in percent]. Groups with no ratings that period drop out
     * and the remaining weights are scaled back up to 100.
     */
    public const MANAGER_WEIGHTS = [
        self::EXEC_TO_MANAGER => 40,
        self::TEAM => 40,
        self::PEER => 20,
    ];

    public const EXECUTIVE_WEIGHTS = [
        self::MANAGER_TO_EXEC => 50,
        self::TEAM => 50,
    ];

    /** @return array<string, array{label: string, questions: array<int, array{key: string, title: string, text: string}>}> */
    public static function all(): array
    {
        return [
            self::TEAM => [
                'label' => 'Rate your project manager',
                'questions' => [
                    ['key' => 'clarity_of_instructions', 'title' => 'Clarity of Instructions', 'text' => 'Does your manager provide clear instructions and explain what is expected of you?'],
                    ['key' => 'availability_support', 'title' => 'Availability & Support', 'text' => 'Is your manager available to answer questions and help when you face difficulties?'],
                    ['key' => 'communication', 'title' => 'Communication', 'text' => 'Does your manager communicate respectfully and professionally?'],
                    ['key' => 'fairness_workload', 'title' => 'Fairness & Workload Management', 'text' => 'Does your manager distribute work fairly and set reasonable expectations?'],
                    ['key' => 'problem_solving', 'title' => 'Problem Solving', 'text' => 'Does your manager help resolve problems and remove obstacles that affect your work?'],
                    ['key' => 'feedback_development', 'title' => 'Feedback & Development', 'text' => 'Does your manager provide useful feedback that helps you improve your work?'],
                ],
            ],
            self::EXEC_TO_MANAGER => [
                'label' => 'Rate your manager',
                'questions' => [
                    ['key' => 'quality_of_work', 'title' => 'Quality of Work', 'text' => 'Does the manager ensure that the team consistently delivers high quality work with minimal errors and rework?'],
                    ['key' => 'timely_delivery', 'title' => 'Timely Delivery', 'text' => 'Does the manager ensure that assigned projects and tasks are completed within agreed deadlines?'],
                    ['key' => 'communication', 'title' => 'Communication', 'text' => 'Does the manager communicate effectively with the executive, clients, and team members?'],
                    ['key' => 'understanding', 'title' => 'Understanding', 'text' => 'Does the manager understand project requirements and provide clear instructions to the team?'],
                    ['key' => 'workload_management', 'title' => 'Workload Management', 'text' => 'Does the manager distribute workloads effectively and ensure productive use of available resources?'],
                    ['key' => 'ownership_accountability', 'title' => 'Ownership & Accountability', 'text' => 'Does the manager take ownership of assigned projects and resolve issues without unnecessary escalation?'],
                    ['key' => 'leadership_development', 'title' => 'Leadership & Team Development', 'text' => 'Does the manager demonstrate leadership by supporting, guiding, and developing team members?'],
                    ['key' => 'initiative_problem_solving', 'title' => 'Initiative & Problem Solving', 'text' => 'Does the manager proactively identify potential problems and take initiative to improve team performance?'],
                ],
            ],
            self::MANAGER_TO_EXEC => [
                'label' => 'Rate your executive',
                'questions' => [
                    ['key' => 'direction_clarity', 'title' => 'Direction & Clarity', 'text' => 'Does your executive provide clear direction and communicate departmental goals and expectations effectively?'],
                    ['key' => 'availability_support', 'title' => 'Availability & Support', 'text' => 'Is your executive available to provide guidance and support when you face challenges?'],
                    ['key' => 'communication', 'title' => 'Communication', 'text' => 'Does your executive communicate respectfully and professionally with managers?'],
                    ['key' => 'fairness_impartiality', 'title' => 'Fairness & Impartiality', 'text' => 'Does your executive treat all managers fairly and avoid favoritism?'],
                    ['key' => 'decision_making', 'title' => 'Decision Making & Problem Solving', 'text' => 'Does your executive make timely and effective decisions to resolve problems?'],
                    ['key' => 'resource_workload', 'title' => 'Resource & Workload Management', 'text' => 'Does your executive coordinate resources and workloads effectively across different teams?'],
                    ['key' => 'feedback_leadership', 'title' => 'Feedback & Leadership Development', 'text' => 'Does your executive provide constructive feedback and help you develop your leadership and management skills?'],
                    ['key' => 'ownership_accountability', 'title' => 'Ownership & Accountability', 'text' => 'Does your executive take responsibility for departmental issues and support managers in resolving them?'],
                ],
            ],
            self::PEER => [
                'label' => 'Rate a fellow manager',
                'questions' => [
                    ['key' => 'collaboration_teamwork', 'title' => 'Collaboration & Teamwork', 'text' => 'Does this manager cooperate effectively with other managers to achieve shared project goals?'],
                    ['key' => 'resource_sharing', 'title' => 'Resource Sharing', 'text' => 'Is this manager willing to share team members and resources when other teams need support?'],
                    ['key' => 'communication', 'title' => 'Communication', 'text' => 'Does this manager communicate clearly, respectfully, and professionally with fellow managers?'],
                    ['key' => 'coordination', 'title' => 'Coordination', 'text' => 'Does this manager coordinate effectively with other managers to ensure smooth project execution?'],
                    ['key' => 'problem_solving', 'title' => 'Problem Solving', 'text' => 'Does this manager actively help resolve issues and disagreements between teams?'],
                    ['key' => 'reliability_accountability', 'title' => 'Reliability & Accountability', 'text' => 'Does this manager fulfill commitments made to other managers and take responsibility when issues arise?'],
                ],
            ],
        ];
    }

    public static function questionsFor(string $kind): array
    {
        return self::all()[$kind]['questions'] ?? [];
    }

    public static function keysFor(string $kind): array
    {
        return array_column(self::questionsFor($kind), 'key');
    }

    /** question key => area title, for one kind (used to name weak areas). */
    public static function titlesFor(string $kind): array
    {
        return array_column(self::questionsFor($kind), 'title', 'key');
    }

    public static function isKind(string $kind): bool
    {
        return array_key_exists($kind, self::all());
    }
}
