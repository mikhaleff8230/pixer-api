#!/usr/bin/env python3
"""SANCAN Telegram listener. It only receives permitted channel content.

Product normalization, deduplication and database writes remain in Laravel.
"""

import asyncio
import json
import logging
import os
import tempfile
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import httpx
from telethon import TelegramClient, events
from telethon.tl.custom.message import Message


logging.basicConfig(level=os.getenv("LOG_LEVEL", "INFO"), format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("sancan.telegram")


@dataclass
class Source:
    id: int
    telegram_channel_id: str | None
    username: str | None
    status: str
    initial_import_mode: str
    initial_import_limit: int | None
    initial_from: str | None
    initial_to: str | None
    last_processed_message_id: int | None


class Listener:
    def __init__(self) -> None:
        self.api_url = os.environ["SANCAN_API_URL"].rstrip("/")
        self.token = os.environ["TELEGRAM_IMPORT_INTERNAL_TOKEN"]
        self.client = TelegramClient(
            os.getenv("TELEGRAM_SESSION_PATH", "/var/lib/sancan-telegram/session"),
            int(os.environ["TELEGRAM_API_ID"]),
            os.environ["TELEGRAM_API_HASH"],
        )
        self.http = httpx.AsyncClient(timeout=httpx.Timeout(90), headers={"Authorization": f"Bearer {self.token}"})
        self.sources: dict[str, Source] = {}

    async def refresh_sources(self) -> None:
        response = await self.http.get(f"{self.api_url}/api/internal/telegram-import/channels")
        response.raise_for_status()
        sources: dict[str, Source] = {}
        for row in response.json():
            source = Source(**{key: row.get(key) for key in Source.__dataclass_fields__})
            if source.username:
                sources[source.username.lower().lstrip("@")] = source
            if source.telegram_channel_id:
                sources[str(source.telegram_channel_id)] = source
                sources[str(source.telegram_channel_id).removeprefix("-100")] = source
        self.sources = sources

    def source_for(self, message: Message) -> Source | None:
        chat = message.chat
        candidates = [str(getattr(chat, "id", "")), str(getattr(message, "chat_id", ""))]
        username = getattr(chat, "username", None)
        if username:
            candidates.append(username.lower())
        for key in candidates:
            if key in self.sources:
                return self.sources[key]
            if key.startswith("-100") and key[4:] in self.sources:
                return self.sources[key[4:]]
        return None

    async def send(self, source: Source, messages: list[Message], event_type: str) -> None:
        messages = sorted(messages, key=lambda item: item.id)
        canonical = messages[0]
        text = next((item.message for item in messages if item.message), "")
        grouped_id = str(canonical.grouped_id) if canonical.grouped_id else None
        source_url = f"https://t.me/{source.username}/{canonical.id}" if source.username else None
        data = {
            "channel_id": str(source.id),
            "telegram_message_id": str(canonical.id),
            "grouped_id": grouped_id or "",
            "source_url": source_url or "",
            "original_text": text,
            "original_date": (canonical.date or datetime.now(timezone.utc)).isoformat(),
            "edited_date": canonical.edit_date.isoformat() if canonical.edit_date else "",
            "event_type": event_type,
            "payload": json.dumps({"ids": [item.id for item in messages], "grouped_id": grouped_id}, ensure_ascii=False),
        }
        files: list[tuple[str, tuple[str, bytes, str]]] = []
        file_ids: list[str] = []
        for message in messages[:12]:
            if not message.photo:
                continue
            with tempfile.TemporaryDirectory(prefix="sancan-tg-") as folder:
                path = await message.download_media(file=folder)
                if not path:
                    continue
                content = Path(path).read_bytes()
                mime = "image/jpeg" if Path(path).suffix.lower() in {".jpg", ".jpeg"} else "image/png"
                files.append(("media[]", (Path(path).name, content, mime)))
                file_ids.append(str(message.photo.id))
        multipart_data = list(data.items()) + [("file_ids[]", value) for value in file_ids]
        response = await self.http.post(f"{self.api_url}/api/internal/telegram-import/ingest", data=multipart_data, files=files)
        response.raise_for_status()
        log.info("ingested channel=%s message=%s event=%s", source.id, canonical.id, event_type)

    async def report_error(self, source: Source, error: Exception) -> None:
        try:
            await self.http.post(f"{self.api_url}/api/internal/telegram-import/error", json={"channel_id": source.id, "message": str(error)[:5000]})
        except Exception:
            log.exception("could not report listener error")

    async def history(self, source: Source) -> None:
        if source.status != "sync_requested" and source.initial_import_mode == "none":
            return
        entity: Any = source.username or source.telegram_channel_id
        if not entity:
            return
        limit = source.initial_import_limit or (100 if source.status == "sync_requested" else 20)
        if source.initial_import_mode == "dates":
            limit = 500
        from_date = datetime.fromisoformat(source.initial_from.replace("Z", "+00:00")) if source.initial_from else None
        to_date = datetime.fromisoformat(source.initial_to.replace("Z", "+00:00")) if source.initial_to else None
        grouped: dict[str, list[Message]] = {}
        try:
            async for message in self.client.iter_messages(entity, limit=min(limit, 500), min_id=source.last_processed_message_id or 0, offset_date=to_date, reverse=True):
                message_date = message.date
                if from_date and message_date and message_date < from_date:
                    continue
                if to_date and message_date and message_date > to_date:
                    continue
                key = str(message.grouped_id or message.id)
                grouped.setdefault(key, []).append(message)
            for messages in grouped.values():
                await self.send(source, messages, "history")
            response = await self.http.post(f"{self.api_url}/api/internal/telegram-import/channels/{source.id}/complete")
            response.raise_for_status()
        except Exception as error:
            await self.report_error(source, error)
            log.exception("history sync failed source=%s", source.id)

    async def reconcile_loop(self) -> None:
        while True:
            try:
                await self.refresh_sources()
                unique_sources = {source.id: source for source in self.sources.values()}.values()
                for source in unique_sources:
                    await self.history(source)
            except Exception:
                log.exception("reconciliation failed")
            await asyncio.sleep(int(os.getenv("TELEGRAM_RECONCILE_SECONDS", "300")))

    async def run(self) -> None:
        await self.refresh_sources()

        @self.client.on(events.NewMessage)
        async def on_message(event: events.NewMessage.Event) -> None:
            source = self.source_for(event.message)
            if source and not event.message.grouped_id:
                await self.send(source, [event.message], "new")

        @self.client.on(events.Album)
        async def on_album(event: events.Album.Event) -> None:
            source = self.source_for(event.messages[0])
            if source:
                await self.send(source, list(event.messages), "album")

        @self.client.on(events.MessageEdited)
        async def on_edit(event: events.MessageEdited.Event) -> None:
            source = self.source_for(event.message)
            if source:
                await self.send(source, [event.message], "edited")

        await self.client.start(bot_token=os.getenv("TELEGRAM_BOT_TOKEN") or None)
        reconcile = asyncio.create_task(self.reconcile_loop())
        try:
            await self.client.run_until_disconnected()
        finally:
            reconcile.cancel()
            await self.http.aclose()


if __name__ == "__main__":
    backoff = 5
    while True:
        try:
            asyncio.run(Listener().run())
            break
        except KeyboardInterrupt:
            break
        except Exception:
            log.exception("listener stopped; reconnecting in %ss", backoff)
            import time
            time.sleep(backoff)
            backoff = min(backoff * 2, 300)
