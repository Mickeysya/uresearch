<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;

class WorkstationBlock extends Model
{
    protected $fillable = ['code', 'name', 'photo_path'];

    /** Same fallback as WorkstationLocation::photoUrl() -- see that method. */
    public function photoUrl(): string
    {
        return $this->photo_path
            ? asset($this->photo_path)
            : asset('images/workstation/block-placeholder.svg');
    }
}
