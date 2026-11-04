<?php

namespace App\Modules\Hani\Support\Similarity;

/**
 * Where the similarity figures for a softbound thesis come from. Bound in
 * Hani\ModuleProvider from config('hani.similarity.source'):
 *
 *   uploaded      UploadedReportSource (default): reads the Turnitin report
 *                 PDF the student downloaded from ULearn
 *   turnitin_api  TurnitinApiSource: a documented stub until UTP licenses API
 *                 access
 *   simulated     SimulatedSource: deterministic made-up figures for demos,
 *                 always labelled as simulated
 */
interface SimilaritySource
{
    /** A short name stored with each reading: uploaded | turnitin_api | simulated. */
    public function name(): string;

    /** Never throws: a failure is a reading with ok = false. */
    public function read(string $reportBytes): SimilarityReading;
}
