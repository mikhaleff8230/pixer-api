# SANCAN Telegram Import 2.0 — architecture audit

## Existing mechanisms reused

- Ozon extension endpoint: `Marvel\Http\Controllers\OzonImportController`.
- Product persistence: `Marvel\Database\Models\Product`, existing product metadata, categories and `variation_options`.
- Media: the existing Spatie MediaLibrary attachment model and the configured `shop.media_disk` (Timeweb S3 in production).
- Draft state: `Marvel\Enums\ProductStatus::DRAFT`.
- Queue infrastructure: Laravel database/Redis queue support and `failed_jobs`.
- Seller authorization: `SancanImporterAuthController::userCanAccessShop`.

## Integration decisions

- Product creation was extracted into `ProductDraftImportService`; Ozon now uses it without changing its request or response contract.
- Telegram posts are idempotent by `(channel_id, telegram_message_id)`. Album elements are sent as one canonical post with `grouped_id`.
- Listener stores no product records and has no database credentials. It only calls an internal bearer-authenticated Laravel API.
- Telegram media is uploaded into SANCAN storage immediately; temporary Telegram URLs are never persisted.
- Sizes and colors are saved as source attributes on a variable draft. No SKU combination is invented without availability evidence.
- Published products are not overwritten after Telegram edits. The post is moved to manual review with its previous versions retained.
- Product groups and XML/YML import were not changed.

## Production requirements

1. Set `TELEGRAM_IMPORT_INTERNAL_TOKEN` in Laravel and `/etc/sancan/telegram-import.env`.
2. Configure Telegram API credentials outside the repository.
3. Set `QUEUE_CONNECTION=database` (or Redis) and enable `sancan-telegram-queue.service`.
4. Enable `sancan-telegram-listener.service` only after creating a permitted Bot API or MTProto session.
5. Pilot with one authorized channel before expanding to 5 and 20 channels.
