<?php

namespace App\Modules\Nureen\Database\Seeders\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Nureen\Models\GaCertificationDetail;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Supporting documents for DEMO applications only: a small, valid, one-page
 * PDF that says it is demo data, names the document type and the student,
 * and is attached under the same doc_type the real form uses -- so the
 * approver's "System checks" read as complete and every file opens.
 *
 * Before this the Nureen seeders attached nothing, and verification runs
 * uploaded a 45-byte stand-in that no PDF viewer could open.
 */
class DemoDocuments
{
    public function __construct(protected DocumentStore $documents) {}

    /** Every document the module's own form would require for this application. */
    public function attachRequired(Application $application): void
    {
        foreach ($this->requiredTypes($application) as $docType) {
            $this->attach($application, $docType);
        }
    }

    public function attach(Application $application, string $docType): ApplicationDocument
    {
        return $this->documents->storeGenerated(
            $application,
            $this->pdf($application, $docType),
            $this->filename($application, $docType),
            $docType,
        );
    }

    public function pdf(Application $application, string $docType): string
    {
        return Pdf::loadView('nureen::demo.placeholder_document', [
            'application' => $application,
            'student' => $application->student,
            'docType' => $docType,
        ])
            ->setPaper('a4')
            ->setOption(['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])
            ->output();
    }

    /**
     * "Evidence of Work Done - Siti Hajar Rosli.pdf": the document type
     * without any "(signed by ...)" note, then the student's name, with the
     * characters a file name cannot hold replaced.
     */
    public function filename(Application $application, string $docType): string
    {
        $type = trim(preg_replace('/\s*\(.*\)\s*$/', '', $docType));
        $name = trim(($type ?: 'Document').' - '.($application->student?->name ?? 'Student'));

        return str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name).'.pdf';
    }

    /** @return array<int, string> */
    public function requiredTypes(Application $application): array
    {
        return match ($application->module_type) {
            'ga_extension' => array_values(config('nureen.ga_extension.required_documents')),
            // SupervisionController::store() attaches under this label.
            'supervision' => ['Supporting Document'],
            'ga_certification' => [config('nureen.ga_certification.supporting_document_label.'
                .(GaCertificationDetail::where('application_id', $application->id)->value('appointment_type') ?? 'GA'))],
            default => [],
        };
    }
}
