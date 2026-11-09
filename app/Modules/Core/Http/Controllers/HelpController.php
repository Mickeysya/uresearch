<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Help and Support (/help): one page for every role, its content chosen by
 * who is signed in.
 *
 * Every word comes from config/help.php; this only decides what the viewer
 * gets and shapes it for the view:
 *   - exactly one audience: student, admin, cgs or approver (audienceFor());
 *   - the FAQ sections for 'all' plus that audience;
 *   - each question's stable anchor ({section}-{n}, counted in config order,
 *     so it never shifts with who is looking);
 *   - links kept only when their route exists (Route::has), so a link into
 *     a module that is not installed is dropped instead of throwing;
 *   - the CGS contact for students and approvers, system support for CGS
 *     and the administrator;
 *   - initials for the people in About us.
 */
class HelpController extends Controller
{
    /** Honorifics and connecting words an initial is never taken from. */
    private const SKIP_IN_INITIALS = ['professor', 'prof', 'assoc', 'dr', 'ts', 'mr', 'mrs', 'b', 'bt', 'bin', 'a/l', 'm'];

    public function __invoke(Request $request)
    {
        $audience = self::audienceFor($request->user());

        $sections = collect(config('help.sections', []))
            ->filter(fn (array $section) => array_intersect((array) ($section['audiences'] ?? ['all']), ['all', $audience]) !== [])
            ->map(fn (array $section) => [
                'id' => $section['id'],
                'title' => $section['title'],
                'icon' => $section['icon'] ?? 'compass',
                'questions' => collect($section['questions'])->values()
                    ->map(fn (array $q, int $i) => $this->question($section['id'], $i + 1, $q))
                    ->all(),
            ])
            ->values()
            ->all();

        $people = fn (array $list) => array_map(fn (array $p) => $p + ['initials' => self::initials($p['name'])], $list);
        $isStaffDesk = in_array($audience, ['cgs', 'admin'], true);

        return view('core::help.index', [
            'audience' => $audience,
            'sections' => $sections,
            'questionCount' => array_sum(array_map(fn ($s) => count($s['questions']), $sections)),
            'contactPhrase' => config("help.contact_phrase.{$audience}", 'reach the Centre for Graduate Studies directly'),
            'popular' => config("help.popular.{$audience}", []),
            // CGS staff cannot usefully be told to contact CGS.
            'contactMode' => $isStaffDesk ? 'support' : 'cgs',
            'contact' => config('help.contact', []),
            'support' => config('help.support', []),
            'tips' => config('help.contact_tips.'.($audience === 'student' ? 'student' : 'staff'), []),
            'showGuides' => $audience === 'student',
            'about' => config('help.about', []),
            'leadership' => $people(config('help.leadership', [])),
            'units' => array_map(fn (array $u) => ['staff' => $people($u['staff'] ?? [])] + $u, config('help.units', [])),
        ]);
    }

    /** student, admin, cgs, or approver for every other role. */
    public static function audienceFor(User $user): string
    {
        return match (true) {
            $user->isStudent() => 'student',
            $user->isAdmin() => 'admin',
            $user->isCgs() => 'cgs',
            default => 'approver',
        };
    }

    /** One question, ready to render. */
    private function question(string $sectionId, int $n, array $q): array
    {
        $paragraphs = (array) ($q['a'] ?? []);
        $list = array_map(fn (array $item) => [
            'term' => $item['term'] ?? '',
            'text' => $item['text'] ?? '',
            'tone' => $item['tone'] ?? null,
        ], $q['list'] ?? []);

        $link = isset($q['link']['route']) && Route::has($q['link']['route'])
            ? ['url' => route($q['link']['route']), 'label' => $q['link']['label']]
            : null;

        return [
            'id' => "{$sectionId}-{$n}",
            'q' => $q['q'],
            'paragraphs' => $paragraphs,
            'list' => $list,
            'badges' => (bool) ($q['badges'] ?? false),
            'link' => $link,
            // Everything a search should match, lower-cased once here.
            'search' => Str::lower(implode(' ', [
                $q['q'],
                ...$paragraphs,
                ...array_map(fn ($i) => $i['term'].' '.$i['text'], $list),
            ])),
        ];
    }

    /** "Assoc. Prof. Dr Balbir Singh A/L Mahinder Singh" -> "BS": first and last real name words. */
    public static function initials(string $name): string
    {
        $words = array_values(array_filter(
            preg_split('/\s+/', trim($name)) ?: [],
            fn (string $w) => $w !== '' && ! in_array(rtrim(Str::lower($w), '.'), self::SKIP_IN_INITIALS, true),
        ));

        if ($words === []) {
            return '?';
        }

        $first = $words[0];
        $last = count($words) > 1 ? end($words) : null;

        return Str::upper(mb_substr($first, 0, 1).($last ? mb_substr($last, 0, 1) : ''));
    }
}
