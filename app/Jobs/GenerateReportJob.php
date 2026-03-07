<?php

namespace App\Jobs;

use App\Models\Report;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public Report $report) {}

    public function handle(): void
    {
        $this->report->update(['status' => 'processing']);

        try {
            $this->report->load([
                'session.topics.keywords',
                'session.topics.suggestions',
            ]);

            $data = $this->buildReportData();

            match ($this->report->format) {
                'pdf'  => $this->generatePdf($data),
                'csv'  => $this->generateCsv($data),
                'xlsx' => $this->generateXlsx($data),
            };
        } catch (\Throwable $e) {
            $this->report->update(['status' => 'failed']);
            throw $e;
        }
    }

    // ── Data Builder ──────────────────────────────────────────────────────

    private function buildReportData(): array
    {
        $session = $this->report->session;

        return [
            'title'   => $this->report->title,
            'session' => [
                'name'             => $session->name,
                'total_topics'     => $session->total_topics,
                'total_documents'  => $session->total_documents,
                'outliers'         => $session->outliers,
                'date_range'       => $session->model_parameters['date_range'] ?? ['start' => null, 'end' => null],
                'generated_at'     => now()->format('F j, Y'),
            ],
            'topics' => $session->topics->map(fn($topic) => [
                'label'          => $topic->label,
                'document_count' => $topic->document_count,
                'keywords'       => $topic->keywords->pluck('keyword')->toArray(),
                'suggestions'    => $topic->suggestions
                    ->take(100)
                    ->map(fn($s) => [
                        'text'         => $s->suggestion,
                        'is_anonymous' => $s->is_anonymous,
                        'created_at'   => $s->created_at?->format('M j, Y'),
                    ])->toArray(),
            ])->toArray(),
        ];
    }


    private function generatePdf(array $data): void
    {
        ini_set('memory_limit', '512M');

        $pdf  = Pdf::loadView('reports.pdf', $data)->setPaper('a4', 'portrait');
        $path = "reports/{$this->report->id}.pdf";

        Storage::disk('local')->put($path, $pdf->output());
        $this->finalise($path);
    }

    private function generateCsv(array $data): void
    {
        $tempPath = sys_get_temp_dir() . "/{$this->report->id}.csv";
        $handle   = fopen($tempPath, 'w');

        // UTF-8 BOM for Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, ['Topic', 'Keywords', 'Suggestion', 'Anonymous', 'Date']);

        foreach ($data['topics'] as $topic) {
            $keywords = implode(', ', $topic['keywords']);
            if (empty($topic['suggestions'])) {
                fputcsv($handle, [$topic['label'], $keywords, '', '', '']);
                continue;
            }
            foreach ($topic['suggestions'] as $s) {
                fputcsv($handle, [
                    $topic['label'],
                    $keywords,
                    $s['text'],
                    $s['is_anonymous'] ? 'Yes' : 'No',
                    $s['created_at'],
                ]);
            }
        }

        fclose($handle);

        $path = "reports/{$this->report->id}.csv";
        Storage::disk('local')->put($path, file_get_contents($tempPath));
        @unlink($tempPath);

        $this->finalise($path);
    }

    private function generateXlsx(array $data): void
    {
        $spreadsheet = new Spreadsheet();

        // ── Summary sheet ─────────────────────────────────────────────────
        $summary = $spreadsheet->getActiveSheet()->setTitle('Summary');
        $summary->fromArray([
            ['Session',          $data['session']['name']],
            ['Total Topics',     $data['session']['total_topics']],
            ['Total Suggestions', $data['session']['total_documents']],
            ['Outliers',         $data['session']['outliers']],
            ['Date From',        $data['session']['date_range']['start'] ?? '—'],
            ['Date To',          $data['session']['date_range']['end']   ?? '—'],
            ['Generated',        $data['session']['generated_at']],
        ]);

        // ── Topics sheet ──────────────────────────────────────────────────
        $sheet = $spreadsheet->createSheet()->setTitle('Topics & Suggestions');
        $sheet->fromArray([['Topic', 'Keywords', 'Suggestion', 'Anonymous', 'Date']]);

        $row = 2;
        foreach ($data['topics'] as $topic) {
            $keywords = implode(', ', $topic['keywords']);
            if (empty($topic['suggestions'])) {
                $sheet->fromArray([[$topic['label'], $keywords, '', '', '']], null, "A{$row}");
                $row++;
                continue;
            }
            foreach ($topic['suggestions'] as $s) {
                $sheet->fromArray([[
                    $topic['label'],
                    $keywords,
                    $s['text'],
                    $s['is_anonymous'] ? 'Yes' : 'No',
                    $s['created_at'],
                ]], null, "A{$row}");
                $row++;
            }
        }

        $path     = "reports/{$this->report->id}.xlsx";
        $tempPath = sys_get_temp_dir() . "/{$this->report->id}.xlsx";

        (new Xlsx($spreadsheet))->save($tempPath);
        Storage::disk('local')->put($path, file_get_contents($tempPath));
        @unlink($tempPath);

        $this->finalise($path);
    }

    // ── Finalise ──────────────────────────────────────────────────────────

    private function finalise(string $path): void
    {
        $fullPath = Storage::disk('local')->path($path);
        $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;

        $this->report->update([
            'status'       => 'completed',
            'file_path'    => $path,
            'file_size'    => $fileSize,
            'generated_at' => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->report->update(['status' => 'failed']);
    }
}
