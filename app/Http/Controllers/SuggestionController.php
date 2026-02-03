<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use App\Models\Suggestion;
use App\Models\Form;
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
            // Validate date filters
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            // Build query with the same date filters as index method
            $query = Suggestion::where('form_id', $formId);

            if ($request->has('start_date') && $request->start_date) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }

            if ($request->has('end_date') && $request->end_date) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            // Get filtered suggestions
            $suggestions = $query->latest()
                ->pluck('suggestion')
                ->toArray();

            if (empty($suggestions)) {
                return response()->json([
                    'message' => 'No suggestions found for the selected date range',
                    'data' => [],
                    'meta' => [
                        'total_analyzed' => 0,
                        'date_range' => [
                            'start' => $request->start_date,
                            'end' => $request->end_date,
                        ]
                    ]
                ]);
            }

            $pythonServiceUrl = config('services.python_bertopic.url');

            /** @var Response $response */
            $response = Http::timeout(60)->post($pythonServiceUrl, [
                'documents' => $suggestions,
            ]);

            if (!$response->successful()) {
                throw new \Exception('Python service returned an error: ' . $response->body(), $response->status());
            }

            return response()->json([
                'message' => 'Topic modeling completed',
                'data' => $response->json(),
                'meta' => [
                    'total_analyzed' => count($suggestions),
                    'date_range' => [
                        'start' => $request->start_date,
                        'end' => $request->end_date,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error analyzing topics',
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
