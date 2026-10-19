<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The "trash" for deleted people — admin only (mounted in the role:2 group).
 *
 * Deleting an employee / customer only soft-deletes the account (users.deleted_at),
 * so nothing is lost. This lists those accounts and lets an admin bring one back,
 * instead of clearing deleted_at by hand in the database.
 *
 *   GET  /admin/trashed-users?kind=employee|customer|member&search=
 *   POST /admin/trashed-users/{id}/restore
 *
 * Restoring only clears deleted_at: the account, its history (work sessions,
 * payslips, ratings, tasks) and its password are exactly as they were. Email,
 * username and phone are unique across ALL rows including deleted ones, so a
 * restored account can never collide with a live one.
 */
class TrashedUserController extends Controller
{
    /** kind => users.user_role */
    private const KINDS = ['employee' => 3, 'customer' => 4, 'member' => 5];

    public function index(Request $request)
    {
        $kind = $request->input('kind', 'employee');
        $role = self::KINDS[$kind] ?? null;

        if ($role === null) {
            return response()->json(['message' => 'Unknown kind.'], 422);
        }

        $rows = User::onlyTrashed()
            ->where('user_role', $role)
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = '%' . trim((string) $request->input('search')) . '%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderByDesc('deleted_at')
            ->limit(300)
            ->get(['id', 'name', 'email', 'phone', 'profile_pic', 'user_role', 'employee_type', 'employee_team', 'deleted_at']);

        return response()->json(['data' => $rows]);
    }

    public function restore($id)
    {
        $user = User::onlyTrashed()->whereIn('user_role', array_values(self::KINDS))->findOrFail($id);

        $user->restore();

        return response()->json([
            'message' => $user->name . ' has been restored.',
            'data'    => $user->only(['id', 'name', 'email']),
        ]);
    }
}
