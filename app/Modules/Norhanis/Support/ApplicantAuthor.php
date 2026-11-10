<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\User;
use Illuminate\Support\Str;

/**
 * Author 1 on the Publication form is always the signed-in student. Their
 * name and email come straight from the account; designation and
 * organisation are worked out from it, since `users` has no such columns
 * (and gets none -- rule 2). Every value only prefills the form: the
 * student can change any of them.
 */
class ApplicantAuthor
{
    public const ORGANISATION = 'Universiti Teknologi PETRONAS';

    /** @return array{author_name: string, email: string, designation: string, organisation: string} */
    public static function for(User $student): array
    {
        return [
            'author_name' => (string) $student->name,
            'email' => (string) $student->email,
            'designation' => self::designation($student->programme),
            'organisation' => self::ORGANISATION,
        ];
    }

    /** "PhD in Chemical Engineering (Full-Time)" => "PhD Student"; anything unrecognised => "Postgraduate Student". */
    public static function designation(?string $programme): string
    {
        $programme = Str::lower((string) $programme);

        return match (true) {
            Str::startsWith($programme, ['phd', 'doctor']) => 'PhD Student',
            Str::startsWith($programme, ['msc', 'master', 'm.sc', 'mphil']) => "Master's Student",
            default => 'Postgraduate Student',
        };
    }
}
