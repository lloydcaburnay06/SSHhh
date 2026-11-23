<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's own account: name, email, password and authenticator.
 */
class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('profile', ['account' => $request->user()?->toPublicArray()]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        $user->fill(['name' => $data['name'], 'email' => mb_strtolower(trim($data['email']))])->save();

        return back()->with('flash', 'Profile saved.');
    }

    /** Replace the authenticator: confirms the password, then shows a new QR code. */
    public function replaceTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $request->session()->put('two_factor.may_replace', true);

        return redirect()->route('two-factor.setup');
    }
}
