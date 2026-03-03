<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\TopicModelingSession;
use Illuminate\Http\JsonResponse;

class VisualizationController extends Controller
{
    public function distribution(int $formId, TopicModelingSession $session): JsonResponse
    {
        return response()->json([
            'labels' => $session->topics->pluck('label'),
            'data'   => $session->topics->map(fn($t) => [
                'label'    => $t->label,
                'count'    => $t->document_count,
                'score'    => $t->representation_score,
                'outliers' => $session->outliers,
            ]),
            'meta' => [
                'total_documents' => $session->total_documents,
                'total_topics'    => $session->total_topics,
                'outliers'        => $session->outliers,
                'outlier_percent' => round($session->outliers / $session->total_documents * 100, 1),
            ],
        ]);
    }

    public function keywords(int $formId, TopicModelingSession $session): JsonResponse
    {
        $session->load('topics.keywords');

        return response()->json([
            'topics' => $session->topics->map(fn($topic) => [
                'label'    => $topic->label,
                'topic_id' => $topic->topic_id,
                'words'    => $topic->keywords->map(fn($kw) => [
                    'text'  => $kw->keyword,
                    'value' => $kw->score,   // word cloud weight
                    'rank'  => $kw->rank,
                ]),
            ]),
        ]);
    }

    public function timeline(int $formId, TopicModelingSession $session): JsonResponse
    {
        $session->load('topics.suggestions');

        return response()->json([
            'topics' => $session->topics->map(fn($topic) => [
                'label' => $topic->label,
                'data'  => $topic->suggestions
                    ->groupBy(fn($s) => $s->created_at->format('Y-m-d'))
                    ->map(fn($group, $date) => [
                        'date'  => $date,
                        'count' => $group->count(),
                    ])
                    ->values(),
            ]),
        ]);
    }

    public function stats(int $formId, TopicModelingSession $session): JsonResponse
    {
        $session->load('topics.keywords');

        $topics = $session->topics;

        return response()->json([
            'total_topics'      => $session->total_topics,
            'total_documents'   => $session->total_documents,
            'outliers'          => $session->outliers,
            'outlier_percent'   => round($session->outliers / $session->total_documents * 100, 1),
            'avg_topic_size'    => round($topics->avg('document_count'), 1),
            'largest_topic'     => $topics->sortByDesc('document_count')->first()?->label,
            'top_keywords'      => $topics->flatMap(fn($t) => $t->keywords->take(3)->pluck('keyword'))->unique()->take(10)->values(),
        ]);
    }
}
