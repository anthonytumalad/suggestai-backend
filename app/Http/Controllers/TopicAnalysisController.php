<?php

namespace App\Http\Controllers;

use App\Jobs\RunTopicAnalysis;
use App\Models\Form;
use App\Services\TopicModelingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TopicAnalysisController extends Controller
{
    private const MIN_BLOCK = 50;
    private const MIN_WARN  = 150;

    public function __construct(private TopicModelingService $service) {}


    private function validateDateRange(Request $request): void
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);
    }

    private function cacheKey(int $formId, ?string $start, ?string $end): string
    {
        return "topic_analysis:{$formId}:{$start}:{$end}";
    }


    public function analyze(Request $request, int $formId): JsonResponse
    {
        $this->validateDateRange($request);

        $form      = Form::findOrFail($formId);
        $startDate = $request->start_date;
        $endDate   = $request->end_date;

        $existing = $this->service->findExistingSession($formId, $startDate, $endDate);
        if ($existing) {
            return response()->json([
                'success'            => false,
                'duplicate_detected' => true,
                'message'            => 'A summary for this date range already exists.',
                'existing_session'   => $this->formatSession($existing),
            ], 409);
        }

        $suggestions = $this->service->suggestionQuery($formId, $startDate, $endDate)->get();
        if ($suggestions->isEmpty()) {
            return $this->noSuggestionsResponse($startDate, $endDate);
        }

        $count = $suggestions->count();

        // Hard block — too few for BERTopic to produce meaningful results
        if ($count < self::MIN_BLOCK) {
            return response()->json([
                'success' => false,
                'message' => 'Not enough responses yet. At least ' . self::MIN_BLOCK . ' suggestions are required to run analysis.',
                'meta'    => [
                    'total_analyzed' => $count,
                    'minimum_required' => self::MIN_BLOCK,
                    'date_range'     => ['start' => $startDate, 'end' => $endDate],
                ],
            ], 422);
        }

        RunTopicAnalysis::dispatch($formId, $startDate, $endDate, Auth::id());

        $response = [
            'success' => true,
            'message' => 'Analysis started. Poll /analyze/status to check progress.',
            'meta'    => [
                'form_id'        => $formId,
                'form_title'     => $form->title,
                'total_analyzed' => $count,
                'date_range'     => ['start' => $startDate, 'end' => $endDate],
            ],
        ];

        // Soft warn — analysis will run but results may be limited
        if ($count < self::MIN_WARN) {
            $response['warning'] = 'Results may be limited with fewer than ' . self::MIN_WARN . ' suggestions. Topics identified may not be fully representative.';
        }

        return response()->json($response, 202);
    }


    public function status(Request $request, int $formId): JsonResponse
    {
        $startDate = $request->start_date;
        $endDate   = $request->end_date;
        $cached    = cache()->get($this->cacheKey($formId, $startDate, $endDate));

        if (!$cached) {
            return response()->json(['status' => 'pending'], 202);
        }

        $topicData = $cached['topic_data'];
        $form      = Form::findOrFail($formId);
        $existing  = $this->service->findExistingSession($formId, $startDate, $endDate);

        $response = [
            'status'  => 'ready',
            'success' => true,
            'message' => 'Analysis completed. Review and save when ready.',
            'preview' => $this->formatPreview($topicData),
            'meta'    => [
                'form_id'        => $formId,
                'form_title'     => $form->title,
                'date_range'     => ['start' => $startDate, 'end' => $endDate],
                'total_analyzed' => $cached['total_analyzed'],
            ],
        ];

        if ($existing) {
            $response['duplicate_detected'] = true;
            $response['message']            = 'A session with this date range already exists.';
            $response['comparison']         = $this->formatComparison($topicData, $existing);
        }

        return response()->json($response);
    }


    public function save(Request $request, int $formId): JsonResponse
    {
        $request->validate([
            'start_date'   => 'nullable|date',
            'end_date'     => 'nullable|date|after_or_equal:start_date',
            'session_name' => 'nullable|string|max:255',
            'action'       => 'nullable|in:keep_both,replace',
        ]);

        $form      = Form::findOrFail($formId);
        $startDate = $request->start_date;
        $endDate   = $request->end_date;
        $cached    = cache()->get($this->cacheKey($formId, $startDate, $endDate));

        if (!$cached) {
            return response()->json([
                'success' => false,
                'message' => 'No analysis found. Run analyze first.',
            ], 422);
        }

        $count = count($cached['suggestion_ids']);

        if ($count < self::MIN_BLOCK) {
            return response()->json([
                'success' => false,
                'message' => 'At least ' . self::MIN_BLOCK . ' suggestions are required.',
                'meta'    => ['total_analyzed' => $count],
            ], 422);
        }

        try {
            $existing = $this->service->findExistingSession($formId, $startDate, $endDate);
            if ($existing && $request->action === 'replace') {
                $this->service->deleteSession($existing);
            }

            $session = $this->service->persistSession(
                form: $form,
                topicData: $cached['topic_data'],
                suggestionIds: $cached['suggestion_ids'],
                startDate: $startDate,
                endDate: $endDate,
                sessionName: $request->session_name,
            );

            cache()->forget($this->cacheKey($formId, $startDate, $endDate));
        } catch (\Exception $e) {
            return $this->serverError('Error saving topic session', $e);
        }

        return response()->json([
            'success' => true,
            'message' => $request->action === 'replace'
                ? 'Session replaced successfully.'
                : 'Topic session saved successfully.',
            'data' => $this->formatSavedSession($session),
            'meta' => [
                'form_id'      => $formId,
                'form_title'   => $form->title,
                'date_range'   => ['start' => $startDate, 'end' => $endDate],
                'action_taken' => $request->action ?? 'new',
            ],
        ], 201);
    }


    private function formatSession($session): array
    {
        return [
            'id'              => $session->id,
            'name'            => $session->name,
            'total_topics'    => $session->total_topics,
            'total_documents' => $session->total_documents,
            'outliers'        => $session->outliers,
            'created_at'      => $session->created_at,
        ];
    }

    private function formatPreview(array $topicData): array
    {
        return [
            'total_topics'    => $topicData['total_topics'],
            'total_documents' => $topicData['total_documents'],
            'outliers'        => $topicData['outliers'],
            'topics'          => collect($topicData['summary'])->map(fn($t) => [
                'topic_id'             => $t['topic_id'],
                'label'                => $t['label'],
                'document_count'       => $t['count'],
                'representation_score' => $t['representation_score'],
                'keywords'             => $t['top_keywords'],
            ]),
        ];
    }

    private function formatComparison(array $topicData, $existing): array
    {
        return [
            'existing_session' => [
                ...$this->formatSession($existing),
                'topics_preview' => $existing->topics->take(5)->map(fn($t) => [
                    'label'          => $t->label,
                    'document_count' => $t->document_count,
                    'keywords'       => $t->keywords->take(5)->pluck('keyword'),
                ]),
            ],
            'differences' => [
                'topic_count_change'    => $topicData['total_topics']    - $existing->total_topics,
                'document_count_change' => $topicData['total_documents'] - $existing->total_documents,
                'outlier_change'        => $topicData['outliers']        - $existing->outliers,
                'analysis_age'          => $existing->created_at->diffForHumans(),
            ],
        ];
    }

    private function formatSavedSession($session): array
    {
        return [
            'session' => $this->formatSession($session),
            'topics'  => $session->topics->map(fn($t) => [
                'id'                   => $t->id,
                'topic_id'             => $t->topic_id,
                'label'                => $t->label,
                'document_count'       => $t->document_count,
                'representation_score' => $t->representation_score,
                'keywords'             => $t->keywords->pluck('keyword'),
            ]),
        ];
    }

    private function noSuggestionsResponse(?string $start, ?string $end): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'No suggestions found for the selected date range.',
            'meta'    => ['total_analyzed' => 0, 'date_range' => ['start' => $start, 'end' => $end]],
        ], 404);
    }

    private function serverError(string $message, \Exception $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => $e->getMessage(),
            'trace'   => config('app.debug') ? $e->getTraceAsString() : null,
        ], 500);
    }
}
