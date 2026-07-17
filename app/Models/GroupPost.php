<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupPost extends Model
{
    protected $fillable = [
        'department_id',
        'author_id',
        'content',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function __toString(): string
    {
        $preview = mb_substr((string) $this->content, 0, 40);

        return "{$this->department}: {$preview}";
    }
}
