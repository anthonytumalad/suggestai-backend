<?php

namespace App\Http\Controllers\Auth;

use App\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirectToGoogle()
    {
        $referrer = request()->headers->get('referer');
        if ($referrer) {
            session(['url.intended' => $referrer]);
        }

        return Socialite::driver('google')
            // ->with(['hd' => 'thelewiscollege.edu.ph'])
            ->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            $intendedUrl = session('url.intended', route('forms.show', ['slug' => 'saso-office']));
            session()->forget('url.intended');

            return redirect($intendedUrl)
                ->withErrors(['error' => 'Authentication failed. Please try again.']);
        }

        $student = Student::where('google_id', $googleUser->getId())->first();

        if (!$student) {
            $student = Student::create([
                'google_id'         => $googleUser->getId(),
                'email'             => $googleUser->getEmail(),
                'name'              => $googleUser->getName(),
                'profile_picture'   => $googleUser->getAvatar(),
                'access_granted_at' => now(),
            ]);
        } else {
            $student->update([
                'access_granted_at' => now(),
            ]);
        }

        Auth::login($student);

        $intendedUrl = session('url.intended');
        session()->forget('url.intended');

        if ($intendedUrl && str_contains($intendedUrl, '/forms/')) {
            return redirect($intendedUrl);
        }

        return redirect()->route('forms.show', ['slug' => 'feedback-form']);
    }
}
