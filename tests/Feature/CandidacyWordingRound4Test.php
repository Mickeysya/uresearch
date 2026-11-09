<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Round 4 (D): the module writes "Study Candidacy" wherever the bare word "Candidacy" would be user-facing text, and
 * "Supervisor / Programme Manager" instead of "Supervisor" for the person who endorses an appeal. Route names, class names,
 * table names, config keys and command names are untouched. Source scans only; nothing here renders a page.
 */
class CandidacyWordingRound4Test extends TestCase
{
    /** @return list<string> every file under a directory with one of the extensions */
    protected function files(string $dir, array $extensions): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /** The text a person can read in a Blade file: comments, PHP, echoes, scripts and styles removed. */
    protected function visibleText(string $blade): string
    {
        $blade = preg_replace(['/\{\{--.*?--\}\}/s', '/@php.*?@endphp/s', '/<script\b.*?<\/script>/s', '/<style\b.*?<\/style>/s', '/\{!!.*?!!\}/s', '/\{\{.*?\}\}/s'], ' ', $blade);

        return $blade;
    }

    protected function bare(string $text): array
    {
        preg_match_all('/(?<!Study )(?<![\\\\\w$:.\-\/_])Candidacy\b(?!::|[\\\\\w(]|\.php)/', $text, $m, PREG_OFFSET_CAPTURE);

        return array_map(fn ($hit) => trim(substr($text, max(0, $hit[1] - 25), 60)), $m[0]);
    }

    public function test_no_candidacy_view_has_the_bare_word_candidacy_as_visible_text(): void
    {
        $bad = [];

        foreach ($this->files('app/Modules/Chloe/Resources/views/candidacy', ['php']) as $file) {
            foreach ($this->bare($this->visibleText(file_get_contents($file))) as $hit) {
                $bad[] = basename($file).': '.preg_replace('/\s+/', ' ', $hit);
            }
        }

        $this->assertSame([], $bad, "Write \"Study Candidacy\":\n".implode("\n", $bad));
    }

    public function test_the_notifications_mail_templates_and_knowledge_say_study_candidacy(): void
    {
        $bad = [];
        $sources = array_merge(
            glob(base_path('app/Modules/Chloe/Notifications/*.php')),
            [base_path('config/candidacy.php'), base_path('app/Modules/Chloe/Support/CandidacyDismissalList.php'), base_path('app/Modules/Chloe/Workflows/CandidacyAppealWorkflow.php')],
            $this->files('docs/knowledge/candidacy', ['md']),
        );

        foreach ($sources as $file) {
            foreach (file($file) as $n => $line) {
                if (preg_match('/^\s*(\*|\/\/|\/\*|#)/', $line)) {
                    continue; // comments
                }
                foreach ($this->bare($line) as $hit) {
                    $bad[] = basename($file).':'.($n + 1).': '.$hit;
                }
            }
        }

        $this->assertSame([], $bad, "Write \"Study Candidacy\":\n".implode("\n", $bad));
    }

    public function test_study_candidacy_is_never_doubled(): void
    {
        foreach (array_merge($this->files('app/Modules/Chloe', ['php']), $this->files('docs/knowledge', ['md']), [base_path('config/candidacy.php')]) as $file) {
            $this->assertStringNotContainsString('Study Study', file_get_contents($file), basename($file));
        }
    }

    public function test_the_approver_label_is_supervisor_programme_manager_everywhere_a_person_reads_it(): void
    {
        $label = 'Supervisor / Programme Manager';
        $presenter = \App\Modules\Chloe\Support\CandidacyAppealPresenter::STAGES;
        $this->assertSame($label, $presenter['supervisor']);

        $stage = collect((new \App\Modules\Chloe\Workflows\CandidacyAppealWorkflow)->stages())->firstWhere('key', 'supervisor');
        $this->assertSame($label, $stage->label);

        $bad = [];

        foreach (array_merge($this->files('app/Modules/Chloe/Resources/views/candidacy', ['php']), glob(base_path('app/Modules/Chloe/Notifications/*.php')), [base_path('config/candidacy.php')], $this->files('docs/knowledge/candidacy', ['md'])) as $file) {
            $text = str_ends_with($file, '.blade.php') ? $this->visibleText(file_get_contents($file)) : file_get_contents($file);
            $text = preg_replace('/Supervisors? \/ Programme Managers?/', '', $text);

            // role codes, reason keys and code comments keep the word; a visible label does not
            foreach (preg_split('/\R/', $text) as $n => $line) {
                if (preg_match('/^\s*(\*|\/\/|\/\*|#)/', $line)) {
                    continue;
                }
                if (preg_match('/(?<![\w$>\'"_:-])Supervisors?\b(?![\w\'"])/', $line) && ! preg_match('/Role::|\'supervisor\'|STATUS_WITH/', $line)) {
                    $bad[] = basename($file).':'.($n + 1).': '.trim(substr($line, 0, 110));
                }
            }
        }

        $this->assertSame([], $bad, "Write \"{$label}\":\n".implode("\n", $bad));
    }
}
