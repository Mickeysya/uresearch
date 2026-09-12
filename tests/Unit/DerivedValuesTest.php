<?php

namespace Tests\Unit;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\StudentDashboard;
use Tests\TestCase;

/**
 * The two rules the portal derives rather than stores, both of which are read
 * in more than one place and so must not drift.
 *
 * No database is touched -- these are pure functions of their inputs, which
 * is the whole reason they were written as derived values. The framework is
 * still booted (Tests\TestCase rather than bare PHPUnit) because Eloquent
 * needs a connection resolver to apply the `submitted_at` date cast, even
 * when nothing is ever queried.
 */
class DerivedValuesTest extends TestCase
{
    /**
     * Application::reference() builds a human reference from the module key
     * and the id, so there is no column to keep in sync. Two letters per word
     * of the key, capped at four -- but a single-word key only yields two
     * that way, so it falls back to the first four of the key itself.
     */
    public function test_reference_abbreviates_multi_word_and_single_word_keys(): void
    {
        $this->assertSame('GAEX-2026-00012', $this->reference('ga_extension', 12));
        $this->assertSame('ATAP-2026-00007', $this->reference('attendance_appeal', 7));

        // TRAV, not TR -- the single-word fallback.
        $this->assertSame('TRAV-2026-00001', $this->reference('travel', 1));

        // Three words still cap at four characters.
        $this->assertSame('GACE-2026-00123', $this->reference('ga_certification', 123));
    }

    public function test_reference_pads_the_id_to_five_digits(): void
    {
        $this->assertSame('TRAV-2026-00001', $this->reference('travel', 1));
        $this->assertSame('TRAV-2026-99999', $this->reference('travel', 99999));
    }

    /**
     * The attendance bands are shared by the student's gauge, its legend and
     * the CGS alert counts. If toneFor() and attendanceBands() ever disagree,
     * a student reading "Good" would be counted as Warning by CGS.
     */
    public function test_attendance_tones_match_their_band_boundaries(): void
    {
        $this->assertSame('good', StudentDashboard::toneFor(100.0));
        $this->assertSame('good', StudentDashboard::toneFor(85.0));   // on the edge
        $this->assertSame('warn', StudentDashboard::toneFor(84.9));
        $this->assertSame('warn', StudentDashboard::toneFor(75.0));   // on the edge
        $this->assertSame('critical', StudentDashboard::toneFor(74.9));
        $this->assertSame('critical', StudentDashboard::toneFor(0.0));

        // No record is not the same as a bad record.
        $this->assertSame('none', StudentDashboard::toneFor(null));
    }

    public function test_every_band_boundary_resolves_to_that_bands_tone(): void
    {
        foreach (StudentDashboard::attendanceBands() as $band) {
            $this->assertSame(
                $band['tone'],
                StudentDashboard::toneFor($band['from']),
                "A percentage exactly on the '{$band['label']}' boundary must read as that band."
            );
        }
    }

    private function reference(string $moduleType, int $id): string
    {
        $application = new Application(['module_type' => $moduleType]);
        $application->id = $id;
        $application->submitted_at = '2026-06-01 00:00:00';

        return $application->reference();
    }
}
