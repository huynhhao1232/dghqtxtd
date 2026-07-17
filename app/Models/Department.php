<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    public const BADGE_COLORS = [
        'blue' => 'Xanh dương',
        'emerald' => 'Xanh lá',
        'amber' => 'Vàng',
        'rose' => 'Hồng',
        'violet' => 'Tím',
        'cyan' => 'Xanh cyan',
        'orange' => 'Cam',
        'slate' => 'Xám',
    ];

    protected $fillable = [
        'name',
        'description',
        'leader_id',
        'badge_color',
    ];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(GroupPost::class);
    }

    public function primaryTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'primary_department_id');
    }

    public function scopedSubtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'scope_department_id');
    }

    public function departmentAssignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class, 'assignee_department_id');
    }

    public function getMemberCountAttribute(): int
    {
        return $this->members()->where('is_active', true)->count();
    }

    public function getBadgeClassesAttribute(): string
    {
        $mapping = [
            'blue' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            'amber' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            'rose' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            'violet' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
            'cyan' => 'bg-cyan-50 text-cyan-700 ring-cyan-600/20',
            'orange' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            'slate' => 'bg-slate-100 text-slate-700 ring-slate-500/20',
        ];

        return $mapping[$this->badge_color] ?? $mapping['blue'];
    }

    public function userCanAccess(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->is_manager) {
            return true;
        }

        if ((int) $this->leader_id === (int) $user->id) {
            return true;
        }

        return $this->members()->where('users.id', $user->id)->exists();
    }

    public function ensureLeaderIsMember(): void
    {
        if ($this->leader_id) {
            $this->members()->syncWithoutDetaching([$this->leader_id]);
        }
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
