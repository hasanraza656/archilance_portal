<?php

namespace App\Services\Mcp\Tools;

use App\Models\PayrollRun;
use App\Services\Mcp\Present;
use App\Services\Mcp\S;
use App\Services\Mcp\Tool;
use App\Services\Mcp\ToolContext;
use App\Services\Mcp\ToolError;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll & salaries. Reading is open to the admin; every action that changes a payroll is a DANGER tool
 * (confirm=true after the user has seen what will happen). The portal's own rules still apply on top.
 */
final class PayrollTools
{
    private const RUN_ACTIONS = ['submit', 'approve', 'reopen', 'cancel', 'close', 'reopen_period', 'approve_and_pay', 'pay_all'];
    private const SLIP_ACTIONS = ['approve', 'unapprove', 'hold', 'release', 'skip', 'unskip', 'pay', 'unpay'];

    /** @return Tool[] */
    public static function all(): array
    {
        return [self::runs(), self::runDetail(), self::report(), self::salaries(), self::generate(), self::runAction(), self::slipAction()];
    }

    private static function tablesReady(): void
    {
        if (!Schema::hasTable('payroll_runs')) {
            throw new ToolError('Payroll is not set up on this server yet.');
        }
    }

    private static function runs(): Tool
    {
        return new Tool(
            'payroll_runs',
            'List payroll runs',
            'The monthly payroll runs (latest 60) with status (Draft, Pending Approval, Approved, Paid, Closed, Cancelled) and money totals.',
            ['status' => S::str('Only runs with this status.', 30)],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                self::tablesReady();
                $r = $c->api->must($c->api->get('admin/payroll', array_filter(['status' => $a['status'] ?? null])));

                return Present::clean(['runs' => $r['data'] ?? []]);
            }
        );
    }

    private static function runDetail(): Tool
    {
        return new Tool(
            'payroll_run_detail',
            'Payroll run with payslips',
            'One payroll run with every payslip (employee, status, basic, earnings, deductions, net) and what can be done with it next.',
            ['run_id' => S::int('Payroll run id.', 1)],
            ['run_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                self::tablesReady();
                $r = $c->api->must($c->api->get('admin/payroll/' . $a['run_id']));
                $slips = array_map(fn ($s) => Present::clean([
                    'payslip_id' => $s['id'] ?? null,
                    'user_id' => $s['user_id'] ?? null,
                    'employee' => $s['employee_name'] ?? null,
                    'team' => $s['department'] ?? null,
                    'status' => $s['status'] ?? null,
                    'basic' => $s['basic_salary'] ?? null,
                    'earnings' => $s['total_earnings'] ?? null,
                    'deductions' => $s['total_deductions'] ?? null,
                    'net' => $s['net_amount'] ?? null,
                    'prorated' => !empty($s['is_prorated']) ? true : null,
                    'paid_at' => $s['paid_at'] ?? null,
                    'payment_method' => $s['payment_method'] ?? null,
                    'reason' => $s['status_reason'] ?? null,
                ]), (array) ($r['payslips'] ?? []));

                return Present::clean([
                    'run' => $r['data'] ?? null,
                    'can_edit' => $r['editable'] ?? null,
                    'can_pay' => $r['payable'] ?? null,
                    'closed' => $r['closed'] ?? null,
                    'payslips' => $slips,
                ]);
            }
        );
    }

    private static function report(): Tool
    {
        return new Tool(
            'payroll_report',
            'Payroll report',
            'Finance roll-ups for a period: totals by month, team, employee, payment method, and what is still unpaid. Months are given as dates (any day in the month).',
            [
                'from' => S::date('First month (default: start of this year)'),
                'to' => S::date('Last month (default: this month)'),
                'status' => S::str('Payslip status filter.', 30),
                'user_id' => S::int('One employee.'),
                'team' => S::str('One team.', 60),
            ],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                self::tablesReady();
                $r = $c->api->must($c->api->get('admin/payroll-report', array_filter([
                    'from' => $a['from'] ?? null, 'to' => $a['to'] ?? null, 'status' => $a['status'] ?? null,
                    'user_id' => $a['user_id'] ?? null, 'team' => $a['team'] ?? null,
                ])));
                unset($r['rows'], $r['register'], $r['payslips']); // the long register: ask payroll_run_detail for lines

                return Present::clean($r);
            }
        );
    }

    private static function salaries(): Tool
    {
        return new Tool(
            'salary_overview',
            'Employee salaries',
            'Every employee\'s salary profile, lifetime paid, and this month\'s payslip status. SENSITIVE - only use when the user asks about pay.',
            [
                'month' => S::date('Month to show payslip status for (default this month)'),
                'search' => S::str('Name / e-mail.', 100),
                'employee_team' => S::str('One team.', 60),
                'employee_type' => S::str('One employee type.', 40),
            ],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                self::tablesReady();
                $r = $c->api->must($c->api->get('admin/salary', array_filter([
                    'month' => $a['month'] ?? null, 'search' => $a['search'] ?? null,
                    'employee_team' => $a['employee_team'] ?? null, 'employee_type' => $a['employee_type'] ?? null,
                ])));

                return Present::clean($r);
            }
        );
    }

    private static function generate(): Tool
    {
        return new Tool(
            'payroll_generate',
            'Generate a month\'s payroll',
            'Build a DRAFT payroll run for a month from every active salary profile (new joiners prorated, standing items added). It is refused if that month already has a live run. A draft pays nobody until it is approved and paid.',
            [
                'month' => S::date('Any date in the month to run, e.g. 2026-11-01'),
                'notes' => S::str('Optional note for the run.', 2000),
                'prorate' => S::bool('Prorate people who joined mid-month (default true).'),
            ],
            ['month'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                self::tablesReady();
                $r = $c->api->must($c->api->post('admin/payroll/generate', array_filter([
                    'month' => $a['month'], 'notes' => $a['notes'] ?? null, 'prorate' => $a['prorate'] ?? null,
                ], fn ($v) => $v !== null)));

                return Present::clean(['generated' => true, 'message' => $r['message'] ?? null, 'run' => $r['data'] ?? null]);
            }
        );
    }

    private static function runAction(): Tool
    {
        return new Tool(
            'payroll_run_action',
            'Move a payroll run forward / back',
            'submit = Draft to Pending Approval. approve = approve the run and its payslips. reopen = send an approved run back to Draft (only if nobody is paid). cancel = cancel the run. approve_and_pay = approve and mark every payable payslip PAID. pay_all = mark every outstanding payslip PAID. close = lock a fully paid month. reopen_period = unlock a closed month (needs a reason). All need confirm=true.',
            [
                'run_id' => S::int('Payroll run id.', 1),
                'action' => S::enum('What to do.', self::RUN_ACTIONS),
                'reason' => S::str('Required for reopen_period.', 500),
                'payment_method' => S::str('For pay actions: e.g. Bank transfer.', 120),
                'payment_reference' => S::str('For pay actions: reference / transaction id.', 190),
            ],
            ['run_id', 'action'],
            Tool::DANGER,
            function (array $a, ToolContext $c) {
                self::tablesReady();
                if ($a['action'] === 'reopen_period' && empty($a['reason'])) {
                    throw new ToolError("'reason' is required for reopen_period.");
                }
                $path = 'admin/payroll/' . $a['run_id'] . '/' . str_replace('_', '-', $a['action']);
                $body = array_filter(['reason' => $a['reason'] ?? null, 'payment_method' => $a['payment_method'] ?? null, 'payment_reference' => $a['payment_reference'] ?? null], fn ($v) => $v !== null);
                $r = $c->api->must($c->api->post($path, $body));

                return Present::clean(['done' => true, 'message' => $r['message'] ?? null, 'run' => $r['data'] ?? null, 'payslips_done' => $r['done'] ?? null, 'payslips_skipped' => $r['ignored'] ?? null]);
            },
            function (array $a, ToolContext $c) {
                self::tablesReady();
                $run = PayrollRun::find($a['run_id']);
                if (!$run) {
                    throw new ToolError('Payroll run not found.');
                }
                $money = in_array($a['action'], ['approve_and_pay', 'pay_all'], true) ? ' This RECORDS PAYMENTS for payslips in the run.' : '';
                $label = $run->period_month ? substr((string) $run->period_month, 0, 7) : '#' . $run->id;

                return "Payroll run {$label} (id {$run->id}) is currently \"{$run->status}\". Action: {$a['action']}.{$money} The portal's payroll rules still apply and every step is written to the payroll audit trail.";
            }
        );
    }

    private static function slipAction(): Tool
    {
        return new Tool(
            'payslip_action',
            'Change one payslip\'s status',
            'approve / unapprove / hold / release / skip / unskip / pay / unpay for ONE payslip (use payroll_run_detail to find ids). pay and unpay record or reverse a payment. Needs confirm=true.',
            [
                'payslip_id' => S::int('Payslip id.', 1),
                'action' => S::enum('What to do.', self::SLIP_ACTIONS),
                'reason' => S::str('For hold / skip: why.', 255),
                'payment_method' => S::str('For pay: method.', 120),
                'payment_reference' => S::str('For pay: reference.', 190),
            ],
            ['payslip_id', 'action'],
            Tool::DANGER,
            function (array $a, ToolContext $c) {
                self::tablesReady();
                $r = $c->api->must($c->api->post('admin/payslip/' . $a['payslip_id'] . '/status', array_filter([
                    'action' => $a['action'], 'reason' => $a['reason'] ?? null,
                    'payment_method' => $a['payment_method'] ?? null, 'payment_reference' => $a['payment_reference'] ?? null,
                ], fn ($v) => $v !== null)));

                return Present::clean(['done' => true, 'message' => $r['message'] ?? null, 'payslip' => $r['data'] ?? null]);
            },
            function (array $a, ToolContext $c) {
                self::tablesReady();
                $slip = \App\Models\Payslip::find($a['payslip_id']);
                if (!$slip) {
                    throw new ToolError('Payslip not found.');
                }

                return "Payslip #{$slip->id} for {$slip->employee_name} (net {$slip->net_amount}, status \"{$slip->status}\"): {$a['action']}." . (in_array($a['action'], ['pay', 'unpay'], true) ? ' This records / reverses a payment.' : '');
            }
        );
    }
}
