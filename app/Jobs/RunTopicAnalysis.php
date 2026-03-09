<?php

namespace App\Jobs;

use App\Models\Form;
use App\Services\TopicModelingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunTopicAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int     $formId,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly int     $userId,
    ) {}

    public function handle(TopicModelingService $service): void
    {
        Log::info('RunTopicAnalysis started', [
            'formId'    => $this->formId,
            'startDate' => $this->startDate,
            'endDate'   => $this->endDate,
        ]);

        // Mark as running so the frontend status endpoint can reflect this
        Cache::put($this->cacheKey(), ['status' => 'running'], now()->addHour());

        $form        = Form::findOrFail($this->formId);
        $suggestions = $service->suggestionQuery($this->formId, $this->startDate, $this->endDate)->get();

        if ($suggestions->isEmpty()) {
            Log::warning('RunTopicAnalysis: no suggestions found', ['formId' => $this->formId]);
            Cache::put($this->cacheKey(), ['status' => 'empty'], now()->addHour());
            return;
        }

        Log::info('RunTopicAnalysis: calling Python service', [
            'formId' => $this->formId,
            'count'  => $suggestions->count(),
        ]);

        $topicData = $service->callPythonService(
            $suggestions->pluck('suggestion')->toArray()
        );

        Cache::put(
            $this->cacheKey(),
            [
                'status'         => 'complete',
                'topic_data'     => $topicData,
                'suggestion_ids' => $suggestions->pluck('id')->toArray(),
                'form_title'     => $form->title,
                'total_analyzed' => $suggestions->count(),
            ],
            now()->addHour()
        );

        Log::info('RunTopicAnalysis completed', [
            'formId'      => $this->formId,
            'totalTopics' => $topicData['total_topics'] ?? 0,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RunTopicAnalysis job failed', [
            'formId'  => $this->formId,
            'message' => $e->getMessage(),
            'trace'   => $e->getTraceAsString(),
        ]);

        // Write a failed status to cache so the frontend stops polling
        Cache::put(
            $this->cacheKey(),
            [
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ],
            now()->addHour()
        );
    }

    public function cacheKey(): string
    {
        return "topic_analysis:{$this->formId}:{$this->startDate}:{$this->endDate}";
    }
}
