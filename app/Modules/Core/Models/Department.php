<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A name on the department picker, not a foreign key anyone else's row
 * points at. See the migration for why: `users.department` stays free text.
 */
class Department extends Model
{
    protected $fillable = ['name', 'faculty', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
