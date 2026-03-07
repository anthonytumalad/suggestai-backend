<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Suggestion;
use App\Models\Form;
use App\Http\Resources\SuggestionResource;
use App\Concerns\Pagination;
use Illuminate\Support\Facades\Auth;

class SuggestionController extends Controller
{
    use Pagination;
    public function index(Request $request, int $formId): JsonResponse
    {
        $query = Suggestion::query()
            ->with('student:id,email,profile_picture')
            ->where('form_id', $formId);

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('id', $term)
                    ->orWhere('suggestion', 'ilike', "%{$term}%")
                    ->orWhereHas(
                        'student',
                        fn($sq) =>
                        $sq->where('email', 'ilike', "%{$term}%")
                    );
            });
        }

        if ($request->filled('is_anonymous')) {
            $query->where('is_anonymous', filter_var($request->is_anonymous, FILTER_VALIDATE_BOOLEAN));
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

    public function store(Request $request, string $slug)
    {
        $form = Form::where('slug', $slug)
            ->whereRaw('is_active IS TRUE')
            ->firstOrFail();

        $validated = $request->validate([
            'suggestion'   => 'required|string|max:5000',
            'is_anonymous' => 'boolean',
        ]);

        Suggestion::create([
            'form_id'      => $form->id,
            'student_id'   => Auth::id(),
            'suggestion'   => $validated['suggestion'],
            'is_anonymous' => (bool) ($validated['is_anonymous'] ?? false),
        ]);

        return redirect()->back()->with('success', 'Your suggestion has been submitted successfully!');
    }

    public function destroy(int $formId, int $id): JsonResponse
    {
        $suggestion = Suggestion::where('form_id', $formId)
            ->findOrFail($id);

        $suggestion->delete();

        return response()->json(null, 204);
    }

    public function bulkDestroy(Request $request, int $formId): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        Suggestion::where('form_id', $formId)
            ->whereIn('id', $validated['ids'])
            ->delete();

        return response()->json(null, 204);
    }
}
