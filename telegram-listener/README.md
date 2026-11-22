# SANCAN Telegram Listener

The listener receives messages from channels for which SANCAN has recorded content permission. It never writes MySQL directly; all data is sent to the authenticated Laravel ingest API.

Required environment variables (store in `/etc/sancan/telegram-import.env`, mode `0600`):

```dotenv
SANCAN_API_URL=https://api.sancan.ru
TELEGRAM_IMPORT_INTERNAL_TOKEN=change-me
TELEGRAM_API_ID=
TELEGRAM_API_HASH=
TELEGRAM_BOT_TOKEN=
TELEGRAM_SESSION_PATH=/var/lib/sancan-telegram/session
TELEGRAM_RECONCILE_SECONDS=300
```

Use `TELEGRAM_BOT_TOKEN` for channels which added the bot as an administrator. For approved MTProto sources omit it and create the Telethon session interactively once under the service account. Never commit the session file.

Install:

```bash
python3 -m venv /var/www/sancan/telegram-listener-venv
/var/www/sancan/telegram-listener-venv/bin/pip install -r requirements.txt
install -d -o www-data -g www-data -m 0700 /var/lib/sancan-telegram
install -m 0644 sancan-telegram-listener.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now sancan-telegram-listener
```
