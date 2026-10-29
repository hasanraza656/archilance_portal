<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ExperienceLetter;
use App\Services\ExperienceLetterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Experience letters (certificates). Available to Admins (user_role 2) and Executives (user_role 3 + employee_type
 * 'Executive'), like the Contracts module: both use the same fixed /api/experience-letters* paths, so the check is
 * done here rather than by route middleware.
 *
 * Until the "experience_letters" database update has been applied every endpoint answers 503 with a clear message,
 * instead of failing - uploading this code before running the update changes nothing else in the portal.
 */
class ExperienceLetterController extends Controller
{
    public function __construct(private ExperienceLetterService $letters)
    {
    }

    private function actor()
    {
        $u = Auth::user();
        $isAdmin = (int) ($u->user_role ?? 0) === 2;
        $isExecutive = (int) ($u->user_role ?? 0) === 3 && ($u->employee_type ?? null) === 'Executive';
        if (!$isAdmin && !$isExecutive) {
            abort(403, 'Only admins and executives can manage experience letters.');
        }

        return $u;
    }

    private function notReady()
    {
        if (ExperienceLetterService::ready()) {
            return null;
        }

        return response()->json([
            'message' => 'Experience letters need a one-time database update. Open System settings and press "Update database".',
            'pending_update' => true,
        ], 503);
    }

    /**
     * GET /api/experience-letters/public/{token} - NO login. What the QR on a letter opens: the genuine letter, so anyone can
     * check it. Access is only by the unguessable token. The list of counted projects (client job names) is never exposed.
     */
    public function publicShow($token)
    {
        if (!ExperienceLetterService::ready() || !preg_match('/^[A-Za-z0-9]{20,64}$/', (string) $token)
            || !\Illuminate\Support\Facades\Schema::hasColumn('experience_letters', 'verify_token')) {
            return response()->json(['message' => 'This letter could not be found.'], 404);
        }
        $l = ExperienceLetter::where('verify_token', $token)->first();
        if (!$l) {
            return response()->json(['message' => 'This letter could not be found.'], 404);
        }
        $data = (array) $l->data;
        unset($data['projects']);

        return response()->json([
            'verified' => true,
            'reference_no' => $l->reference_no,
            'employee_name' => $l->employee_name,
            'issue_date' => $l->issue_date?->toDateString(),
            'data' => $data,
        ]);
    }

    /** GET /api/experience-letters/config - themes, default wording, company details. */
    public function config()
    {
        $this->actor();

        return response()->json(ExperienceLetterService::config() + ['ready' => ExperienceLetterService::ready()]);
    }

    /** GET /api/experience-letters/people?search= - staff (including former employees) who can be given a letter. */
    public function people(Request $request)
    {
        $this->actor();

        return response()->json(['data' => $this->letters->people($request->query('search'), (int) $request->query('limit', 30))]);
    }

    /** GET /api/experience-letters/prefill/{userId} - values and counted projects for one person. */
    public function prefill($userId)
    {
        $this->actor();
        $out = $this->letters->prefill((int) $userId);

        return $out ? response()->json($out) : response()->json(['message' => 'Employee not found.'], 404);
    }

    /** GET /api/experience-letters - issued letters, newest first. Optional ?search= (name, reference, designation). */
    public function index(Request $request)
    {
        $this->actor();
        if ($r = $this->notReady()) {
            return $r;
        }

        $q = ExperienceLetter::with('creator:id,name')->latest('id');
        if ($request->filled('search')) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->query('search'))) . '%';
            $q->where(fn ($w) => $w->where('employee_name', 'like', $like)->orWhere('reference_no', 'like', $like)->orWhere('designation', 'like', $like));
        }
        if ($request->filled('employee_id')) {
            $q->where('employee_id', (int) $request->query('employee_id'));
        }

        $p = $q->paginate(max(1, min(50, (int) $request->query('per_page', 12))));
        $p->setCollection($p->getCollection()->map(fn ($l) => $this->letters->present($l)));

        return response()->json($p);
    }

    /** GET /api/experience-letters/{id} */
    public function show($id)
    {
        $this->actor();
        if ($r = $this->notReady()) {
            return $r;
        }
        $l = ExperienceLetter::with('creator:id,name')->find($id);

        return $l ? response()->json($this->letters->present($l)) : response()->json(['message' => 'Letter not found.'], 404);
    }

    /** POST /api/experience-letters - issue (store) a letter. Body: employee_id, values, texts, theme, projects. */
    public function store(Request $request)
    {
        $actor = $this->actor();
        if ($r = $this->notReady()) {
            return $r;
        }
        $request->validate(['employee_id' => 'required|integer', 'values' => 'required|array']);

        [$letter, $error] = $this->letters->create((int) $request->input('employee_id'), $request->only(['values', 'texts', 'theme', 'projects']), (int) $actor->id);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json($this->letters->present($letter->load('creator:id,name')), 201);
    }

    /** PUT /api/experience-letters/{id} - correct an issued letter (keeps its reference number). */
    public function update(Request $request, $id)
    {
        $this->actor();
        if ($r = $this->notReady()) {
            return $r;
        }
        $letter = ExperienceLetter::find($id);
        if (!$letter) {
            return response()->json(['message' => 'Letter not found.'], 404);
        }
        $request->validate(['values' => 'required|array']);

        [$updated, $error] = $this->letters->update($letter, $request->only(['values', 'texts', 'theme', 'projects']));
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json($this->letters->present($updated->load('creator:id,name')));
    }

    /** DELETE /api/experience-letters/{id} - admins only; a soft delete, the reference number is never reused. */
    public function destroy($id)
    {
        $u = $this->actor();
        if ((int) $u->user_role !== 2) {
            abort(403, 'Only admins can remove an issued letter.');
        }
        if ($r = $this->notReady()) {
            return $r;
        }
        $letter = ExperienceLetter::find($id);
        if (!$letter) {
            return response()->json(['message' => 'Letter not found.'], 404);
        }
        $letter->delete();

        return response()->json(['message' => 'Letter removed.']);
    }
}
