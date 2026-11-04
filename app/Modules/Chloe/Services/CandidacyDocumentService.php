<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Appeal documents: the official form (.xlsx, required) and up to four supporting files (xlsx, pdf, docx), 10 MB each.
 * A file is accepted only when its extension AND its real content agree (a PDF must start with %PDF, an xlsx / docx must
 * be a zip with the matching parts). Stored under a generated name on the private disk; the original name is only a label.
 */
class CandidacyDocumentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_SUPPORTING = 4;

    public const MIME = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf' => 'application/pdf',
    ];

    public function store(CandidacyAppeal $appeal, UploadedFile $file, string $kind): CandidacyAppealDocument
    {
        $field = $kind === CandidacyAppealDocument::KIND_OFFICIAL_FORM ? 'official_form' : 'supporting';
        $allowed = $kind === CandidacyAppealDocument::KIND_OFFICIAL_FORM ? ['xlsx'] : ['xlsx', 'pdf', 'docx'];

        if (! in_array($appeal->status, [CandidacyAppeal::STATUS_DRAFT, CandidacyAppeal::STATUS_RETURNED], true)) {
            throw ValidationException::withMessages([$field => 'Documents can only be changed on a draft or a returned appeal.']);
        }

        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => 'Each file must be at most 10 MB.']);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $bytes = (string) file_get_contents($file->getRealPath());

        if (! in_array($ext, $allowed, true) || ! $this->contentMatches($ext, $file->getRealPath(), $bytes)) {
            throw ValidationException::withMessages([$field => $kind === CandidacyAppealDocument::KIND_OFFICIAL_FORM
                ? 'The official appeal form must be an Excel (.xlsx) file.'
                : 'Supporting documents must be Excel (.xlsx), PDF or Word (.docx) files, and the content must match the file type.']);
        }

        if ($kind === CandidacyAppealDocument::KIND_SUPPORTING
            && $appeal->documents()->where('kind', $kind)->where('round_uploaded', $this->roundFor($appeal))->count() >= self::MAX_SUPPORTING) {
            throw ValidationException::withMessages([$field => 'You can add at most '.self::MAX_SUPPORTING.' supporting documents.']);
        }

        $path = 'candidacy/appeals/'.$appeal->id.'/'.Str::random(32).'.'.$ext;
        Storage::disk('local')->put($path, $bytes);

        return CandidacyAppealDocument::create([
            'candidacy_appeal_id' => $appeal->id, 'kind' => $kind, 'original_name' => $this->label($file->getClientOriginalName()),
            'stored_path' => $path, 'mime' => self::MIME[$ext], 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'round_uploaded' => $this->roundFor($appeal), 'uploaded_at' => now(),
        ]);
    }

    /** A student may remove only what they added in the current editing round (earlier rounds stay as evidence). */
    public function remove(CandidacyAppeal $appeal, CandidacyAppealDocument $document): void
    {
        if ($document->candidacy_appeal_id !== $appeal->id || $document->round_uploaded !== $this->roundFor($appeal)
            || ! in_array($appeal->status, [CandidacyAppeal::STATUS_DRAFT, CandidacyAppeal::STATUS_RETURNED], true)) {
            throw ValidationException::withMessages(['documents' => 'This document can no longer be removed.']);
        }

        Storage::disk('local')->delete($document->stored_path);
        $document->delete();
    }

    /** The round a document uploaded now belongs to: the current one on a draft, the next one on a returned appeal. */
    public function roundFor(CandidacyAppeal $appeal): int
    {
        return $appeal->status === CandidacyAppeal::STATUS_RETURNED ? $appeal->round + 1 : $appeal->round;
    }

    protected function contentMatches(string $ext, string $path, string $bytes): bool
    {
        if ($ext === 'pdf') {
            return str_starts_with($bytes, '%PDF-');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        $part = $ext === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml';
        $ok = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($part) !== false;
        $zip->close();

        return $ok;
    }

    protected function label(string $name): string
    {
        return Str::limit(trim(preg_replace('/[\x00-\x1f\/\\\\]+/u', ' ', $name)) ?: 'document', 200, '');
    }
}
