<?php

namespace Database\Seeders;

use App\Models\Suggestion;
use App\Models\Student;
use Illuminate\Database\Seeder;

class SuggestionCsvSeeder extends Seeder
{
    public function run()
    {
        $path = storage_path('app/seeders/campus_problems.csv');

        if (!file_exists($path)) {
            $this->command->error("CSV file not found: {$path}");
            return;
        }

        // Get all student IDs once
        $studentIds = Student::pluck('id')->toArray();

        // Read CSV: one suggestion per line
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $rows = [];

        foreach ($lines as $line) {
            $isAnonymous = rand(0, 1) === 1;

            $rows[] = [
                'form_id'      => 522,
                'student_id'   => $isAnonymous ? null : (count($studentIds) ? $studentIds[array_rand($studentIds)] : null),
                'suggestion'   => $line,
                'is_anonymous' => $isAnonymous,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Bulk insert safely
        foreach (array_chunk($rows, 100) as $chunk) {
            Suggestion::insert($chunk);
        }

        $this->command->info('Suggestions for form 522 imported successfully!');
    }
}
