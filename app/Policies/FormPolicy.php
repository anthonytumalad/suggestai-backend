<?php

namespace App\Policies;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Form;
use App\Models\User;

class FormPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Form $form): bool
    {
        return $form->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Form $form): bool
    {
        Log::info('User info:', ['user' => Auth::user(), 'form' => $form]);

        return $form->user_id === $user->id;
    }

     public function delete(User $user, Form $form): bool
    {
        return $form->user_id === $user->id;
    }
}
