"""
Django settings for EduEval — Hệ thống Đánh giá Hiệu quả Công việc.
"""

import logging
import os
from pathlib import Path

_settings_logger = logging.getLogger(__name__)

BASE_DIR = Path(__file__).resolve().parent.parent


def _load_dotenv(path: Path) -> None:
    """Load KEY=VALUE pairs from .env if present (does not override existing env)."""
    if not path.is_file():
        return
    try:
        text = path.read_text(encoding='utf-8')
    except OSError:
        return
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, _, value = line.partition('=')
        key = key.strip()
        if not key or key in os.environ:
            continue
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in ('"', "'"):
            value = value[1:-1]
        os.environ[key] = value


_load_dotenv(BASE_DIR / '.env')

# Production: set DJANGO_SECRET_KEY on PythonAnywhere (Web → Environment variables
# or in the WSGI file). Local fallback keeps development convenient.
SECRET_KEY = os.environ.get(
    'DJANGO_SECRET_KEY',
    'django-insecure--k$hl9x5y@7p(3x572m2hvuh&65b&-i45h$ch3%$wph%vm^e=i',
)

# Local default True. On PythonAnywhere set DJANGO_DEBUG=0 (or false / no / off).
DEBUG = os.environ.get('DJANGO_DEBUG', '1').lower() in ('1', 'true', 'yes', 'on')

# Comma-separated hosts, e.g. yourusername.pythonanywhere.com
_allowed = os.environ.get('DJANGO_ALLOWED_HOSTS', '').strip()
if _allowed:
    ALLOWED_HOSTS = [h.strip() for h in _allowed.split(',') if h.strip()]
elif DEBUG:
    ALLOWED_HOSTS = ['*']
else:
    ALLOWED_HOSTS = []

# Needed when DEBUG=False and the site is served over HTTPS (PythonAnywhere free).
_csrf = os.environ.get('DJANGO_CSRF_TRUSTED_ORIGINS', '').strip()
if _csrf:
    CSRF_TRUSTED_ORIGINS = [o.strip() for o in _csrf.split(',') if o.strip()]
elif not DEBUG:
    CSRF_TRUSTED_ORIGINS = [
        f'https://{h}' for h in ALLOWED_HOSTS if h and not h.startswith('.')
    ]

INSTALLED_APPS = [
    'django.contrib.admin',
    'django.contrib.auth',
    'django.contrib.contenttypes',
    'django.contrib.sessions',
    'django.contrib.messages',
    'django.contrib.staticfiles',
    'accounts.apps.AccountsConfig',
    'tasks.apps.TasksConfig',
]

MIDDLEWARE = [
    'django.middleware.security.SecurityMiddleware',
    'django.contrib.sessions.middleware.SessionMiddleware',
    'django.middleware.common.CommonMiddleware',
    'django.middleware.csrf.CsrfViewMiddleware',
    'django.contrib.auth.middleware.AuthenticationMiddleware',
    'django.contrib.messages.middleware.MessageMiddleware',
    'django.middleware.clickjacking.XFrameOptionsMiddleware',
]

ROOT_URLCONF = 'edueval.urls'

TEMPLATES = [
    {
        'BACKEND': 'django.template.backends.django.DjangoTemplates',
        'DIRS': [BASE_DIR / 'templates'],
        'APP_DIRS': True,
        'OPTIONS': {
            'context_processors': [
                'django.template.context_processors.request',
                'django.template.context_processors.media',
                'django.contrib.auth.context_processors.auth',
                'django.contrib.messages.context_processors.messages',
                'accounts.context_processors.notifications',
            ],
        },
    },
]

WSGI_APPLICATION = 'edueval.wsgi.application'

# SQLite is fine on PythonAnywhere free tier (file under project root).
DATABASES = {
    'default': {
        'ENGINE': 'django.db.backends.sqlite3',
        'NAME': BASE_DIR / 'db.sqlite3',
    }
}

AUTH_PASSWORD_VALIDATORS = [
    {'NAME': 'django.contrib.auth.password_validation.UserAttributeSimilarityValidator'},
    {'NAME': 'django.contrib.auth.password_validation.MinimumLengthValidator'},
    {'NAME': 'django.contrib.auth.password_validation.CommonPasswordValidator'},
    {'NAME': 'django.contrib.auth.password_validation.NumericPasswordValidator'},
]

AUTH_USER_MODEL = 'accounts.User'

LANGUAGE_CODE = 'vi'
TIME_ZONE = 'Asia/Ho_Chi_Minh'
USE_I18N = True
USE_TZ = True

STATIC_URL = '/static/'
STATICFILES_DIRS = [BASE_DIR / 'static']
STATIC_ROOT = BASE_DIR / 'staticfiles'

MEDIA_URL = '/media/'
MEDIA_ROOT = BASE_DIR / 'media'

# ---------------------------------------------------------------------------
# Media storage: local disk by default; Long Van S3 when USE_S3=1.
# Static files stay on the server (PythonAnywhere static mapping / collectstatic).
# If USE_S3=1 but django-storages is missing (wrong venv), fall back to local
# media so pages with FileField do not crash with InvalidStorageError.
# ---------------------------------------------------------------------------
USE_S3 = os.environ.get('USE_S3', '0').lower() in ('1', 'true', 'yes', 'on')

AWS_ACCESS_KEY_ID = os.environ.get('AWS_ACCESS_KEY_ID', '')
AWS_SECRET_ACCESS_KEY = os.environ.get('AWS_SECRET_ACCESS_KEY', '')
AWS_STORAGE_BUCKET_NAME = os.environ.get('AWS_STORAGE_BUCKET_NAME', 'edueval')
AWS_S3_ENDPOINT_URL = os.environ.get(
    'AWS_S3_ENDPOINT_URL',
    'https://s3-hcm5-r1.longvan.net',
)
AWS_S3_REGION_NAME = os.environ.get('AWS_S3_REGION_NAME', 'us-east-1') or None
AWS_S3_SIGNATURE_VERSION = os.environ.get('AWS_S3_SIGNATURE_VERSION', 's3v4')
AWS_S3_ADDRESSING_STYLE = os.environ.get('AWS_S3_ADDRESSING_STYLE', 'path')
AWS_DEFAULT_ACL = None  # private objects; FileField.url uses signed URLs
AWS_QUERYSTRING_AUTH = True
AWS_S3_FILE_OVERWRITE = False
AWS_S3_OBJECT_PARAMETERS = {
    'CacheControl': 'max-age=86400',
}

STORAGES = {
    'default': {
        'BACKEND': 'django.core.files.storage.FileSystemStorage',
    },
    'staticfiles': {
        'BACKEND': 'django.contrib.staticfiles.storage.StaticFilesStorage',
    },
}

if USE_S3:
    try:
        import storages  # noqa: F401
    except ImportError:
        USE_S3 = False
        _settings_logger.warning(
            'USE_S3=1 nhưng chưa cài django-storages — dùng FileSystemStorage '
            '(media local). Chạy: pip install -r requirements.txt trong đúng venv '
            'của dự án DGVC (không dùng venv project khác).'
        )
    else:
        STORAGES['default'] = {
            'BACKEND': 'storages.backends.s3boto3.S3Boto3Storage',
        }

# Cho phép nhúng media cùng origin (xem trước PDF trong Modal iframe)
X_FRAME_OPTIONS = 'SAMEORIGIN'

# Secure cookies only over HTTPS. Set DJANGO_COOKIE_SECURE=0 for HTTP (e.g. IP-only VPS before SSL).
# Default: True when DEBUG=False (HTTPS), False when DEBUG=True.
_cookie_secure = os.environ.get('DJANGO_COOKIE_SECURE', '').strip().lower()
if _cookie_secure in ('1', 'true', 'yes', 'on'):
    SESSION_COOKIE_SECURE = True
    CSRF_COOKIE_SECURE = True
elif _cookie_secure in ('0', 'false', 'no', 'off'):
    SESSION_COOKIE_SECURE = False
    CSRF_COOKIE_SECURE = False
else:
    SESSION_COOKIE_SECURE = not DEBUG
    CSRF_COOKIE_SECURE = not DEBUG

if SESSION_COOKIE_SECURE or CSRF_COOKIE_SECURE:
    SECURE_PROXY_SSL_HEADER = ('HTTP_X_FORWARDED_PROTO', 'https')

DEFAULT_AUTO_FIELD = 'django.db.models.BigAutoField'

LOGIN_URL = 'login'
LOGIN_REDIRECT_URL = 'home'
LOGOUT_REDIRECT_URL = 'login'

from django.contrib.messages import constants as message_constants

MESSAGE_TAGS = {
    message_constants.DEBUG: 'debug',
    message_constants.INFO: 'info',
    message_constants.SUCCESS: 'success',
    message_constants.WARNING: 'warning',
    message_constants.ERROR: 'error',
}
