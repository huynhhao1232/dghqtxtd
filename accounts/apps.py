from django.apps import AppConfig


class AccountsConfig(AppConfig):
    name = 'accounts'
    verbose_name = 'Tài khoản'

    def ready(self):
        import accounts.signals  # noqa: F401
