"""Verify Long Van / S3 credentials and optionally create the media bucket."""

from django.conf import settings
from django.core.management.base import BaseCommand, CommandError


class Command(BaseCommand):
    help = (
        'Kiểm tra kết nối S3 (Long Van): liệt kê bucket và thử truy cập '
        'AWS_STORAGE_BUCKET_NAME. Dùng --create-bucket để tạo bucket nếu chưa có.'
    )

    def add_arguments(self, parser):
        parser.add_argument(
            '--create-bucket',
            action='store_true',
            help='Tạo AWS_STORAGE_BUCKET_NAME nếu chưa tồn tại / Create bucket if missing',
        )

    def handle(self, *args, **options):
        if not getattr(settings, 'USE_S3', False):
            raise CommandError(
                'USE_S3 chưa bật. Đặt USE_S3=1 trong .env hoặc biến môi trường.'
            )
        if not settings.AWS_ACCESS_KEY_ID or not settings.AWS_SECRET_ACCESS_KEY:
            raise CommandError('Thiếu AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY.')

        try:
            import boto3
            from botocore.client import Config
            from botocore.exceptions import ClientError
        except ImportError as exc:
            raise CommandError('Cần cài boto3: pip install -r requirements.txt') from exc

        client = boto3.client(
            's3',
            endpoint_url=settings.AWS_S3_ENDPOINT_URL,
            aws_access_key_id=settings.AWS_ACCESS_KEY_ID,
            aws_secret_access_key=settings.AWS_SECRET_ACCESS_KEY,
            region_name=settings.AWS_S3_REGION_NAME or 'us-east-1',
            config=Config(
                signature_version=settings.AWS_S3_SIGNATURE_VERSION,
                s3={'addressing_style': settings.AWS_S3_ADDRESSING_STYLE},
            ),
        )

        bucket = settings.AWS_STORAGE_BUCKET_NAME
        self.stdout.write(f'Endpoint: {settings.AWS_S3_ENDPOINT_URL}')
        self.stdout.write(f'Bucket mục tiêu: {bucket}')

        try:
            resp = client.list_buckets()
        except ClientError as exc:
            raise CommandError(f'Không list được buckets: {exc}') from exc

        names = [b['Name'] for b in resp.get('Buckets', [])]
        self.stdout.write('Buckets hiện có:')
        if names:
            for name in names:
                mark = ' ←' if name == bucket else ''
                self.stdout.write(f'  - {name}{mark}')
        else:
            self.stdout.write('  (trống)')

        exists = bucket in names
        if not exists:
            # HeadBucket works even if ListAllMyBuckets is restricted
            try:
                client.head_bucket(Bucket=bucket)
                exists = True
            except ClientError:
                exists = False

        if exists:
            self.stdout.write(self.style.SUCCESS(f'OK — bucket "{bucket}" sẵn sàng.'))
            return

        if not options['create_bucket']:
            raise CommandError(
                f'Bucket "{bucket}" chưa có. Tạo trên Long Van console hoặc chạy:\n'
                f'  python manage.py check_s3 --create-bucket'
            )

        try:
            client.create_bucket(Bucket=bucket)
        except ClientError as exc:
            code = exc.response.get('Error', {}).get('Code', '')
            if code in ('TooManyBuckets', 'BucketAlreadyExists', 'BucketAlreadyOwnedByYou'):
                hint = ''
                if names:
                    hint = f' Bucket hiện có: {", ".join(names)}. Đặt AWS_STORAGE_BUCKET_NAME cho đúng tên đó.'
                raise CommandError(f'Tạo bucket thất bại ({code}).{hint}\nChi tiết: {exc}') from exc
            raise CommandError(f'Tạo bucket thất bại: {exc}') from exc

        self.stdout.write(self.style.SUCCESS(f'Đã tạo bucket "{bucket}".'))
