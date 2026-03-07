<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Suggestion;
use App\Models\TopicModelingSession;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $now = now();

        return response()->json([
            'stats' => [
                'total_forms'       => Form::count(),
                'total_suggestions' => Suggestion::count(),
                'this_month'        => Suggestion::whereMonth('created_at', $now->month)
                    ->whereYear('created_at', $now->year)
                    ->count(),
                'total_sessions'    => TopicModelingSession::count(),
                'this_week'         => Suggestion::where('created_at', '>=', $now->startOfWeek())
                    ->count(),
            ],

            'timeline' => Suggestion::selectRaw("DATE(created_at) as date, COUNT(*) as count")
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('date')
                ->orderBy('date')
                ->get(),

            'per_form' => Form::withCount('suggestions')
                ->orderByDesc('suggestions_count')
                ->limit(6)
                ->get(['id', 'title', 'is_active']),

            'anonymous_ratio' => [
                'anonymous'  => Suggestion::where('is_anonymous', true)->count(),
                'identified' => Suggestion::where('is_anonymous', false)->count(),
            ],

            'recent_suggestions' => Suggestion::with([
                'form:id,title',
                'student:id,email'
            ])
                ->latest()
                ->limit(8)
                ->get(['id', 'form_id', 'student_id', 'suggestion', 'is_anonymous', 'created_at']),
        ]);
    }
}
