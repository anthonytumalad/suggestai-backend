<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Form;
use App\Models\TopicModelingSession;

class TopicSessionController extends Controller
{
    public function index(int $formId): JsonResponse
    {
        try {
            Form::findOrFail($formId);

            $sessions = TopicModelingSession::query()
                ->where('form_id', $formId)
                ->with(['topics.keywords'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $sessions->map(fn($session) => [
                    'id'              => $session->id,
                    'name'            => $session->name,
                    'total_topics'    => $session->total_topics,
                    'total_documents' => $session->total_documents,
                    'outliers'        => $session->outliers,
                    'status'          => $session->status,
                    'created_at'      => $session->created_at,
                    'date_range'      => $session->model_parameters['date_range'] ?? ['start' => null, 'end' => null],
                    'topics'          => $session->topics->map(fn($topic) => [
                        'id'                   => $topic->id,
                        'topic_id'             => $topic->topic_id,
                        'label'                => $topic->label,
                        'document_count'       => $topic->document_count,
                        'representation_score' => $topic->representation_score,
                        'keywords'             => $topic->keywords->pluck('keyword'),
                    ]),
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching topic sessions',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $formId, int $sessionId): JsonResponse
    {
        try {
            $session = TopicModelingSession::with(['topics.keywords', 'topics.suggestions.student'])
                ->findOrFail($sessionId);

            if (($session->model_parameters['form_id'] ?? null) !== $formId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session does not belong to this form.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'session' => [
                        'id'              => $session->id,
                        'name'            => $session->name,
                        'total_topics'    => $session->total_topics,
                        'total_documents' => $session->total_documents,
                        'outliers'        => $session->outliers,
                        'created_at'      => $session->created_at,
                        'date_range'      => $session->model_parameters['date_range'] ?? ['start' => null, 'end' => null],
                    ],
                    'topics' => $session->topics->map(fn($topic) => [
                        'id'                   => $topic->id,
                        'topic_id'             => $topic->topic_id,
                        'label'                => $topic->label,
                        'original_name'        => $topic->original_name,
                        'language'             => $topic->language,
                        'document_count'       => $topic->document_count,
                        'representation_score' => $topic->representation_score,
                        'keywords'             => $topic->keywords->pluck('keyword'),
                        'sample_suggestions'   => $topic->suggestions->take(3)->map(fn($s) => [
                            'id'           => $s->id,
                            'suggestion'   => $s->suggestion,
                            'is_anonymous' => $s->is_anonymous,
                            'student'      => $s->is_anonymous ? null : ['email' => $s->student->email ?? null],
                            'created_at'   => $s->created_at,
                        ]),
                    ]),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching session details',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
