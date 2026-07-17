<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

class Vietnamese
{
    private const ALPHABET = 'aăâbcdđeêghiklmnoôơpqrstuưvxy0123456789';

    private static array $charRank = [];

    private const TONE_MAP = [
        'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
        'ằ' => 'ă', 'ắ' => 'ă', 'ẳ' => 'ă', 'ẵ' => 'ă', 'ặ' => 'ă',
        'ầ' => 'â', 'ấ' => 'â', 'ẩ' => 'â', 'ẫ' => 'â', 'ậ' => 'â',
        'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
        'ề' => 'ê', 'ế' => 'ê', 'ể' => 'ê', 'ễ' => 'ê', 'ệ' => 'ê',
        'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
        'ồ' => 'ô', 'ố' => 'ô', 'ổ' => 'ô', 'ỗ' => 'ô', 'ộ' => 'ô',
        'ờ' => 'ơ', 'ớ' => 'ơ', 'ở' => 'ơ', 'ỡ' => 'ơ', 'ợ' => 'ơ',
        'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
        'ừ' => 'ư', 'ứ' => 'ư', 'ử' => 'ư', 'ữ' => 'ư', 'ự' => 'ư',
        'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
    ];

    public static function vietSortKey(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (! self::$charRank) {
            foreach (mb_str_split(self::ALPHABET) as $i => $ch) {
                self::$charRank[$ch] = sprintf('%03d', $i);
            }
        }

        $text = mb_strtolower(normalizer_normalize($text, \Normalizer::FORM_C) ?: $text);
        $text = strtr($text, self::TONE_MAP);

        $protect = [
            'ă' => "\u{e001}", 'â' => "\u{e002}", 'đ' => "\u{e003}",
            'ê' => "\u{e004}", 'ô' => "\u{e005}", 'ơ' => "\u{e006}", 'ư' => "\u{e007}",
        ];
        $restore = array_flip($protect);
        $text = strtr($text, $protect);
        $nfd = normalizer_normalize($text, \Normalizer::FORM_D) ?: $text;
        $stripped = preg_replace('/\p{Mn}/u', '', $nfd) ?? $nfd;
        $text = strtr($stripped, $restore);

        $parts = [];
        foreach (mb_str_split($text) as $ch) {
            if (isset(self::$charRank[$ch])) {
                $parts[] = self::$charRank[$ch];
            } elseif (preg_match("/[\s\-'.’]/u", $ch)) {
                $parts[] = ' ';
            } else {
                $parts[] = 'z'.sprintf('%04x', mb_ord($ch));
            }
        }

        return implode('', $parts);
    }

    /** @return array{0:string,1:string,2:string} given, middle, surname */
    public static function parseVietnameseName(string $fullName): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', trim($fullName)) ?: []));
        if (! $parts) {
            return ['', '', ''];
        }
        if (count($parts) === 1) {
            return [$parts[0], '', ''];
        }
        if (count($parts) === 2) {
            return [$parts[1], '', $parts[0]];
        }

        return [$parts[count($parts) - 1], implode(' ', array_slice($parts, 1, -1)), $parts[0]];
    }

    public static function personSortKey(string $fullName): array
    {
        [$ten, $dem, $ho] = self::parseVietnameseName($fullName);

        return [
            self::vietSortKey($ten),
            self::vietSortKey($dem),
            self::vietSortKey($ho),
            mb_strtolower($fullName),
        ];
    }

    public static function userDisplayFullName(User $user): string
    {
        $first = trim((string) $user->first_name);
        $last = trim((string) $user->last_name);
        if ($last !== '' && $first !== '') {
            return trim($last.' '.$first);
        }

        return $first !== '' ? $first : ($last !== '' ? $last : (string) $user->username);
    }

    public static function sortUsers(iterable $users): Collection
    {
        return collect($users)->sortBy(fn (User $u) => self::personSortKey(self::userDisplayFullName($u)))->values();
    }
}
