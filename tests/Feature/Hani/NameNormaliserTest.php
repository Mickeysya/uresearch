<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Support\NameNormaliser;
use Tests\TestCase;

/**
 * Support\NameNormaliser: the comparison behind duplicate detection, import
 * matching without an email, and the co-authorship check.
 */
class NameNormaliserTest extends TestCase
{
    protected function names(): NameNormaliser
    {
        return app(NameNormaliser::class);
    }

    public function test_titles_are_stripped(): void
    {
        $this->assertSame('hassan omar', $this->names()->normalise('Prof Dr Hassan bin Omar'));
        $this->assertSame('hassan omar', $this->names()->normalise('Hassan Omar, PhD'));
        $this->assertSame('hassan omar', $this->names()->normalise('Prof. Ir. Dr. Hassan Omar'));
        $this->assertSame('melissa teoh hui min', $this->names()->normalise('Assoc Prof Dr Melissa Teoh Hui Min'));
        $this->assertSame('azman yahya', $this->names()->normalise('Datuk Ts. Dr Azman Yahya'));
        $this->assertSame('rahim', $this->names()->normalise('Tan Sri Dato Sri Rahim'));
    }

    public function test_ap_as_associate_professor_is_a_title(): void
    {
        $this->assertSame('farah liyana rosli', $this->names()->normalise('AP Dr Farah Liyana binti Rosli'));
        $this->assertSame(100, $this->names()->similarity('AP Dr Farah Liyana binti Rosli', 'Assoc Prof Dr Farah Liyana Rosli'));
    }

    public function test_patronymic_connectors_are_dropped(): void
    {
        $this->assertSame('nurul aina aziz', $this->names()->normalise('Assoc Prof Dr Nurul Aina binti Aziz'));
        $this->assertSame('rajesh subramaniam', $this->names()->normalise('Prof Dr Rajesh a/l Subramaniam'));
        $this->assertSame('priya ramasamy', $this->names()->normalise('Assoc Prof Dr Priya a/p Ramasamy'));
        $this->assertSame('ahmad ali', $this->names()->normalise('Ahmad bt. Ali'));
    }

    public function test_the_institution_hint_is_not_part_of_the_name(): void
    {
        $this->assertSame('hassan omar', $this->names()->normalise('Prof Dr Hassan bin Omar (UTM)'));
    }

    public function test_tan_the_surname_survives(): void
    {
        $this->assertSame('kevin tan boon huat', $this->names()->normalise('Dr Kevin Tan Boon Huat'));
    }

    public function test_word_order_does_not_hide_a_match(): void
    {
        $this->assertGreaterThanOrEqual(85, $this->names()->similarity('Omar Hassan', 'Hassan Omar'));
    }

    public function test_different_people_stay_apart(): void
    {
        $this->assertFalse($this->names()->looksAlike('Dr Farah Liyana Rosli', 'Dr Nur Syafiqah Hamzah'));
        $this->assertFalse($this->names()->looksAlike('Prof Dr Lim Wei Keat', 'Dr Lee Chun Hao'));
        $this->assertSame(0, $this->names()->similarity('', 'Hassan Omar'));
    }

    public function test_a_small_spelling_difference_still_looks_alike(): void
    {
        $this->assertTrue($this->names()->looksAlike('Muhammad Iqbal Rashid', 'Mohammad Iqbal Rashid'));
    }
}
