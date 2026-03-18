<?php

namespace App\Jobs;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public Report $report) {}

    public function handle(): void
    {
        ini_set('memory_limit', '512M');
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

    // ── Helpers ───────────────────────────────────────────────────────────

    private function countSuggestions(array $topics): int
    {
        return array_sum(array_map(fn($t) => count($t['suggestions']), $topics));
    }

    // ── Data Builder ──────────────────────────────────────────────────────

    private function buildReportData(): array
    {
        $session = $this->report->session;

        return [
            'title'   => $this->report->title,
            'session' => [
                'name'            => $session->name,
                'total_topics'    => $session->total_topics,
                'total_documents' => $session->total_documents,
                'outliers'        => $session->outliers,
                'date_range'      => $session->model_parameters['date_range'] ?? ['start' => null, 'end' => null],
                'generated_at'    => now()->format('F j, Y'),
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

    // ── Generators ────────────────────────────────────────────────────────

    private function generatePdf(array $data): void
    {
        $html = view('reports.pdf', $data)->render();

        $tmpDir = storage_path('app/mpdf-tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'orientation'       => 'P',
            'margin_top'        => 15,
            'margin_bottom'     => 15,
            'margin_left'       => 15,
            'margin_right'      => 15,
            'tempDir'           => $tmpDir,
            'setAutoTopMargin'  => 'pad',
        ]);

        $mpdf->WriteHTML($html);

        $output = $mpdf->Output('', 'S');
        $path   = "reports/{$this->report->id}.pdf";

        Storage::disk('local')->put($path, $output);

        $this->finalise($path);
    }

    private function generateCsv(array $data): void
    {
        $tempPath = sys_get_temp_dir() . "/{$this->report->id}.csv";
        $handle   = fopen($tempPath, 'w');

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

        $this->buildSummarySheet($spreadsheet, $data);
        $this->buildTopicsSheet($spreadsheet, $data);

        $spreadsheet->setActiveSheetIndex(0);

        $path     = "reports/{$this->report->id}.xlsx";
        $tempPath = sys_get_temp_dir() . "/{$this->report->id}.xlsx";

        (new Xlsx($spreadsheet))->save($tempPath);
        Storage::disk('local')->put($path, file_get_contents($tempPath));
        @unlink($tempPath);

        $this->finalise($path);
    }

    // ── Excel Sheet Builders ──────────────────────────────────────────────
    private function buildSummarySheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Summary');

        // ── Report title ──────────────────────────────────────────────────
        $sheet->mergeCells('A1:B1');
        $sheet->setCellValue('A1', $data['title']);
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF1F3864']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // ── Session info rows ─────────────────────────────────────────────
        $rows = [
            ['Session',            $data['session']['name']],
            ['Total Topics',       $data['session']['total_topics']],
            ['Total Suggestions',  $data['session']['total_documents']],
            ['Outliers',           $data['session']['outliers']],
            ['Date From',          $data['session']['date_range']['start'] ?? '—'],
            ['Date To',            $data['session']['date_range']['end']   ?? '—'],
            ['Generated',          $data['session']['generated_at']],
        ];

        $row = 3;
        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FF374151']],
            ]);
            $sheet->getStyle("B{$row}")->applyFromArray([
                'font' => ['color' => ['argb' => 'FF6B7280']],
            ]);
            $row++;
        }

        // ── Topics overview table ─────────────────────────────────────────
        $row += 1; // blank row spacer
        $tableHeaderRow = $row;

        $sheet->setCellValue("A{$row}", '#');
        $sheet->setCellValue("B{$row}", 'Topic');
        $sheet->setCellValue("C{$row}", 'Keywords');
        $sheet->setCellValue("D{$row}", 'Documents');
        $sheet->setCellValue("E{$row}", 'Suggestions');

        $sheet->getStyle("A{$tableHeaderRow}:E{$tableHeaderRow}")->applyFromArray([
            'font'    => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F3864']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $row++;
        foreach ($data['topics'] as $i => $topic) {
            $isEven = $i % 2 === 0;
            $bgColor = $isEven ? 'FFF0F4FF' : 'FFFFFFFF';

            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValue("B{$row}", $topic['label']);
            $sheet->setCellValue("C{$row}", implode(', ', $topic['keywords']));
            $sheet->setCellValue("D{$row}", $topic['document_count']);
            $sheet->setCellValue("E{$row}", count($topic['suggestions']));

            $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bgColor]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
            ]);
            $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $row++;
        }

        // ── Column widths ─────────────────────────────────────────────────
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(14);
        $sheet->getColumnDimension('E')->setWidth(14);
    }

    private function buildTopicsSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet()->setTitle('Topics & Suggestions');

        $row = 1;

        foreach ($data['topics'] as $i => $topic) {
            // ── Topic header block ────────────────────────────────────────
            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->setCellValue("A{$row}", ($i + 1) . '. ' . $topic['label']);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F3864']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;

            // ── Topic meta: document count + keywords ─────────────────────
            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->setCellValue("A{$row}", "Documents: {$topic['document_count']}   |   Keywords: " . implode(', ', $topic['keywords']));
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font'      => ['italic' => true, 'size' => 10, 'color' => ['argb' => 'FF374151']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EDF5']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1, 'wrapText' => true],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
            $row++;

            // ── Suggestions column headers ────────────────────────────────
            $sheet->setCellValue("A{$row}", '#');
            $sheet->setCellValue("B{$row}", 'Suggestion');
            $sheet->setCellValue("C{$row}", 'Anonymous');
            $sheet->setCellValue("D{$row}", 'Date');

            $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                'font'    => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E4D8A']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $row++;

            // ── Suggestion rows ───────────────────────────────────────────
            if (empty($topic['suggestions'])) {
                $sheet->mergeCells("A{$row}:D{$row}");
                $sheet->setCellValue("A{$row}", 'No suggestions for this topic.');
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font'      => ['italic' => true, 'color' => ['argb' => 'FF9CA3AF']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $row++;
            } else {
                foreach ($topic['suggestions'] as $j => $s) {
                    $isEven  = $j % 2 === 0;
                    $bgColor = $isEven ? 'FFF9FAFB' : 'FFFFFFFF';

                    $sheet->setCellValue("A{$row}", $j + 1);
                    $sheet->setCellValue("B{$row}", $s['text']);
                    $sheet->setCellValue("C{$row}", $s['is_anonymous'] ? 'Yes' : 'No');
                    $sheet->setCellValue("D{$row}", $s['created_at']);

                    $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bgColor]],
                        'alignment' => ['wrapText' => true],
                    ]);
                    $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    $row++;
                }
            }

            $row++; // blank spacer between topics
        }

        // ── Column widths ─────────────────────────────────────────────────
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(60);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(14);
    }


    // ── Finalise ──────────────────────────────────────────────────────────

    private function finalise(string $path): void
    {
        $fullPath = Storage::disk('local')->path($path);

        if (!file_exists($fullPath) || filesize($fullPath) === 0) {
            $this->report->update(['status' => 'failed']);
            throw new \RuntimeException("Report file was not written to disk: {$fullPath}");
        }

        $this->report->update([
            'status'       => 'completed',
            'file_path'    => $path,
            'file_size'    => filesize($fullPath),
            'generated_at' => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->report->update(['status' => 'failed']);
    }
}
