import json

from channels.db import database_sync_to_async
from channels.generic.websocket import AsyncWebsocketConsumer

from .models import ChatMessage, Department


class ChatConsumer(AsyncWebsocketConsumer):
    """WebSocket chat theo phòng = id Tổ/Nhóm."""

    async def connect(self):
        self.room_name = self.scope['url_route']['kwargs']['room_name']
        self.group_name = f'chat_{self.room_name}'
        user = self.scope.get('user')

        if not user or not user.is_authenticated:
            await self.close(code=4001)
            return

        department = await self._get_department(self.room_name)
        if department is None:
            await self.close(code=4004)
            return

        if not await self._user_can_access(department, user):
            await self.close(code=4003)
            return

        self.department = department
        await self.channel_layer.group_add(self.group_name, self.channel_name)
        await self.accept()

    async def disconnect(self, close_code):
        if hasattr(self, 'group_name'):
            await self.channel_layer.group_discard(self.group_name, self.channel_name)

    async def receive(self, text_data=None, bytes_data=None):
        if not text_data:
            return
        try:
            payload = json.loads(text_data)
        except json.JSONDecodeError:
            return

        text = (payload.get('message') or payload.get('text') or '').strip()
        if not text:
            return
        if len(text) > 2000:
            text = text[:2000]

        user = self.scope['user']
        message = await self._save_message(user, text)
        await self.channel_layer.group_send(
            self.group_name,
            {
                'type': 'chat.message',
                'message': message,
            },
        )

    async def chat_message(self, event):
        await self.send(text_data=json.dumps(event['message'], ensure_ascii=False))

    @database_sync_to_async
    def _get_department(self, room_name):
        try:
            dept_id = int(room_name)
        except (TypeError, ValueError):
            return None
        try:
            return Department.objects.get(pk=dept_id)
        except Department.DoesNotExist:
            return None

    @database_sync_to_async
    def _user_can_access(self, department, user):
        return department.user_can_access(user)

    @database_sync_to_async
    def _save_message(self, user, text):
        msg = ChatMessage.objects.create(
            department=self.department,
            user=user,
            text=text,
        )
        # Refresh related user for display name
        msg.user = user
        return msg.to_chat_dict()
