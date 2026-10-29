<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * The company's notice-period rules, in one place.
 *
 *  - Termination (HR issues an official termination email): 3 calendar days from the day it is issued, for everyone.
 *  - Resignation: Managers and Executives 1 month, everyone else 10 days (either party).
 *  - Promotion: a promoted employee follows the terms of their NEW position from its effective date, so the notice
 *    period is always worked out from the person's designation (employee_type) AT THE TIME the notice is given.
 *  - Any other reason (contract ended, other): no fixed notice period.
 *  - Leave during the notice period (resignation or termination) is not paid: see Offboarding::noticeWindow() and
 *    PayrollGenerator::unpaidLeave().
 *
 * The notice period is a rule of thumb for the screen and for payroll; the dates themselves can always be changed
 * (the policy says any change to the notice period must be agreed by both parties).
 */
class NoticePolicy
{
    /** Reasons that start a notice period at all. */
    public const NOTICE_REASONS = ['Terminated', 'Resigned'];

    /** Designations on the "managers and executives" notice period. */
    public const SENIOR_TYPES = ['Manager', 'Executive'];

    public static function hasNotice(?string $reason): bool
    {
        return in_array($reason, self::NOTICE_REASONS, true);
    }

    public static function isSenior(?string $employeeType): bool
    {
        return in_array($employeeType, self::SENIOR_TYPES, true);
    }

    /**
     * @return array{applies:bool,days:?int,months:?int,label:string,basis:string}
     */
    public static function rule(?string $employeeType, ?string $reason): array
    {
        if ($reason === 'Terminated') {
            return ['applies' => true, 'days' => 3, 'months' => null, 'label' => '3 calendar days', 'basis' => 'Termination policy: 3 calendar days from the date the termination email is issued'];
        }
        if ($reason === 'Resigned') {
            return self::isSenior($employeeType)
                ? ['applies' => true, 'days' => null, 'months' => 1, 'label' => '1 month', 'basis' => 'Notice period policy: managers and executives give 1 month']
                : ['applies' => true, 'days' => 10, 'months' => null, 'label' => '10 days', 'basis' => 'Notice period policy: lower-level team members give 10 days'];
        }

        return ['applies' => false, 'days' => null, 'months' => null, 'label' => 'No fixed notice period', 'basis' => 'No notice period policy applies to this reason'];
    }

    /** The last working day the policy points to, counted from the day the notice is given. */
    public static function suggestedLastDay(?string $employeeType, ?string $reason, $noticeDate): ?Carbon
    {
        $r = self::rule($employeeType, $reason);
        if (!$r['applies']) {
            return null;
        }
        $d = Carbon::parse($noticeDate)->startOfDay();

        return $r['months'] ? $d->copy()->addMonthsNoOverflow($r['months']) : $d->copy()->addDays((int) $r['days']);
    }
}
