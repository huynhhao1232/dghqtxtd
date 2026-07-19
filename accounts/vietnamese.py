"""Sắp xếp họ tên theo quy ước tiếng Việt: Tên → Tên đệm → Họ."""

from __future__ import annotations

import unicodedata

# Thứ tự chữ cái tiếng Việt (đã bỏ dấu thanh; ă â đ ê ô ơ ư đứng đúng vị trí)
_VIET_ALPHABET = (
    'aăâbcdđeêghiklmnoôơpqrstuưvxy'
    '0123456789'
)

_CHAR_RANK = {ch: f'{i:03d}' for i, ch in enumerate(_VIET_ALPHABET)}

# Map nguyên âm/phụ âm có dấu thanh → chữ cái gốc trong bảng chữ cái VN
_TONE_TO_BASE = str.maketrans({
    'à': 'a', 'á': 'a', 'ả': 'a', 'ã': 'a', 'ạ': 'a',
    'ằ': 'ă', 'ắ': 'ă', 'ẳ': 'ă', 'ẵ': 'ă', 'ặ': 'ă',
    'ầ': 'â', 'ấ': 'â', 'ẩ': 'â', 'ẫ': 'â', 'ậ': 'â',
    'è': 'e', 'é': 'e', 'ẻ': 'e', 'ẽ': 'e', 'ẹ': 'e',
    'ề': 'ê', 'ế': 'ê', 'ể': 'ê', 'ễ': 'ê', 'ệ': 'ê',
    'ì': 'i', 'í': 'i', 'ỉ': 'i', 'ĩ': 'i', 'ị': 'i',
    'ò': 'o', 'ó': 'o', 'ỏ': 'o', 'õ': 'o', 'ọ': 'o',
    'ồ': 'ô', 'ố': 'ô', 'ổ': 'ô', 'ỗ': 'ô', 'ộ': 'ô',
    'ờ': 'ơ', 'ớ': 'ơ', 'ở': 'ơ', 'ỡ': 'ơ', 'ợ': 'ơ',
    'ù': 'u', 'ú': 'u', 'ủ': 'u', 'ũ': 'u', 'ụ': 'u',
    'ừ': 'ư', 'ứ': 'ư', 'ử': 'ư', 'ữ': 'ư', 'ự': 'ư',
    'ỳ': 'y', 'ý': 'y', 'ỷ': 'y', 'ỹ': 'y', 'ỵ': 'y',
    'đ': 'đ',
})


def viet_sort_key(text: str) -> str:
    """Khóa so sánh theo bảng chữ cái tiếng Việt (không phân biệt hoa/thường, bỏ dấu thanh)."""
    if not text:
        return ''
    text = unicodedata.normalize('NFC', text).lower().strip()
    text = text.translate(_TONE_TO_BASE)

    # Bảo vệ ă â đ ê ô ơ ư trước khi NFD (tránh bị tách thành a/e/o/u + dấu)
    _protect = {
        'ă': '\ue001',
        'â': '\ue002',
        'đ': '\ue003',
        'ê': '\ue004',
        'ô': '\ue005',
        'ơ': '\ue006',
        'ư': '\ue007',
    }
    _restore = {v: k for k, v in _protect.items()}
    for src, token in _protect.items():
        text = text.replace(src, token)

    text = ''.join(
        c for c in unicodedata.normalize('NFD', text)
        if not unicodedata.combining(c)
    )
    for token, src in _restore.items():
        text = text.replace(token, src)

    parts = []
    for ch in text:
        if ch in _CHAR_RANK:
            parts.append(_CHAR_RANK[ch])
        elif ch.isspace() or ch in "-'’.":
            parts.append(' ')
        else:
            parts.append(f'z{ord(ch):04x}')
    return ''.join(parts)


def parse_vietnamese_name(full_name: str) -> tuple[str, str, str]:
    """
    Tách họ tên Việt Nam.
    Trả về (tên, tên_đệm, họ).
    VD: 'Nguyễn Văn An' → ('An', 'Văn', 'Nguyễn')
        'Trần An' → ('An', '', 'Trần')
        'An' → ('An', '', '')
    """
    parts = [p for p in (full_name or '').strip().split() if p]
    if not parts:
        return ('', '', '')
    if len(parts) == 1:
        return (parts[0], '', '')
    if len(parts) == 2:
        return (parts[1], '', parts[0])
    return (parts[-1], ' '.join(parts[1:-1]), parts[0])


def vietnamese_person_sort_key(full_name: str) -> tuple[str, str, str, str]:
    """Khóa sắp xếp: Tên → Tên đệm → Họ (rồi chuỗi gốc để ổn định)."""
    ten, dem, ho = parse_vietnamese_name(full_name)
    return (
        viet_sort_key(ten),
        viet_sort_key(dem),
        viet_sort_key(ho),
        full_name.lower(),
    )


def user_display_full_name(user) -> str:
    """Họ và tên hiển thị theo thứ tự Việt Nam (Họ + Đệm + Tên)."""
    first = (user.first_name or '').strip()
    last = (user.last_name or '').strip()
    if last and first:
        # Dữ liệu cũ: last_name=Họ, first_name=Tên đệm + Tên
        return f'{last} {first}'.strip()
    return first or last or user.username


def sort_users_by_vietnamese_name(users):
    """Sắp xếp list User tăng dần theo Tên → Đệm → Họ."""
    return sorted(
        users,
        key=lambda u: vietnamese_person_sort_key(user_display_full_name(u)),
    )
