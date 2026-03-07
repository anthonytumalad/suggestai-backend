<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Form;
use App\Models\TopicModelingSession;

class TopicSessionController extends Controller
{
    public function index(Request $request, int $formId): JsonResponse
    {
        try {
            Form::findOrFail($formId);

            $sessions = TopicModelingSession::query()
                ->where('form_id', $formId)
                ->with(['topics.keywords'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn($session) => [
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
                ]);

            // Search
            if ($request->filled('search')) {
                $search = strtolower($request->search);
                $sessions = $sessions->filter(
                    fn($s) => str_contains(strtolower($s['name']), $search)
                );
            }

            // Status filter
            if ($request->filled('status')) {
                $sessions = $sessions->filter(
                    fn($s) => $s['status'] === $request->status
                );
            }

            // Topics range filter
            if ($request->filled('topics')) {
                $sessions = $sessions->filter(function ($s) use ($request) {
                    $n = $s['total_topics'] ?? 0;
                    return match ($request->topics) {
                        '1-5'  => $n >= 1  && $n <= 5,
                        '6-15' => $n >= 6  && $n <= 15,
                        '15+'  => $n > 15,
                        default => true,
                    };
                });
            }

            // Sort
            if ($request->filled('sort')) {
                $sessions = match ($request->sort) {
                    'oldest'           => $sessions->sortBy('created_at'),
                    'topics_desc'      => $sessions->sortByDesc('total_topics'),
                    'suggestions_desc' => $sessions->sortByDesc('total_documents'),
                    'outliers_desc'    => $sessions->sortByDesc('outliers'),
                    default            => $sessions->sortByDesc('created_at'), // newest
                };
            }

            // Paginate the collection manually
            $sessions  = $sessions->values();
            $page      = max(1, (int) $request->input('page', 1));
            $perPage   = max(1, min(100, (int) $request->input('per_page', 15)));
            $total     = $sessions->count();
            $items     = $sessions->slice(($page - 1) * $perPage, $perPage)->values();
            $lastPage  = (int) ceil($total / $perPage);

            return response()->json([
                'success' => true,
                'data'    => $items,
                'meta'    => [
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'total'        => $total,
                    'last_page'    => $lastPage,
                    'from'         => $total ? ($page - 1) * $perPage + 1 : null,
                    'to'           => $total ? min($page * $perPage, $total) : null,
                ],
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
