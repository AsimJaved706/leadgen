<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use App\Support\ProfessionalEmailTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()], 'workspace' => 'required|string|max:100']);
        $user = DB::transaction(function () use ($data) {
            $plan = Plan::where('slug', 'free')->where('is_active', true)->firstOrFail();
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $workspace = Workspace::create(['name' => $data['workspace'], 'owner_id' => $user->id, 'plan_id' => $plan->id]);
            $workspace->members()->attach($user->id, ['role' => 'owner']);
            ProfessionalEmailTemplates::install($workspace);

            return $user->fresh();
        });
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        Audit::record('user.registered', $user->id);

        return response()->json($user->load('workspaces.plan'), 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', strtolower($data['email']))->first();
        // Always run a hash check, including unknown accounts.
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$q.uBAuNzSAElRx.Ao/yX0u3iST25.LGh6yjXsGhLvVi47dlfSNY6e');
        if (! $valid || ! $user || $user->suspended_at) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::record('user.login', $user->id);

        return $user->load('workspaces.plan');
    }

    public function logout(Request $request)
    {
        Audit::record('user.logout', $request->user()->id);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }
}
