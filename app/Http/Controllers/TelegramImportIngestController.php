<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramImportPost;
use App\Models\TelegramImportChannel;
use App\Models\TelegramImportMedia;
use App\Models\TelegramImportPost;
use App\Services\TelegramMediaImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TelegramImportIngestController extends Controller
{
    public function channels(Request $request)
    {
        $this->authorizeListener($request);
        return TelegramImportChannel::query()->where('auto_import_enabled', true)->whereIn('status', ['active', 'sync_requested'])->get(['id', 'telegram_channel_id', 'username', 'access_method', 'initial_import_mode', 'initial_import_limit', 'initial_from', 'initial_to', 'last_processed_message_id', 'status']);
    }

    public function ingest(Request $request, TelegramMediaImportService $mediaImporter)
    {
        $this->authorizeListener($request);
        $data = $request->validate([
            'channel_id' => ['required', 'integer', 'exists:telegram_import_channels,id'], 'telegram_message_id' => ['required', 'integer'],
            'grouped_id' => ['nullable', 'string', 'max:100'], 'source_url' => ['nullable', 'string', 'max:2048'], 'original_text' => ['nullable', 'string', 'max:200000'],
            'original_date' => ['nullable', 'date'], 'edited_date' => ['nullable', 'date'], 'event_type' => ['required', 'in:new,album,edited,history'],
            'payload' => ['nullable', 'string'], 'file_ids' => ['nullable', 'array', 'max:12'], 'file_ids.*' => ['string', 'max:255'],
            'media' => ['nullable', 'array', 'max:12'], 'media.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $channel = TelegramImportChannel::findOrFail($data['channel_id']);
        abort_if(!$channel->auto_import_enabled || $channel->status === 'paused', 409, 'Канал приостановлен.');

        $post = !empty($data['grouped_id'])
            ? TelegramImportPost::where('channel_id', $channel->id)->where('grouped_id', $data['grouped_id'])->first()
            : null;
        $post ??= TelegramImportPost::firstOrNew(['channel_id' => $channel->id, 'telegram_message_id' => $data['telegram_message_id']]);
        if ($post->exists && $data['event_type'] === 'edited') {
            $versions = $post->previous_versions ?: [];
            $versions[] = ['text' => $post->original_text, 'normalized_data' => $post->normalized_data, 'saved_at' => now()->toIso8601String()];
            $post->previous_versions = array_slice($versions, -20);
            if ($post->product_id) $post->status = 'review';
        }
        $post->fill([
            'grouped_id' => $data['grouped_id'] ?? null, 'source_url' => $data['source_url'] ?? null, 'original_text' => $data['original_text'] ?? '',
            'raw_payload' => isset($data['payload']) ? json_decode($data['payload'], true) : null, 'original_date' => isset($data['original_date']) ? Carbon::parse($data['original_date']) : now(),
            'edited_date' => isset($data['edited_date']) ? Carbon::parse($data['edited_date']) : null, 'status' => $post->product_id ? $post->status : 'new', 'error_message' => null,
        ])->save();

        foreach ($request->file('media', []) as $index => $file) {
            $fileId = $data['file_ids'][$index] ?? hash_file('sha256', $file->getRealPath());
            if (TelegramImportMedia::where(['post_id' => $post->id, 'telegram_file_identifier' => $fileId])->exists()) continue;
            $stored = $mediaImporter->import($file);
            TelegramImportMedia::create(['post_id' => $post->id, 'telegram_file_identifier' => $fileId, 'attachment_id' => $stored['attachment_id'], 'storage_data' => $stored['image'], 'content_hash' => $stored['content_hash'], 'sort_order' => $index]);
        }

        $channel->update(['last_processed_message_id' => max((int) $channel->last_processed_message_id, (int) $post->telegram_message_id), 'last_publication_at' => $post->original_date, 'last_sync_at' => now(), 'last_error' => null, 'status' => 'active']);
        if (!$post->product_id) ProcessTelegramImportPost::dispatch($post->id);
        return response()->json(['post_id' => $post->id, 'status' => $post->status, 'duplicate' => !$post->wasRecentlyCreated], $post->wasRecentlyCreated ? 201 : 200);
    }

    public function error(Request $request)
    {
        $this->authorizeListener($request);
        $data = $request->validate(['channel_id' => ['required', 'integer', 'exists:telegram_import_channels,id'], 'message' => ['required', 'string', 'max:5000']]);
        TelegramImportChannel::whereKey($data['channel_id'])->update(['last_error' => $data['message'], 'status' => 'error']);
        return response()->noContent();
    }

    public function complete(Request $request, TelegramImportChannel $channel)
    {
        $this->authorizeListener($request);
        $channel->update(['status' => 'active', 'last_sync_at' => now(), 'last_error' => null, 'initial_import_mode' => 'none']);
        return response()->noContent();
    }

    private function authorizeListener(Request $request): void
    {
        $expected = (string) config('services.telegram_import.internal_token');
        $actual = (string) $request->bearerToken();
        abort_unless($expected !== '' && $actual !== '' && hash_equals($expected, $actual), 401);
    }
}
