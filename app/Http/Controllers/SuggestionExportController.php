<?php

namespace App\Http\Controllers;

use App\Exports\SuggestionsExport;
use App\Models\Form;
use App\Models\Suggestion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SuggestionExportController extends Controller
{
    public function export(Request $request, int $formId)
    {
        $request->validate([
            'type'       => 'required|in:csv,xlsx,pdf',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        $form      = Form::findOrFail($formId);
        $type      = $request->type;
        $startDate = $request->start_date;
        $endDate   = $request->end_date;
        $filename  = $this->buildFilename($form->title, $type, $startDate, $endDate);

        return match ($type) {
            'csv'  => Excel::download(
                          new SuggestionsExport($formId, $startDate, $endDate),
                          $filename,
                          \Maatwebsite\Excel\Excel::CSV,
                          ['Content-Type' => 'text/csv']
                      ),
            'xlsx' => Excel::download(
                          new SuggestionsExport($formId, $startDate, $endDate),
                          $filename
                      ),
            'pdf'  => $this->exportPdf($formId, $form->title, $startDate, $endDate, $filename),
        };
    }

    private function exportPdf(
        int     $formId,
        string  $formTitle,
        ?string $startDate,
        ?string $endDate,
        string  $filename
    ) {
        $query = Suggestion::with('student:id,email')
            ->where('form_id', $formId);

        if ($startDate) $query->whereDate('created_at', '>=', $startDate);
        if ($endDate)   $query->whereDate('created_at', '<=', $endDate);

        $suggestions = $query->latest()->get();

        $pdf = Pdf::loadView('exports.suggestions', [
            'suggestions' => $suggestions,
            'formTitle'   => $formTitle,
            'startDate'   => $startDate,
            'endDate'     => $endDate,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    private function buildFilename(
        string  $formTitle,
        string  $type,
        ?string $startDate,
        ?string $endDate
    ): string {
        $slug  = str($formTitle)->slug()->limit(30);
        $range = $startDate && $endDate
            ? "_{$startDate}_to_{$endDate}"
            : ($startDate ? "_from_{$startDate}" : '');

        return "suggestions_{$slug}{$range}.{$type}";
    }
}
