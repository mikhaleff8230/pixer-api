<?php

namespace App\Jobs;

use App\Models\AiServiceJob;
use App\Services\AiServiceManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAiServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $aiServiceJobId)
    {
    }

    public function handle(AiServiceManager $manager): void
    {
        $job = AiServiceJob::findOrFail($this->aiServiceJobId);

        try {
            $job->update([
                'status' => 'processing',
                'started_at' => $job->started_at ?: now(),
            ]);

            $manager->processJob($job);
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $manager->refundJob($job->fresh(), $e->getMessage());
        }
    }
}