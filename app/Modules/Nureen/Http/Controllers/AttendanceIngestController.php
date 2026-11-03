<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Nureen\Models\AttendanceIngestQueueEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a Power Automate flow (or anything else holding the shared key)
 * posts attendance records. Deliberately thin: this only checks the key and
 * the JSON shape and stages a queue row each -- PushEndpointSource is what
 * actually ingests them, on the same schedule and through the same
 * AttendanceIngestor as every other source.
 *
 * Needs a CSRF exemption in bootstrap/app.php to accept a real external
 * POST -- see SHARED_CHANGES.md. Until that lands this correctly 419s an
 * outside caller, which is exactly why it is one of three sources rather
 * than the only one: InboxFolderSource and SimulatedSource work fully
 * without it, and this endpoint is still completely covered by
 * AttendanceIngestEndpointTest (Laravel's test client does not enforce
 * CSRF, the same as every other POST test in this suite).
 */
class AttendanceIngestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $key = $request->header('X-Api-Key');
        $expected = (string) config('nureen.attendance.ingest_key');

        if ($expected === '' || ! $key || ! hash_equals($expected, (string) $key)) {
            return response()->json(['message' => 'Invalid or missing API key.'], 401);
        }

        $data = $request->validate([
            'records' => ['required', 'array', 'min:1'],
            'records.*.matric_no' => ['required', 'string'],
            'records.*.period_end' => ['required'],
            'records.*.sessions_attended' => ['required'],
            'records.*.sessions_total' => ['required'],
        ]);

        foreach ($data['records'] as $record) {
            AttendanceIngestQueueEntry::create([
                'payload' => $record,
                'received_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Staged for the next sync.',
            'staged' => count($data['records']),
        ], 202);
    }
}
