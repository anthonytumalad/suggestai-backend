<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Services\TopicModelingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TopicAnalysisController extends Controller
{
    public function __construct(private TopicModelingService $service) {}

    public function analyze(Request $request, int $formId): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $form      = Form::findOrFail($formId);
        $startDate = $request->start_date;
        $endDate   = $request->end_date;

        $existingSession = $this->service->findExistingSession($formId, $startDate, $endDate);

        if ($existingSession) {
            return response()->json([
                'success'            => false,
                'duplicate_detected' => true,
                'message'            => 'A summary for this date range already exists. Clear it first before generating a new one.',
                'existing_session'   => [
                    'id'              => $existingSession->id,
                    'name'            => $existingSession->name,
                    'total_topics'    => $existingSession->total_topics,
                    'total_documents' => $existingSession->total_documents,
                    'created_at'      => $existingSession->created_at,
                ],
            ], 409);
        }

        $suggestions = $this->service->suggestionQuery($formId, $startDate, $endDate)->get();

        if ($suggestions->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No suggestions found for the selected date range.',
                'data'    => null,
                'meta'    => [
                    'total_analyzed' => 0,
                    'date_range'     => ['start' => $startDate, 'end' => $endDate],
                ],
            ], 404);
        }

        try {
            $topicData = $this->service->callPythonService(
                $suggestions->pluck('suggestion')->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Analyze Topics — Python service error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error analyzing topics',
                'error'   => $e->getMessage(),
                'trace'   => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }

        $existingSession = $this->service->findExistingSession($formId, $startDate, $endDate);

        $responseData = [
            'success' => true,
            'message' => 'Analysis completed. Review and save when ready.',
            'preview' => [
                'total_topics'    => $topicData['total_topics'],
                'total_documents' => $topicData['total_documents'],
                'outliers'        => $topicData['outliers'],
                'topics'          => collect($topicData['summary'])->map(fn($topic) => [
                    'topic_id'             => $topic['topic_id'],
                    'label'                => $topic['label'],
                    'document_count'       => $topic['count'],
                    'representation_score' => $topic['representation_score'],
                    'keywords'             => $topic['top_keywords'],
                ]),
            ],
            'meta' => [
                'form_id'        => $formId,
                'form_title'     => $form->title,
                'date_range'     => ['start' => $startDate, 'end' => $endDate],
                'total_analyzed' => $suggestions->count(),
            ],
        ];

        if ($existingSession) {
            $responseData['duplicate_detected'] = true;
            $responseData['message']            = 'A session with this date range already exists. Review and choose an action.';
            $responseData['comparison']         = [
                'existing_session' => [
                    'id'              => $existingSession->id,
                    'name'            => $existingSession->name,
                    'total_topics'    => $existingSession->total_topics,
                    'total_documents' => $existingSession->total_documents,
                    'outliers'        => $existingSession->outliers,
                    'created_at'      => $existingSession->created_at,
                    'topics_preview'  => $existingSession->topics->take(5)->map(fn($topic) => [
                        'label'          => $topic->label,
                        'document_count' => $topic->document_count,
                        'keywords'       => $topic->keywords->take(5)->pluck('keyword'),
                    ]),
                ],
                'differences' => [
                    'topic_count_change'    => $topicData['total_topics'] - $existingSession->total_topics,
                    'document_count_change' => $topicData['total_documents'] - $existingSession->total_documents,
                    'outlier_change'        => $topicData['outliers'] - $existingSession->outliers,
                    'analysis_age'          => $existingSession->created_at->diffForHumans(),
                ],
            ];
        }

        return response()->json($responseData, 200);
    }


    public function save(Request $request, int $formId): JsonResponse
    {
        $request->validate([
            'start_date'   => 'nullable|date',
            'end_date'     => 'nullable|date|after_or_equal:start_date',
            'session_name' => 'nullable|string|max:255',
            'action'       => 'nullable|in:keep_both,replace',
        ]);

        $form        = Form::findOrFail($formId);
        $startDate   = $request->start_date;
        $endDate     = $request->end_date;
        $minRequired = 10;

        $suggestions = $this->service->suggestionQuery($formId, $startDate, $endDate)->get();

        if ($suggestions->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No suggestions found for the selected date range.',
                'meta'    => [
                    'total_analyzed'   => 0,
                    'minimum_required' => $minRequired,
                    'date_range'       => ['start' => $startDate, 'end' => $endDate],
                ],
            ], 404);
        }

        if ($suggestions->count() < $minRequired) {
            return response()->json([
                'success' => false,
                'message' => "At least {$minRequired} suggestions are needed, but only {$suggestions->count()} were found.",
                'meta'    => [
                    'total_analyzed'   => $suggestions->count(),
                    'minimum_required' => $minRequired,
                    'date_range'       => ['start' => $startDate, 'end' => $endDate],
                ],
            ], 422);
        }

        try {
            $topicData = $this->service->callPythonService(
                $suggestions->pluck('suggestion')->toArray()
            );

            $existingSession = $this->service->findExistingSession($formId, $startDate, $endDate);

            if ($existingSession && $request->action === 'replace') {
                $this->service->deleteSession($existingSession);
            }

            $session = $this->service->persistSession(
                form:          $form,
                topicData:     $topicData,
                suggestionIds: $suggestions->pluck('id')->toArray(),
                startDate:     $startDate,
                endDate:       $endDate,
                sessionName:   $request->session_name,
            );
        } catch (\Exception $e) {
            Log::error('Save Topic Session Error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error saving topic session',
                'error'   => $e->getMessage(),
                'trace'   => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $request->action === 'replace'
                ? 'Session replaced successfully.'
                : 'Topic modeling session saved successfully.',
            'data' => [
                'session' => [
                    'id'              => $session->id,
                    'name'            => $session->name,
                    'total_topics'    => $session->total_topics,
                    'total_documents' => $session->total_documents,
                    'outliers'        => $session->outliers,
                    'created_at'      => $session->created_at,
                ],
                'topics' => $session->topics->map(fn($topic) => [
                    'id'                   => $topic->id,
                    'topic_id'             => $topic->topic_id,
                    'label'                => $topic->label,
                    'document_count'       => $topic->document_count,
                    'representation_score' => $topic->representation_score,
                    'keywords'             => $topic->keywords->pluck('keyword'),
                ]),
            ],
            'meta' => [
                'form_id'      => $formId,
                'form_title'   => $form->title,
                'date_range'   => ['start' => $startDate, 'end' => $endDate],
                'action_taken' => $request->action ?? 'new',
            ],
        ], 201);
    }
}
