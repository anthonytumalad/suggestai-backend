<?php

namespace App\Jobs;

use App\Models\Form;
use App\Models\TopicAnalysisResult;
use App\Services\TopicModelingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunTopicAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int     $formId,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly int     $userId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TopicModelingService $service): void
    {
        $form        = Form::findOrFail($this->formId);
        $suggestions = $service->suggestionQuery($this->formId, $this->startDate, $this->endDate)->get();

        if ($suggestions->isEmpty()) return;

        $topicData = $service->callPythonService(
            $suggestions->pluck('suggestion')->toArray()
        );

        cache()->put(
            $this->cacheKey(),
            [
                'topic_data'      => $topicData,
                'suggestion_ids'  => $suggestions->pluck('id')->toArray(),
                'form_title'      => $form->title,
                'total_analyzed'  => $suggestions->count(),
            ],
            now()->addHour()
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RunTopicAnalysis job failed', [
            'formId'  => $this->formId,
            'message' => $e->getMessage(),
        ]);
    }

    public function cacheKey(): string
    {
        return "topic_analysis:{$this->formId}:{$this->startDate}:{$this->endDate}";
    }
}
