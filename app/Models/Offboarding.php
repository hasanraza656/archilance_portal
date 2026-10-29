<?php

namespace App\Models;

use App\Support\PayrollSchema;
use Illuminate\Database\Eloquent\Model;

/**
 * One person leaving. `last_working_day` is the last day they are paid for (it is also written to users.hire_end_date);
 * `removal_date` is the day their portal account is switched off (soft-deleted). The two can differ: someone can work
 * until the 15th and keep portal access for a week of handover.
 *
 * Payroll reads the last working day from here (see PayrollGenerator::lastDay), so it does not depend on the
 * account having been removed yet.
 */
class Offboarding extends Model
{
    protected $guarded = [];

    public const SCHEDULED  = 'Scheduled';
    public const COMPLETED  = 'Completed';
    public const CANCELLED  = 'Cancelled';
    public const REINSTATED = 'Reinstated';

    public const REASONS = ['Terminated', 'Resigned', 'Contract ended', 'Other'];

    protected $casts = [
        'last_working_day'       => 'date:Y-m-d',
        'removal_date'           => 'date:Y-m-d',
        'previous_hire_end_date' => 'date:Y-m-d',
        'joining_date'           => 'date:Y-m-d',
        'notice_date'            => 'date:Y-m-d',
        'completed_at'           => 'datetime',
        'cancelled_at'           => 'datetime',
    ];

    /** @var array<int, self>|null */
    private static ?array $live = null;

    public static function ready(): bool
    {
        return PayrollSchema::table('offboardings');
    }

    /** The in-force offboarding (Scheduled or Completed) per user id, for the whole request. */
    public static function live(): array
    {
        if (self::$live === null) {
            self::$live = [];
            if (self::ready()) {
                try {
                    foreach (self::whereIn('status', [self::SCHEDULED, self::COMPLETED])->orderBy('id')->get() as $o) {
                        self::$live[(int) $o->user_id] = $o;
                    }
                } catch (\Throwable $e) {
                    self::$live = [];
                }
            }
        }

        return self::$live;
    }

    public static function forUser(int $userId): ?self
    {
        return self::live()[$userId] ?? null;
    }

    /**
     * The notice period of someone who resigned or was terminated, as [from, to] dates (the day notice was given up to
     * and including the last working day); null when no notice period applies. Leave taken inside it is unpaid.
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}|null
     */
    public static function noticeWindow(int $userId): ?array
    {
        $o = self::forUser($userId);
        if (!$o || !\App\Support\NoticePolicy::hasNotice($o->reason_type) || !$o->last_working_day) {
            return null;
        }

        $from = $o->notice_date ?: ($o->created_at ? $o->created_at->copy() : null);
        if (!$from) {
            return null;
        }
        $from = $from->copy()->startOfDay();
        $to = $o->last_working_day->copy()->startOfDay();

        return $from->lte($to) ? [$from, $to] : null;
    }

    public static function flush(): void
    {
        self::$live = null;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by')->withTrashed();
    }

    public function logs()
    {
        return $this->hasMany(OffboardingLog::class, 'offboarding_id')->orderByDesc('id');
    }
}
