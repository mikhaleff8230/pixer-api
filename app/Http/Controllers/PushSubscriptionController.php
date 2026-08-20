<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function publicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => config('webpush.public_key'),
            'configured' => app(WebPushService::class)->isConfigured(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:512'],
            'keys.auth' => ['required', 'string', 'max:512'],
            'content_encoding' => ['nullable', 'string', 'max:32'],
        ]);

        abort_unless(app(WebPushService::class)->isConfigured(), 503, 'Web Push is not configured.');

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aesgcm',
                'user_agent' => (string) $request->userAgent(),
                'last_used_at' => now(),
            ]
        );

        return response()->json(['subscribed' => true, 'id' => $subscription->id], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'url', 'max:2048']]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['subscribed' => false]);
    }

    public function test(Request $request, WebPushService $webPush): JsonResponse
    {
        $sent = $webPush->sendToUser($request->user()->id, [
            'title' => 'SANCAN',
            'body' => 'Тестовое уведомление работает.',
            'url' => '/messages',
            'tag' => 'sancan-test-push',
        ]);

        return response()->json(['sent' => $sent]);
    }
}
