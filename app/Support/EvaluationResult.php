<?php

namespace App\Support;

class EvaluationResult
{
    public const DAT = 'DAT';
    public const CHO_LAM_LAI = 'CHO_LAM_LAI';
    public const TRE_BI_TRU_DIEM = 'TRE_BI_TRU_DIEM';

    public const CHOICES = [
        self::DAT => 'Đạt',
        self::CHO_LAM_LAI => 'Chưa đạt - Yêu cầu làm lại',
        self::TRE_BI_TRU_DIEM => 'Chưa đạt - Yêu cầu làm lại và bị -1',
    ];

    public static function label(?string $code): string
    {
        if (! $code) {
            return '—';
        }

        return self::CHOICES[$code] ?? $code;
    }

    public static function isValid(string $code): bool
    {
        return array_key_exists($code, self::CHOICES);
    }

    public static function radioChoices(): array
    {
        return [
            self::DAT => 'Đạt (Ghi nhận hoàn thành)',
            self::CHO_LAM_LAI => 'Chưa đạt (Yêu cầu làm lại)',
            self::TRE_BI_TRU_DIEM => 'Chưa đạt (Làm lại & Trừ 1 điểm thi đua)',
        ];
    }
}
