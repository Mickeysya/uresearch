<?php

namespace App\Modules\Chloe\Support;

/**
 * The fake roster of the demo data: Malaysian, Chinese and Indian names with titles, generated from a FIXED seed so that every reseed gives
 * the same people. Lecturers and chairs come from separate draws, every name is unique, and none equals a real account's name.
 * (It uses its own seeded draw and leaves the global random generator as it found it.)
 */
final class CandidacyDemoRoster
{
    public const SEED = 260301;

    private const MALAY = [
        'm' => ['Ahmad Faizal', 'Mohd Hafizuddin', 'Muhammad Azri', 'Khairul Anwar', 'Shahrul Nizam', 'Ismail Sabri', 'Hasbullah', 'Zulkifli', 'Mohd Rizal', 'Amirul Hakim', 'Fakhrul Razi', 'Syed Idris', 'Norazman', 'Wan Azlan'],
        'f' => ['Nurul Aina', 'Siti Hajar', 'Norzila', 'Farah Liyana', 'Azlina', 'Rohana', 'Zaharah', 'Hasnah', 'Sharifah Nadia', 'Marlina', 'Noraini', 'Salmah'],
        'father' => ['Abdullah', 'Hassan', 'Ibrahim', 'Yusof', 'Hamid', 'Othman', 'Ramli', 'Mansor', 'Jaafar', 'Daud', 'Kamaruddin', 'Samad'],
    ];

    private const CHINESE = [
        'surname' => ['Tan', 'Lim', 'Lee', 'Wong', 'Chong', 'Ng', 'Teo', 'Goh', 'Yap', 'Chin', 'Ong', 'Liew', 'Ho', 'Cheah'],
        'given' => ['Wei Ming', 'Siew Lan', 'Kok Leong', 'Mei Ling', 'Boon Hock', 'Jia Hui', 'Chee Keong', 'Li Ying', 'Hock Seng', 'Yen Ni', 'Kah Yee', 'Zhi Hao', 'Pei Shan', 'Tze Wei'],
    ];

    private const INDIAN = [
        'm' => ['Rajendran', 'Kumaresan', 'Sivakumar', 'Ganesan', 'Subramaniam', 'Murugan', 'Ravindran', 'Prakash', 'Arumugam', 'Selvam'],
        'f' => ['Priya', 'Anitha', 'Vijayalakshmi', 'Lakshmi', 'Devi', 'Kavitha', 'Meenakshi', 'Sangeetha', 'Revathi'],
        'father' => ['Muthu', 'Krishnan', 'Rajan', 'Pillai', 'Naidu', 'Perumal', 'Ramasamy', 'Chandran'],
    ];

    private const LECTURER_TITLES = ['Dr.', 'Ir. Dr.', 'Assoc. Prof. Dr.'];

    private const CHAIR_TITLES = ['Assoc. Prof. Dr.', 'Prof. Dr.', 'Prof. Ir. Dr.'];

    /**
     * @param  list<string>  $deptCodes  all department codes
     * @param  list<string>  $realNames  names of real accounts (never reused, with or without title)
     * @return array{chairs: array<string,string>, lecturers: array<string, list<string>>} chairs keyed by code (CIS excluded: it has the real chair);
     *                                                                                    lecturers keyed by code (CIS holds only its FAKE lecturers, 4 to 6)
     */
    public static function generate(array $deptCodes, array $realNames): array
    {
        mt_srand(self::SEED);

        $taken = [];
        foreach ($realNames as $n) {
            $taken[strtolower(CandidacyStaffDirectory::sortName($n))] = true;
        }

        $draw = function (array $titles) use (&$taken): string {
            for ($try = 0; $try < 500; $try++) {
                $name = self::person();
                $key = strtolower($name);

                if (! isset($taken[$key])) {
                    $taken[$key] = true;

                    return $titles[mt_rand(0, count($titles) - 1)].' '.$name;
                }
            }

            throw new \RuntimeException('Could not draw a unique name.');
        };

        $out = ['chairs' => [], 'lecturers' => []];

        foreach ($deptCodes as $code) {
            if ($code !== 'CIS') {
                $out['chairs'][$code] = $draw(self::CHAIR_TITLES);
            }
        }

        foreach ($deptCodes as $code) {
            $count = $code === 'CIS' ? mt_rand(4, 6) : mt_rand(5, 7); // CIS: plus the real lecturer = 5 to 7
            $out['lecturers'][$code] = [];

            foreach (range(1, $count) as $i) {
                $out['lecturers'][$code][] = $draw(self::LECTURER_TITLES);
            }
        }

        mt_srand();

        return $out;
    }

    /** One person's name without a title. */
    private static function person(): string
    {
        $pick = fn (array $list) => $list[mt_rand(0, count($list) - 1)];

        return match (mt_rand(0, 2)) {
            0 => ($male = mt_rand(0, 1)) ? $pick(self::MALAY['m']).' bin '.$pick(self::MALAY['father']) : $pick(self::MALAY['f']).' binti '.$pick(self::MALAY['father']),
            1 => $pick(self::CHINESE['surname']).' '.$pick(self::CHINESE['given']),
            default => ($male = mt_rand(0, 1)) ? $pick(self::INDIAN['m']).' a/l '.$pick(self::INDIAN['father']) : $pick(self::INDIAN['f']).' a/p '.$pick(self::INDIAN['father']),
        };
    }
}
