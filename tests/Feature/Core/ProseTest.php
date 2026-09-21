<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * House style for the words on the screen.
 *
 * The em dash used as a sentence connector -- "we did X — it means Y" -- is
 * the single most recognisable tell that a sentence was not typed by a human,
 * and this is a Final Year Project that gets read by supervisors. A comma, a
 * semicolon, a colon or a full stop says the same thing and does not.
 *
 * Deliberately narrow, so it stays a rule people can follow rather than one
 * they fight:
 *
 *   - Only the EM dash (U+2014). The EN dash (U+2013) is correct in a range
 *     ("75% – 84%", "Jan – Mar") and is left alone.
 *   - Only where it is spaced, which is the prose usage. A bare em dash
 *     standing in for an empty table cell ({{ $x ?? '—' }}) is ordinary UI
 *     convention, not prose, and is left alone.
 *   - Only what actually renders. Blade comments, <style> and <script> are
 *     stripped first: code comments are not read by anyone marking this.
 */
class ProseTest extends TestCase
{
    /**
     * Blade comments, @php blocks, CSS, JS and code comments: none of it
     * reaches the page. Replaced with blank lines rather than removed, so a
     * reported line number still matches the file.
     */
    protected function renderedText(string $source): string
    {
        $blank = fn ($m) => str_repeat("\n", substr_count($m[0], "\n"));

        $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $source);
        $source = preg_replace_callback('/@php\b.*?@endphp/s', $blank, $source);
        $source = preg_replace_callback('/<style\b.*?<\/style>/s', $blank, $source);
        $source = preg_replace_callback('/<script\b.*?<\/script>/s', $blank, $source);
        $source = preg_replace_callback('/\/\*.*?\*\//s', $blank, $source);

        // A whole line that is only a // comment.
        return preg_replace('/^\s*\/\/.*$/m', '', $source);
    }

    public function test_no_page_uses_an_em_dash_as_a_sentence_connector(): void
    {
        $views = glob(base_path('app/Modules/*/Resources/views/**/*.blade.php'), GLOB_BRACE)
            + glob(base_path('app/Modules/*/Resources/views/*.blade.php'));

        $this->assertNotEmpty($views, 'Found no Blade views to scan, so the glob is wrong.');

        $offenders = [];

        foreach ($views as $view) {
            $name = str_replace(base_path().'/', '', $view);

            foreach (explode("\n", $this->renderedText(file_get_contents($view))) as $i => $line) {
                // A line that is nothing but an em dash is the empty-cell
                // placeholder written across an @if, not prose.
                if (trim($line) === "\u{2014}") {
                    continue;
                }

                // Spaced on either side, or written as the HTML entity.
                if (preg_match('/(\x{2014}\s|\s\x{2014}|&mdash;)/u', $line)) {
                    $offenders[] = $name.':'.($i + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An em dash is being used as a sentence connector. Use a comma, a semicolon, "
            ."a colon or a full stop instead:\n  ".implode("\n  ", $offenders)
        );
    }
}
