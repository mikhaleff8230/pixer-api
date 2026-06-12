<?php

namespace App\Services\Ai;

class GeminiService
{
    public function __call(string $method, array $arguments): array
    {
        return [
            'provider' => 'gemini',
            'status' => 'stub',
            'message' => 'Gemini provider is prepared for future implementation.',
            'method' => $method,
        ];
    }
}