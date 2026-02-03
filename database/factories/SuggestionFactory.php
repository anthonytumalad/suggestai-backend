<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Form;
use App\Models\Student;

class SuggestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id'     => Form::inRandomOrder()->first()?->id ?? 1,
            'student_id'  => Student::inRandomOrder()->first()?->id,
            'suggestion'  => $this->faker->paragraph,
            'is_anonymous'=> $this->faker->boolean(30),
        ];
    }
}
