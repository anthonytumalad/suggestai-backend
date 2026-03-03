<?php

namespace App\Exports;

use App\Models\Suggestion;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SuggestionsExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private int     $formId,
        private ?string $startDate,
        private ?string $endDate,
    ) {}

    public function query(): Builder
    {
        $query = Suggestion::with('student:id,email,name')
            ->where('form_id', $this->formId);

        if ($this->startDate) {
            $query->whereDate('created_at', '>=', $this->startDate);
        }

        if ($this->endDate) {
            $query->whereDate('created_at', '<=', $this->endDate);
        }

        return $query->latest();
    }

    public function headings(): array
    {
        return ['#', 'Student', 'Suggestion', 'Anonymous', 'Date'];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->is_anonymous ? 'Anonymous' : ($row->student?->email ?? 'N/A'),
            $row->suggestion,
            $row->is_anonymous ? 'Yes' : 'No',
            $row->created_at->format('Y-m-d H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
