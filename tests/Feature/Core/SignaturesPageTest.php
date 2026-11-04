<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * "My Signatures": one page and one sidebar entry for every signature a
 * person keeps, instead of a "My Signature" link per module.
 */
class SignaturesPageTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_a_supervisor_gets_one_link_and_one_section_per_module(): void
    {
        $supervisor = $this->supervisor();

        $labels = array_column(app(ModuleRegistry::class)->linksFor($supervisor), 'label');
        $this->assertSame(1, count(array_keys($labels, 'My Signatures')));
        $this->assertNotContains('My Signature', $labels);
        $this->assertNotContains('Hardbound Signature', $labels);

        $this->actingAs($supervisor)
            ->get(route('signatures.index'))
            ->assertOk()
            ->assertSeeInOrder(['Study Candidacy Appeals', 'Hardbound Thesis &amp; Appointment Letters'], false)
            ->assertSee('Not uploaded')
            ->assertSee('Required');
    }

    public function test_an_upload_from_the_page_comes_back_to_it(): void
    {
        Storage::fake('local');

        $this->actingAs($this->supervisor())
            ->post(route('hardbound.signature.store'), [
                'signature' => UploadedFile::fake()->image('sig.png', 600, 200),
                'return' => '/signatures',
            ])
            ->assertRedirect(route('signatures.index').'#hardbound');

        $this->get(route('signatures.index'))->assertSee('On file');
    }

    /**
     * Approving without a signature bounces to hardbound.signature with a
     * flashed reason; that page now forwards here, and the reason must
     * survive the extra hop.
     */
    public function test_the_old_pages_forward_here_and_keep_their_message(): void
    {
        $chair = $this->chair();

        $this->actingAs($chair)
            ->from(route('dashboard'))
            ->get(route('hardbound.signature'))
            ->assertRedirect(route('signatures.index').'#hardbound');

        $this->actingAs($chair)
            ->withSession(['error' => 'Upload your signature first.', '_flash' => ['old' => [], 'new' => ['error']]])
            ->followingRedirects()
            ->get(route('hardbound.signature'))
            ->assertSee('My Signatures')
            ->assertSee('Upload your signature first.');
    }

    public function test_a_student_sees_only_the_signature_they_can_use(): void
    {
        $this->actingAs($this->student())
            ->get(route('signatures.index'))
            ->assertOk()
            ->assertSee('Hardbound Thesis &amp; Appointment Letters', false)
            ->assertDontSee('Study Candidacy Appeals')
            // Their hardbound signature only matters if they appeal, so it is not Required.
            ->assertSee('Optional');
    }
}
