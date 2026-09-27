<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkstationLocation extends Model
{
    public const GENDER_MALE = 'male';

    public const GENDER_FEMALE = 'female';

    protected $fillable = ['block', 'room_code', 'gender', 'name', 'description'];

    public function workstations(): HasMany
    {
        return $this->hasMany(Workstation::class);
    }

    public function genderLabel(): string
    {
        return ucfirst($this->gender);
    }
}
