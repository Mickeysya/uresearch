<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Database\Seeders\HaniDemoSeeder;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerPublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** HaniDemoSeeder, part 1: the examiner pool from the fictional JSON dataset. */
class HaniDemoSeederTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'Computing' => 'FSMC', 'Management' => 'FSMC', 'Applied Sciences' => 'FSMC', 'Geosciences' => 'FSMC',
            'Chemical Engineering' => 'FOE', 'Petroleum Engineering' => 'FOE', 'Civil & Environmental Engineering' => 'FOE',
            'Mechanical Engineering' => 'FOE', 'Electrical & Electronics Engineering' => 'FOE',
        ] as $name => $faculty) {
            Department::create(['name' => $name, 'faculty' => $faculty]);
        }
    }

    public function test_it_loads_the_pool_through_the_import_and_is_idempotent(): void
    {
        $this->seniorExec();
        $users = User::count();
        $before = md5_file(HaniDemoSeeder::DATASET);

        $this->seed(HaniDemoSeeder::class);

        $this->assertSame(41, Examiner::count());
        $this->assertSame(15, ExaminerPublication::count());
        $this->assertSame('Computing', Examiner::where('email', 'farah.rosli@example.com')->value('department'));
        $this->assertFalse((bool) Examiner::where('email', 'zulkifli.hassan@example.com')->value('is_active'));
        $this->assertStringStartsWith('Adjunct', Examiner::where('email', 'azlina.mansor@example.org')->value('current_utp_appointment'));

        $this->seed(HaniDemoSeeder::class);

        $this->assertSame(41, Examiner::count(), 'Running it again adds nobody.');
        $this->assertSame(15, ExaminerPublication::count());
        $this->assertSame($users, User::count(), 'Teammates\' test accounts are not touched.');
        $this->assertSame($before, md5_file(HaniDemoSeeder::DATASET), 'The dataset is read, never written.');
    }
}
