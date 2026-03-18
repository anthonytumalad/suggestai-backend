<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Suggestion;
use App\Models\Topic;
use App\Models\TopicKeyword;
use App\Models\TopicModelingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TopicModelingService
{
    public function findExistingSession(int $formId, ?string $startDate, ?string $endDate): ?TopicModelingSession
    {
        return TopicModelingSession::query()
            ->where('form_id', $formId)
            ->where(function ($query) use ($startDate) {
                $startDate === null
                    ? $query->whereRaw("(model_parameters->'date_range'->>'start') IS NULL")
                    : $query->whereRaw("(model_parameters->'date_range'->>'start') = ?", [$startDate]);
            })
            ->where(function ($query) use ($endDate) {
                $endDate === null
                    ? $query->whereRaw("(model_parameters->'date_range'->>'end') IS NULL")
                    : $query->whereRaw("(model_parameters->'date_range'->>'end') = ?", [$endDate]);
            })
            ->with(['topics.keywords'])
            ->first();
    }

    public function suggestionQuery(int $formId, ?string $startDate, ?string $endDate): Builder
    {
        $query = Suggestion::where('form_id', $formId);

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        return $query->latest();
    }


    /**
     * Send suggestions to the Python BERTopic service and return the result.
     *
     * @throws \Exception if the service returns a non-2xx response
     */
    public function callPythonService(array $documents): array
    {
        $url = config('services.python_bertopic.url');

        /** @var Response $response */
        $response = Http::timeout(300)->post($url, [
            'documents' => $documents,
        ]);

        if (!$response->successful()) {
            throw new \Exception(
                'Python service returned an error: ' . $response->body(),
                $response->status()
            );
        }

        return $response->json();
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    /**
     * Persist a topic modeling session along with its topics,
     * keywords, and document-topic relationships.
     *
     * Wrapped in a transaction — callers should NOT wrap again.
     *
     * @throws \Exception on failure (transaction is rolled back)
     */
    public function persistSession(
        Form    $form,
        array   $topicData,
        array   $suggestionIds,
        ?string $startDate,
        ?string $endDate,
        ?string $sessionName = null
    ): TopicModelingSession {
        return DB::transaction(function () use (
            $form,
            $topicData,
            $suggestionIds,
            $startDate,
            $endDate,
            $sessionName
        ) {
            $name = $sessionName ?? sprintf(
                '%s - %s',
                $form->title,
                $startDate && $endDate ? "{$startDate} to {$endDate}" : 'All Time'
            );

            $session = TopicModelingSession::create([
                'form_id'          => $form->id,
                'name'             => $name,
                'source_type'      => 'suggestions',
                'total_topics'     => $topicData['total_topics'],
                'total_documents'  => $topicData['total_documents'],
                'outliers'         => $topicData['outliers'],
                'model_parameters' => [
                    'form_id'    => $form->id,
                    'date_range' => ['start' => $startDate, 'end' => $endDate],
                ],
                'status' => 'completed',
            ]);

            $topicIdMapping = $this->saveTopicsAndKeywords($session, $topicData['summary']);

            $this->saveDocumentTopics($topicData['document_topics'] ?? [], $suggestionIds, $topicIdMapping);

            $session->load(['topics.keywords']);

            return $session;
        });
    }

    public function deleteSession(TopicModelingSession $session): void
    {
        $topicIds = $session->topics()->pluck('id')->toArray();

        if (!empty($topicIds)) {
            DB::table('document_topics')->whereIn('topic_id', $topicIds)->delete();
            TopicKeyword::whereIn('topic_id', $topicIds)->delete();
            Topic::whereIn('id', $topicIds)->delete();
        }

        $session->delete();
    }

    private function saveTopicsAndKeywords(TopicModelingSession $session, array $summary): array
    {
        $topicIdMapping = [];

        foreach ($summary as $topicSummary) {
            $topic = Topic::create([
                'session_id'           => $session->id,
                'topic_id'             => $topicSummary['topic_id'],
                'original_name'        => $topicSummary['original_name'],
                'label'                => $topicSummary['label'],
                'language'             => $topicSummary['language'],
                'document_count'       => $topicSummary['count'],
                'representation_score' => $topicSummary['representation_score'],
            ]);

            $topicIdMapping[$topicSummary['topic_id']] = $topic->id;

            $keywords = [];
            foreach ($topicSummary['top_keywords'] as $index => $keyword) {
                $keywords[] = [
                    'topic_id'   => $topic->id,
                    'keyword'    => $keyword,
                    'rank'       => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($keywords)) {
                TopicKeyword::insert($keywords);
            }
        }

        return $topicIdMapping;
    }

    private function saveDocumentTopics(array $documentTopics, array $suggestionIds, array $topicIdMapping): void
    {
        $inserts = [];

        foreach ($documentTopics as $index => $docTopicInfo) {
            $pythonTopicId = $docTopicInfo['topic_id'];

            if ($pythonTopicId === -1 || !isset($suggestionIds[$index], $topicIdMapping[$pythonTopicId])) {
                continue;
            }

            $inserts[] = [
                'suggestion_id' => $suggestionIds[$index],
                'topic_id'      => $topicIdMapping[$pythonTopicId],
                'probability'   => $docTopicInfo['probability'] ?? null,
                'is_primary'    => DB::raw('true'),
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }

        if (!empty($inserts)) {
            DB::table('document_topics')->insert($inserts);
        }
    }
}
