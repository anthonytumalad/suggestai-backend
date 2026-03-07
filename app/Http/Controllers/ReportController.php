<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\TopicModelingSession;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Concerns\Pagination;
use App\Jobs\GenerateReportJob;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    use Pagination;

    public function index(Request $request): JsonResponse
    {
        $query = Report::query()
            ->with(['form', 'session', 'generatedBy'])
            ->where('generated_by', $request->user()->id)
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->format, fn($q) => $q->where('format', $request->format))
            ->when($request->search, fn($q) => $q->where('title', 'ilike', "%{$request->search}%"))
            ->orderByDesc('created_at');

        return $this->paginate($query, $request);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'topic_session_id' => 'required|exists:topic_modeling_sessions,id',
            'format'           => 'sometimes|in:pdf,csv,xlsx',
        ]);

        $session = TopicModelingSession::with('topics')->findOrFail($request->topic_session_id);

        $report = Report::create([
            'form_id'          => $session->form_id,
            'topic_session_id' => $request->topic_session_id,
            'generated_by'     => $request->user()->id,
            'title'            => "Report — {$session->name}",
            'format'           => $request->format ?? 'pdf',
            'status'           => 'pending',
        ]);

        GenerateReportJob::dispatch($report);

        return response()->json($report, 201);
    }

    public function show(Report $report): JsonResponse
    {
        $report->load(['form', 'session', 'generatedBy']);

        return response()->json($report);
    }

    public function download(Report $report)
    {
        if ($report->status !== 'completed' || !$report->file_path) {
            return response()->json(['message' => 'Report is not ready yet.'], 422);
        }

        if (!Storage::disk('local')->exists($report->file_path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $mime = match ($report->format) {
            'pdf'   => 'application/pdf',
            'csv'   => 'text/csv',
            'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };

        $fullPath = Storage::disk('local')->path($report->file_path);

        return response()->download($fullPath, "{$report->title}.{$report->format}", [
            'Content-Type' => $mime,
        ]);
    }

    public function destroy(Report $report): JsonResponse
    {
        if ($report->file_path && Storage::disk('local')->exists($report->file_path)) {
            Storage::disk('local')->delete($report->file_path);
        }

        $report->delete();
        return response()->json(['message' => 'Report deleted.']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'exists:reports,id',
        ]);

        foreach (Report::whereIn('id', $request->ids)->get() as $report) {
            if ($report->file_path && Storage::disk('local')->exists($report->file_path)) {
                Storage::disk('local')->delete($report->file_path);
            }
            $report->delete();
        }

        return response()->json(['message' => 'Reports deleted.']);
    }
}
