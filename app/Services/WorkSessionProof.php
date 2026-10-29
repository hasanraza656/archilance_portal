<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkSession;
use App\Support\PayrollSchema;
use Illuminate\Http\UploadedFile;

/**
 * The attachment that can accompany a manual time entry (proof of the work).
 *
 * The file is stored in OneDrive like other portal attachments; the work session only remembers where it is, its
 * original name, type and size. Until the `work_session_proof` database update has been applied nothing is stored
 * (and manual time still works exactly as before).
 */
class WorkSessionProof
{
    public const MAX_KB = 15360;

    public static function rules(): array
    {
        return ['proof_pdf' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp,gif,doc,docx,xls,xlsx,csv,txt|max:' . (int) floor(self::maxBytes() / 1024)];
    }

    /** "8M" / "128K" / "1G" (php.ini shorthand) as bytes; 0 when unlimited or unreadable. */
    private static function iniBytes($value): int
    {
        $v = trim((string) $value);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }

        return (int) $n;
    }

    /**
     * The largest attachment this server will actually accept: our own 15 MB cap, or less when PHP's
     * upload_max_filesize / post_max_size are smaller (a larger upload is rejected with "413 Content Too Large"
     * before the portal even sees it). A little room is left for the other form fields.
     */
    public static function maxBytes(): int
    {
        $limits = [self::MAX_KB * 1024];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $b = self::iniBytes(ini_get($key));
            if ($b > 0) {
                $limits[] = $b;
            }
        }

        return max(0, min($limits) - 256 * 1024);
    }

    public static function ready(): bool
    {
        return PayrollSchema::has('work_sessions', 'proof_path');
    }

    /** The uploaded file of a request, whichever of the accepted field names it came in on. */
    public static function fileFrom($request): ?UploadedFile
    {
        foreach (['proof_pdf', 'proof', 'attachment'] as $key) {
            if ($request->hasFile($key)) {
                return $request->file($key);
            }
        }

        return null;
    }

    /**
     * Uploads the file and returns the columns to save on the session, or null when there is nothing to store
     * (no file, or the database update has not been applied yet).
     *
     * @throws \Throwable when the upload itself fails, so the caller can refuse the whole entry instead of losing the file.
     */
    public function upload(?UploadedFile $file, int $userId): ?array
    {
        if (!$file || !self::ready()) {
            return null;
        }

        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $file->getClientOriginalName()) ?: 'attachment';
        $path = 'work_session_proofs/' . $userId . '/' . uniqid() . '_' . $name;

        app(OneDriveService::class)->upload($path, file_get_contents($file->getRealPath()));

        return [
            'proof_path' => $path,
            'proof_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'proof_type' => mb_substr((string) $file->getClientMimeType(), 0, 120),
            'proof_size' => (int) $file->getSize(),
        ];
    }

    /** Who may download it: the person the time belongs to, admins, and managers / supervisors / executives. */
    public static function canView(User $user, WorkSession $session): bool
    {
        if ((int) $session->user_id === (int) $user->id) {
            return true;
        }
        if (in_array((int) $user->user_role, [1, 2, 6, 7], true)) {
            return true;
        }

        return (int) $user->user_role === 3 && in_array($user->employee_type, ['Manager', 'Supervisor', 'Executive'], true);
    }
}
