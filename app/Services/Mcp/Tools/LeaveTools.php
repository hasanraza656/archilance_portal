<?php

namespace App\Services\Mcp\Tools;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Mcp\Present;
use App\Services\Mcp\S;
use App\Services\Mcp\Tool;
use App\Services\Mcp\ToolContext;
use App\Services\Mcp\ToolError;
use Carbon\Carbon;

/** Leave requests: see them, approve / reject, record leave on someone's behalf. */
final class LeaveTools
{
    public const TYPES = ['sick', 'casual', 'annual', 'marriage', 'unpaid', 'additional', 'maternity', 'paternity'];

    /** @return Tool[] */
    public static function all(): array
    {
        return [self::listLeaves(), self::getLeave(), self::decideLeave(), self::recordLeave()];
    }

    private static function weekdays(?string $from, ?string $to): int
    {
        if (!$from || !$to) {
            return 0;
        }
        try {
            $d = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->startOfDay();
        } catch (\Throwable $e) {
            return 0;
        }
        $n = 0;
        for ($i = 0; $d->lte($end) && $i < 400; $i++, $d->addDay()) {
            if (!$d->isWeekend()) {
                $n++;
            }
        }

        return $n;
    }

    private static function row(array $l): array
    {
        $start = isset($l['start_date']) ? substr((string) $l['start_date'], 0, 10) : null;
        $end = isset($l['end_date']) ? substr((string) $l['end_date'], 0, 10) : null;

        return Present::clean([
            'id' => $l['id'] ?? null,
            'employee' => Present::person($l['user'] ?? null),
            'reports_to' => Present::person($l['user']['manager'] ?? null),
            'type' => $l['leave_type'] ?? null,
            'status' => $l['status'] ?? null,
            'from' => $start,
            'to' => $end,
            'working_days' => self::weekdays($start, $end),
            'reason' => $l['reason'] ?? null,
            'reviewed_by' => Present::person($l['approver'] ?? null),
            'reviewed_at' => $l['reviewed_at'] ?? null,
            'requested_at' => $l['created_at'] ?? null,
            'policy_override' => !empty($l['policy_override']) ? true : null,
        ]);
    }

    private static function listLeaves(): Tool
    {
        return new Tool(
            'list_leave_requests',
            'List leave requests',
            'List employees\' leave requests, newest first, with counts by status. Defaults to showing everything; pass status "Pending" to see what needs a decision. Filter by employee, type, dates (any leave touching the window) or a name search.',
            [
                'status' => S::enum('Only this status.', ['Pending', 'Approved', 'Rejected']),
                'employee_id' => S::int('Only this employee (user id).'),
                'leave_type' => S::enum('Only this leave type.', self::TYPES),
                'from' => S::date('Leave overlapping on/after this date'),
                'to' => S::date('Leave overlapping on/before this date'),
                'search' => S::str('Employee name / e-mail.', 100),
                'limit' => S::limit(30, 100),
            ],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $limit = $c->limit($a, 30, 100);
                $r = $c->api->must($c->api->get('admin/leave-request', array_filter([
                    'status' => $a['status'] ?? null,
                    'user_id' => $a['employee_id'] ?? null,
                    'leave_type' => $a['leave_type'] ?? null,
                    'from' => $a['from'] ?? null,
                    'to' => $a['to'] ?? null,
                    'search' => $a['search'] ?? null,
                    'per_page' => $limit,
                    'page' => 1,
                ])));

                return ['counts' => $r['counts'] ?? null] + Present::page(array_map(fn ($l) => self::row($l), (array) ($r['data'] ?? [])), $limit, $r['total'] ?? null);
            }
        );
    }

    private static function getLeave(): Tool
    {
        return new Tool(
            'get_leave_request',
            'Get a leave request',
            'One leave request with the employee\'s leave balances for the current leave cycle (sick / casual / annual / other used) and what the leave policy says they have left. Look at this before approving.',
            ['leave_id' => S::int('Leave request id.', 1)],
            ['leave_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $r = $c->api->must($c->api->get('admin/leave-request/' . $a['leave_id']));

                return Present::clean([
                    'request' => self::row((array) ($r['data'] ?? [])),
                    'leave_cycle' => $r['cycle'] ?? null,
                    'used_this_cycle_working_days' => $r['leave_summary'] ?? null,
                    'policy_balances' => $r['policy'] ?? null,
                ]);
            }
        );
    }

    private static function decideLeave(): Tool
    {
        return new Tool(
            'decide_leave_request',
            'Approve or reject leave',
            'Approve or reject ONE leave request. It is recorded as reviewed by the signed-in admin. Works on a Pending request; if the request was already decided, say so to the user before changing it.',
            [
                'leave_id' => S::int('Leave request id.', 1),
                'decision' => S::enum('approve or reject.', ['approve', 'reject']),
            ],
            ['leave_id', 'decision'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                $l = LeaveRequest::find($a['leave_id']);
                if (!$l) {
                    throw new ToolError('Leave request not found.');
                }
                $new = $a['decision'] === 'approve' ? 'Approved' : 'Rejected';
                $before = $l->status;
                if ($before === $new) {
                    return ['changed' => false, 'message' => "Leave request #{$l->id} is already {$new}."];
                }
                $c->api->must($c->api->put('admin/leave-request/' . $l->id, ['status' => $new]));
                $emp = User::withTrashed()->find($l->user_id);

                return ['changed' => true, 'leave_id' => (int) $l->id, 'employee' => $emp ? ['id' => (int) $emp->id, 'name' => $emp->name] : null, 'status_before' => $before, 'status_now' => $new, 'from' => substr((string) $l->start_date, 0, 10), 'to' => substr((string) $l->end_date, 0, 10)];
            }
        );
    }

    private static function recordLeave(): Tool
    {
        return new Tool(
            'record_leave_for_employee',
            'Record leave for an employee',
            'Record leave on an employee\'s behalf (status Pending by default; pass Approved to record it already approved). The leave policy is checked: if it is breached the portal refuses and says why - tell the user, and only retry with override=true plus override_reason if they explicitly want an exception.',
            [
                'employee_id' => S::int('Employee user id.', 1),
                'leave_type' => S::enum('Leave type.', self::TYPES),
                'start_date' => S::date('First day'),
                'end_date' => S::date('Last day'),
                'reason' => S::str('Reason.', 1000),
                'status' => S::enum('Pending (default), Approved or Rejected.', ['Pending', 'Approved', 'Rejected']),
                'override' => S::bool('Record even though it breaks the leave policy (admin exception).'),
                'override_reason' => S::str('Why the exception is made (kept in the audit trail).', 500),
            ],
            ['employee_id', 'leave_type', 'start_date', 'end_date'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                if ($a['end_date'] < $a['start_date']) {
                    throw new ToolError('end_date is before start_date.');
                }
                $r = $c->api->must($c->api->post('admin/leave-request', array_filter([
                    'user_id' => $a['employee_id'],
                    'leave_type' => $a['leave_type'],
                    'start_date' => $a['start_date'],
                    'end_date' => $a['end_date'],
                    'reason' => $a['reason'] ?? null,
                    'status' => $a['status'] ?? null,
                    'override' => !empty($a['override']) ? true : null,
                    'override_reason' => $a['override_reason'] ?? null,
                ], fn ($v) => $v !== null)));

                return ['recorded' => true, 'request' => self::row((array) ($r['data'] ?? [])), 'policy_override_used' => (bool) ($r['override'] ?? false)];
            }
        );
    }
}
