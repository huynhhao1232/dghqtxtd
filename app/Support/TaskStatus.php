<?php

namespace App\Support;

class TaskStatus
{
    public const TODO = 'todo';
    public const IN_PROGRESS = 'in_progress';
    public const PENDING = 'pending_review';
    public const COMPLETED = 'completed';
    public const REDO = 'redo';

    public const CHOICES = [
        self::TODO => 'Chưa làm',
        self::IN_PROGRESS => 'Đang làm',
        self::PENDING => 'Chờ duyệt',
        self::COMPLETED => 'Hoàn thành',
        self::REDO => 'Yêu cầu làm lại',
    ];

    public const PILL = [
        self::TODO => 'bg-gray-100 text-gray-700',
        self::IN_PROGRESS => 'bg-blue-100 text-blue-800',
        self::PENDING => 'bg-amber-100 text-amber-800',
        self::COMPLETED => 'bg-emerald-100 text-emerald-800',
        self::REDO => 'bg-rose-100 text-rose-800',
    ];

    public const PROGRESS = [
        self::TODO => 5,
        self::IN_PROGRESS => 45,
        self::PENDING => 75,
        self::COMPLETED => 100,
        self::REDO => 30,
    ];

    public static function label(string $status): string
    {
        return self::CHOICES[$status] ?? $status;
    }
}
