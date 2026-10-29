<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service provider / support company that is paid every month but has no portal account (e.g. a firm that
 * supports Archilance). They go through the same salary profiles and payroll runs as employees.
 *
 * The salary and payslip tables are keyed by user_id, so a provider is addressed there by a "virtual user id":
 * BASE + its own id. The base is far above any real user id, so the two can never collide, and nothing that
 * looks up users by id will ever find a provider by accident.
 */
class PayrollPayee extends Model
{
    use SoftDeletes;

    public const BASE = 1000000000;

    protected $guarded = [];

    public static function isVirtual($userId): bool
    {
        return (int) $userId >= self::BASE;
    }

    public static function idFromVirtual($userId): int
    {
        return (int) $userId - self::BASE;
    }

    public function virtualId(): int
    {
        return self::BASE + (int) $this->id;
    }

    /** Active providers by virtual id. */
    public static function keyedByVirtualId(array $virtualIds)
    {
        $ids = array_map([self::class, 'idFromVirtual'], array_filter($virtualIds, [self::class, 'isVirtual']));
        if (!$ids) {
            return collect();
        }

        return self::withTrashed()->whereIn('id', $ids)->get()->keyBy(fn ($p) => $p->virtualId());
    }

    /**
     * What the payslip generator reads from a person (name, email, start date, title, team), so a provider can be
     * passed wherever an employee is expected.
     */
    public function asSubject(): object
    {
        return (object) [
            'id'            => $this->virtualId(),
            'name'          => $this->company ? ($this->name . ' (' . $this->company . ')') : $this->name,
            'email'         => $this->email,
            'joining_date'  => null,
            'employee_type' => 'Service provider',
            'employee_team' => $this->category ?: 'Service providers',
        ];
    }
}
