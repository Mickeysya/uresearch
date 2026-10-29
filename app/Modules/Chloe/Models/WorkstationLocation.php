<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkstationLocation extends Model
{
    public const GENDER_MALE = 'male';

    public const GENDER_FEMALE = 'female';

    protected $fillable = ['block', 'room_code', 'gender', 'name', 'description', 'photo_path'];

    public function workstations(): HasMany
    {
        return $this->hasMany(Workstation::class);
    }

    public function genderLabel(): string
    {
        return ucfirst($this->gender);
    }

    /**
     * A real photo was never shot for every room on the reference seat map,
     * so every room falls back to the same generic illustration rather than
     * showing nothing — see public/images/workstation/room-placeholder.svg.
     * CGS can attach a real photo per room later by setting photo_path;
     * nothing else has to change.
     */
    public function photoUrl(): string
    {
        return $this->photo_path
            ? asset($this->photo_path)
            : asset('images/workstation/room-placeholder.svg');
    }

    /**
     * One shared seat icon per block, not per room or per seat -- every
     * seat in a block looks the same, so there's nothing to photograph per
     * seat. A real photo (.jpg) wins over the illustration (.svg) once one
     * exists for the block; falls back to the room illustration for a
     * block neither has been made for yet.
     */
    public function seatIconUrl(): string
    {
        $block = strtolower($this->block);

        foreach (['jpg', 'svg'] as $extension) {
            $path = "images/workstation/seat-{$block}.{$extension}";

            if (file_exists(public_path($path))) {
                return asset($path);
            }
        }

        return asset('images/workstation/room-placeholder.svg');
    }
}
