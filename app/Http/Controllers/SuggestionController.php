<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\Suggestion;
use App\Models\Form;
use App\Models\Topic;
use App\Models\TopicKeyword;
use App\Models\TopicModelingSession;
use App\Http\Resources\SuggestionResource;
use App\Concerns\Pagination;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;

class SuggestionController extends Controller
{
    use Pagination;

    public function index(Request $request, int $formId): JsonResponse
    {
        $query = Suggestion::query()
            ->with('student:id,email,profile_picture')
            ->where('form_id', $formId);

        if ($request->has('start_date') && $request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date') && $request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $query->latest();

        return $this->paginateWithResource(
            $query,
            SuggestionResource::class,
            $request,
            [
                'per_page' => 15,
                'max_per_page' => 100,
                'allowed_sort_columns' => ['id', 'created_at'],
                'default_sort' => [
                    'column' => 'created_at',
                    'direction' => 'desc'
                ],
            ],
            ['suggestion', 'student.email'],
        );
    }

    public function analyzeTopics(Request $request, int $formId): JsonResponse
    {
        try {
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            $form = Form::findOrFail($formId);

            $query = Suggestion::where('form_id', $formId);

            if ($request->has('start_date') && $request->start_date) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }

            if ($request->has('end_date') && $request->end_date) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            $suggestions = $query->latest()->get();

            if ($suggestions->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'No suggestions found for the selected date range',
                    'data' => null,
                    'meta' => [
                        'total_analyzed' => 0,
                        'date_range' => [
                            'start' => $request->start_date,
                            'end' => $request->end_date,
                        ]
                    ]
                ], 404);
            }

            $documents = $suggestions->pluck('suggestion')->toArray();
            $pythonServiceUrl = config('services.python_bertopic.url');

            /** @var Response $response */
            $response = Http::timeout(60)->post($pythonServiceUrl, [
                'documents' => $documents,
            ]);

            if (!$response->successful()) {
                throw new \Exception('Python service returned an error: ' . $response->body(), $response->status());
            }

            $topicData = $response->json();

            $existingSession = TopicModelingSession::whereJsonContains('model_parameters->form_id', $formId)
                ->where(function ($query) use ($request) {
                    $query->whereJsonContains('model_parameters->date_range->start', $request->start_date)
                        ->whereJsonContains('model_parameters->date_range->end', $request->end_date);
                })
                ->with(['topics.keywords'])
                ->first();

            $responseData = [
                'success' => true,
                'message' => 'Analysis completed. Review and save when ready.',
                'preview' => [
                    'total_topics' => $topicData['total_topics'],
                    'total_documents' => $topicData['total_documents'],
                    'outliers' => $topicData['outliers'],
                    'topics' => collect($topicData['summary'])->map(function ($topic) {
                        return [
                            'topic_id' => $topic['topic_id'],
                            'label' => $topic['label'],
                            'document_count' => $topic['count'],
                            'representation_score' => $topic['representation_score'],
                            'keywords' => $topic['top_keywords'],
                        ];
                    }),
                ],
                'meta' => [
                    'form_id' => $formId,
                    'form_title' => $form->title,
                    'date_range' => [
                        'start' => $request->start_date,
                        'end' => $request->end_date,
                    ],
                    'total_analyzed' => count($suggestions),
                ]
            ];

            if ($existingSession) {
                $responseData['duplicate_detected'] = true;
                $responseData['comparison'] = [
                    'existing_session' => [
                        'id' => $existingSession->id,
                        'name' => $existingSession->name,
                        'total_topics' => $existingSession->total_topics,
                        'total_documents' => $existingSession->total_documents,
                        'outliers' => $existingSession->outliers,
                        'created_at' => $existingSession->created_at,
                        'topics_preview' => $existingSession->topics->take(5)->map(function ($topic) {
                            return [
                                'label' => $topic->label,
                                'document_count' => $topic->document_count,
                                'keywords' => $topic->keywords->take(5)->pluck('keyword')->toArray(),
                            ];
                        }),
                    ],
                    'differences' => [
                        'topic_count_change' => $topicData['total_topics'] - $existingSession->total_topics,
                        'document_count_change' => $topicData['total_documents'] - $existingSession->total_documents,
                        'outlier_change' => $topicData['outliers'] - $existingSession->outliers,
                        'analysis_age' => $existingSession->created_at->diffForHumans(),
                    ],
                ];
                $responseData['message'] = 'A session with this date range already exists. Review the comparison and choose an action.';
            }

            return response()->json($responseData, 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error analyzing topics',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    public function saveTopicSession(Request $request, int $formId): JsonResponse
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'session_name' => 'nullable|string|max:255',
                'action' => 'nullable|in:keep_both,replace',
            ]);

            $form = Form::findOrFail($formId);

            $query = Suggestion::where('form_id', $formId);

            if ($request->has('start_date') && $request->start_date) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }

            if ($request->has('end_date') && $request->end_date) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            $suggestions = $query->latest()->get();

            if ($suggestions->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'No suggestions found for the selected date range',
                ], 404);
            }

            $documents = $suggestions->pluck('suggestion')->toArray();
            $suggestionIds = $suggestions->pluck('id')->toArray();

            $pythonServiceUrl = config('services.python_bertopic.url');

            /** @var Response $response */
            $response = Http::timeout(60)->post($pythonServiceUrl, [
                'documents' => $documents,
            ]);

            if (!$response->successful()) {
                throw new \Exception('Python service returned an error: ' . $response->body(), $response->status());
            }

            $topicData = $response->json();

            $existingSession = TopicModelingSession::whereJsonContains('model_parameters->form_id', $formId)
                ->where(function ($query) use ($request) {
                    $query->whereJsonContains('model_parameters->date_range->start', $request->start_date)
                        ->whereJsonContains('model_parameters->date_range->end', $request->end_date);
                })
                ->first();

            if ($existingSession && $request->action === 'replace') {
                $topicIds = $existingSession->topics()->pluck('id')->toArray();

                if (!empty($topicIds)) {
                    DB::table('document_topics')->whereIn('topic_id', $topicIds)->delete();
                    TopicKeyword::whereIn('topic_id', $topicIds)->delete();
                    Topic::whereIn('id', $topicIds)->delete();
                }

                $existingSession->delete();
            }

            $sessionName = $request->session_name ?? sprintf(
                '%s - Analysis %s',
                $form->title,
                now()->format('Y-m-d H:i')
            );

            $session = TopicModelingSession::create([
                'name' => $sessionName,
                'source_type' => 'suggestions',
                'total_topics' => $topicData['total_topics'],
                'total_documents' => $topicData['total_documents'],
                'outliers' => $topicData['outliers'],
                'model_parameters' => [
                    'form_id' => $formId,
                    'date_range' => [
                        'start' => $request->start_date,
                        'end' => $request->end_date,
                    ],
                ],
                'status' => 'completed',
            ]);

            // Save topics
            $topicIdMapping = [];

            foreach ($topicData['summary'] as $topicSummary) {
                $topic = Topic::create([
                    'session_id' => $session->id,
                    'topic_id' => $topicSummary['topic_id'],
                    'original_name' => $topicSummary['original_name'],
                    'label' => $topicSummary['label'],
                    'language' => $topicSummary['language'],
                    'document_count' => $topicSummary['count'],
                    'representation_score' => $topicSummary['representation_score'],
                ]);

                $topicIdMapping[$topicSummary['topic_id']] = $topic->id;

                // Save keywords
                foreach ($topicSummary['top_keywords'] as $index => $keyword) {
                    TopicKeyword::create([
                        'topic_id' => $topic->id,
                        'keyword' => $keyword,
                        'rank' => $index + 1,
                    ]);
                }
            }

            // Save document-topic relationships
            if (isset($topicData['document_topics']) && is_array($topicData['document_topics'])) {
                foreach ($topicData['document_topics'] as $index => $docTopicInfo) {
                    if (isset($suggestionIds[$index])) {
                        $pythonTopicId = $docTopicInfo['topic_id'];

                        // Skip outliers (topic_id = -1)
                        if ($pythonTopicId === -1) {
                            continue;
                        }

                        if (isset($topicIdMapping[$pythonTopicId])) {
                            DB::table('document_topics')->insert([
                                'suggestion_id' => $suggestionIds[$index],
                                'topic_id' => $topicIdMapping[$pythonTopicId],
                                'probability' => $docTopicInfo['probability'] ?? null,
                                'is_primary' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            $session->load(['topics.keywords']);

            return response()->json([
                'success' => true,
                'message' => $existingSession && $request->action === 'replace'
                    ? 'Session replaced successfully'
                    : 'Topic modeling session saved successfully',
                'data' => [
                    'session' => [
                        'id' => $session->id,
                        'name' => $session->name,
                        'total_topics' => $session->total_topics,
                        'total_documents' => $session->total_documents,
                        'outliers' => $session->outliers,
                        'created_at' => $session->created_at,
                    ],
                    'topics' => $session->topics->map(function ($topic) {
                        return [
                            'id' => $topic->id,
                            'topic_id' => $topic->topic_id,
                            'label' => $topic->label,
                            'document_count' => $topic->document_count,
                            'representation_score' => $topic->representation_score,
                            'keywords' => $topic->keywords->pluck('keyword')->toArray(),
                        ];
                    }),
                ],
                'meta' => [
                    'form_id' => $formId,
                    'form_title' => $form->title,
                    'date_range' => [
                        'start' => $request->start_date,
                        'end' => $request->end_date,
                    ],
                    'action_taken' => $request->action ?? 'new',
                ]
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error saving topic session',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    public function getTopicSessions(int $formId): JsonResponse
    {
        try {
            Form::findOrFail($formId);

            $sessions = TopicModelingSession::whereJsonContains('model_parameters->form_id', $formId)
                ->with(['topics.keywords'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $sessions->map(function ($session) {
                    return [
                        'id' => $session->id,
                        'name' => $session->name,
                        'total_topics' => $session->total_topics,
                        'total_documents' => $session->total_documents,
                        'outliers' => $session->outliers,
                        'status' => $session->status,
                        'created_at' => $session->created_at,
                        'topics_preview' => $session->topics->take(5)->map(function ($topic) {
                            return [
                                'label' => $topic->label,
                                'count' => $topic->document_count,
                            ];
                        }),
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching topic sessions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getTopicSessionDetails(int $formId, int $sessionId): JsonResponse
    {
        try {
            $session = TopicModelingSession::with(['topics.keywords', 'topics.suggestions.student'])
                ->findOrFail($sessionId);

            if ($session->model_parameters['form_id'] ?? null !== $formId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session does not belong to this form'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'session' => [
                        'id' => $session->id,
                        'name' => $session->name,
                        'total_topics' => $session->total_topics,
                        'total_documents' => $session->total_documents,
                        'outliers' => $session->outliers,
                        'created_at' => $session->created_at,
                    ],
                    'topics' => $session->topics->map(function ($topic) {
                        return [
                            'id' => $topic->id,
                            'topic_id' => $topic->topic_id,
                            'label' => $topic->label,
                            'original_name' => $topic->original_name,
                            'language' => $topic->language,
                            'document_count' => $topic->document_count,
                            'representation_score' => $topic->representation_score,
                            'keywords' => $topic->keywords->pluck('keyword')->toArray(),
                            'sample_suggestions' => $topic->suggestions->take(3)->map(function ($suggestion) {
                                return [
                                    'id' => $suggestion->id,
                                    'suggestion' => $suggestion->suggestion,
                                    'is_anonymous' => $suggestion->is_anonymous,
                                    'student' => $suggestion->is_anonymous ? null : [
                                        'email' => $suggestion->student->email ?? null,
                                    ],
                                    'created_at' => $suggestion->created_at,
                                ];
                            }),
                        ];
                    }),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching session details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request, string $slug)
    {
        $form = Form::where('slug', $slug)
            ->whereRaw('is_active IS TRUE')
            ->firstOrFail();

        $validated = $request->validate([
            'suggestion' => 'required|string|max:5000',
            'is_anonymous' => 'boolean',
        ]);

        Suggestion::create([
            'form_id' => $form->id,
            'student_id' => Auth::id(),
            'suggestion' => $validated['suggestion'],
            'is_anonymous' => (bool) ($validated['is_anonymous'] ?? false),
        ]);

        return redirect()->back()->with('success', 'Your suggestion has been submitted successfully!');
    }
}
