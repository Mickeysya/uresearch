<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Support\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'matric_no', 'programme', 'contact_no',
        'department', 'faculty', 'supervisor_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The supervisor assigned to this student.
     *
     * The legacy users table had no such link, which is why every supervisor
     * saw every pending application in the system rather than their own
     * students'. Modules should scope their queues through this.
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id');
    }

    public function supervisees(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'student_id');
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isStudent(): bool
    {
        return $this->role === Role::STUDENT;
    }

    public function roleLabel(): string
    {
        return Role::label($this->role);
    }
}
