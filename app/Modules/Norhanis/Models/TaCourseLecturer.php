<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\User;
use App\Modules\Norhanis\Support\Roles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** A course lecturer's staff code, for the Daily Log's lecturer search. */
class TaCourseLecturer extends Model
{
    protected $table = 'ta_course_lecturers';

    protected $fillable = ['user_id', 'staff_code'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "Dr. Hafiz Rahman (UTP1234)": what the student picks and sees. */
    public function display(): string
    {
        return "{$this->user->name} ({$this->staff_code})";
    }

    /** Every lecturer a student can pick, by name. */
    public static function choices(): Collection
    {
        return self::with('user')
            ->whereHas('user', fn ($q) => $q->where('role', Roles::LECTURER))
            ->get()
            ->sortBy(fn (self $l) => $l->user->name)
            ->values();
    }

    /**
     * The lecturer a typed value names: "Name (CODE)" as the search list
     * offers it, or just the code, or just the exact name. Null when it
     * names nobody, or a name two lecturers share.
     */
    public static function resolve(string $value): ?self
    {
        $value = trim($value);
        $choices = self::choices();

        if (preg_match('/\(([^()]+)\)\s*$/', $value, $m)) {
            $code = trim($m[1]);
        } else {
            $code = $value;
        }

        $byCode = $choices->first(fn (self $l) => strcasecmp($l->staff_code, $code) === 0);
        if ($byCode) {
            return $byCode;
        }

        $byName = $choices->filter(fn (self $l) => strcasecmp($l->user->name, $value) === 0);

        return $byName->count() === 1 ? $byName->first() : null;
    }
}
