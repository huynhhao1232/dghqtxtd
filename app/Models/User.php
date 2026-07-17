<?php

namespace App\Models;

use App\Support\Vietnamese;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'first_name',
        'last_name',
        'email',
        'password',
        'is_manager',
        'is_staff',
        'is_active',
        'position',
        'phone',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_manager' => 'boolean',
        'is_staff' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user');
    }

    public function ledDepartments(): HasMany
    {
        return $this->hasMany(Department::class, 'leader_id');
    }

    public function taskNotifications(): HasMany
    {
        return $this->hasMany(TaskNotification::class, 'recipient_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class, 'assignee_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(TaskParticipation::class);
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by_id');
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return Storage::url($this->avatar);
        }

        $name = rawurlencode((string) $this);

        return "https://ui-avatars.com/api/?name={$name}&background=1d4ed8&color=fff";
    }

    public function getDepartmentNameAttribute(): string
    {
        $names = $this->departments()->orderBy('name')->limit(3)->pluck('name')->all();

        if (! $names) {
            return '—';
        }

        return implode(', ', $names);
    }

    public function getFullNameVnAttribute(): string
    {
        return Vietnamese::userDisplayFullName($this);
    }

    public function getIsGroupLeaderAttribute(): bool
    {
        return $this->ledDepartments()->exists();
    }

    public function getRoleLabelAttribute(): string
    {
        return $this->is_manager ? 'Quản lý' : 'Nhân viên';
    }

    public function leadsDepartment(?Department $department): bool
    {
        if (! $department) {
            return false;
        }

        return (int) $department->leader_id === (int) $this->id;
    }

    public function __toString(): string
    {
        return $this->full_name_vn;
    }
}
