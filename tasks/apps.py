from django.apps import AppConfig


class TasksConfig(AppConfig):
    name = 'tasks'
    verbose_name = 'Công việc & Thông báo'

    def ready(self):
        import tasks.signals  # noqa: F401
