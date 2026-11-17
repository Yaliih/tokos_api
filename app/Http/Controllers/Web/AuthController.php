<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Connexion / inscription du site web (session), avec le même compte que l'app. */
class AuthController extends Controller
{
    public function showLogin()
    {
        return view('web.pages.login', ['title' => 'Connexion', 'hideRail' => true]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'phone_code' => 'required|string|max:5',
            'phone_number' => 'required|string|max:20',
            'password' => 'required|string',
        ]);
        $data['phone_number'] = ltrim(preg_replace('/\D/', '', $data['phone_number']), '0');

        if (! Auth::guard('web')->attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['phone_number' => 'Numéro ou mot de passe incorrect.']);
        }
        if (! Auth::user()->is_active) {
            Auth::logout();
            throw ValidationException::withMessages(['phone_number' => 'Ce compte est désactivé.']);
        }
        $request->session()->regenerate();
        Auth::user()->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->claimInvite($request);

        return redirect($this->safeNext($request->input('suite')))->with('ok', 'Content de te revoir !');
    }

    public function showRegister()
    {
        return view('web.pages.register', ['title' => 'Créer un compte', 'hideRail' => true]);
    }

    public function register(RegisterRequest $request)
    {
        app(\App\Services\Competition\AntiFraud::class)->checkSignup();
        $user = DB::transaction(function () use ($request) {
            $code = VerificationCode::where('phone_code', $request->phone_code)
                ->where('phone_number', $request->phone_number)
                ->where('code', $request->verification_code)
                ->where('is_used', false)->first();
            if (! $code || ! $code->isValid()) {
                throw ValidationException::withMessages(['verification_code' => 'Code invalide ou expiré.']);
            }
            $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'phone_code' => $request->phone_code,
                'phone_number' => $request->phone_number,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'birth_date' => $request->birth_date,
                'gender' => $request->gender,
                'interests' => array_values(array_intersect((array) $request->input('interests', []), array_keys(config('competition.categories')))) ?: null,
                'interests_asked_at' => now(),
            ]);
            $code->markAsUsed();
            $user->markPhoneAsVerified();

            return $user;
        });

        app(\App\Services\Competition\AntiFraud::class)->markSignup($user);
        Auth::login($user, true);
        $request->session()->regenerate();
        $this->claimInvite($request);

        return $request->filled('suite')
            ? redirect($this->safeNext($request->input('suite')))->with('ok', 'Bienvenue sur Sosa ! Ton défi t\'attend.')
            : redirect('/decouvrir')->with('ok', 'Bienvenue sur Sosa ! Vote pour un duel ou lance ton premier défi.');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /** Défi reçu par lien (SMS / WhatsApp) avant d'avoir un compte. */
    private function claimInvite(Request $request): void
    {
        if ($token = $request->session()->pull('phone_invite')) {
            app(\App\Services\Competition\CompetitionService::class)->claimPhoneInvites($request->user(), $token);
        }
    }

    private function safeNext(?string $next): string
    {
        return $next && str_starts_with($next, '/') && ! str_starts_with($next, '//') ? $next : '/';
    }

    // ── Mot de passe oublié : code par WhatsApp ou SMS, puis nouveau mot de passe ──

    public function showForgot(Request $request)
    {
        return view('web.pages.forgot', ['title' => 'Mot de passe oublié', 'hideRail' => true, 'step' => session('reset_phone') ? 2 : 1, 'phone' => session('reset_phone')]);
    }

    public function sendResetCode(Request $request)
    {
        $data = $request->validate(['phone_code' => 'required|string|max:5', 'phone_number' => 'required|string|max:20']);
        $code = '+'.ltrim($data['phone_code'], '+');
        $number = ltrim(preg_replace('/\D/', '', $data['phone_number']), '0');
        $user = User::where('phone_code', $code)->where('phone_number', $number)->where('is_active', true)->first();
        // Même réponse que le compte existe ou non : on ne révèle pas qui est inscrit
        if ($user) {
            $recent = VerificationCode::where('phone_code', $code)->where('phone_number', $number)->where('created_at', '>=', now()->subHour())->count();
            if ($recent >= 5) {
                throw ValidationException::withMessages(['phone_number' => 'Trop d\'essais. Réessaie dans une heure.']);
            }
            $last = VerificationCode::where('phone_code', $code)->where('phone_number', $number)->latest('id')->first();
            if ($last && $last->created_at->diffInSeconds(now()) < 60) {
                throw ValidationException::withMessages(['phone_number' => __('Patiente une minute avant de redemander un code.')]);
            }
            $otp = VerificationCode::generateUniqueCode();
            VerificationCode::create(['phone_code' => $code, 'phone_number' => $number, 'code' => $otp, 'expires_at' => now()->addMinutes((int) config('messaging.code_ttl', 10)), 'is_used' => false, 'attempts' => 0]);
            app(\App\Services\Messaging\Messenger::class)->sendCode($code.$number, $otp);
        }
        session(['reset_phone' => ['code' => $code, 'number' => $number]]);

        return redirect('/mot-de-passe-oublie')->with('ok', 'Si ce numéro a un compte, un code vient de lui être envoyé.');
    }

    public function resetPassword(Request $request)
    {
        $phone = session('reset_phone');
        abort_unless($phone, 419);
        $data = $request->validate([
            'verification_code' => 'required|digits:6',
            'password' => 'required|string|min:6|confirmed',
        ], ['password.confirmed' => 'Les deux mots de passe ne sont pas identiques.', 'password.min' => 'Au moins 6 caractères.']);
        $otp = VerificationCode::where('phone_code', $phone['code'])->where('phone_number', $phone['number'])
            ->where('is_used', false)->latest('id')->first();
        if (! $otp || $otp->attempts >= 5 || ! $otp->isValid() || ! hash_equals((string) $otp->code, (string) $data['verification_code'])) {
            $otp?->incrementAttempts();
            throw ValidationException::withMessages(['verification_code' => 'Code invalide ou expiré.']);
        }
        $user = User::where('phone_code', $phone['code'])->where('phone_number', $phone['number'])->where('is_active', true)->firstOrFail();
        $otp->markAsUsed();
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $user->tokens()->delete();
        session()->forget('reset_phone');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/')->with('ok', 'Mot de passe changé. Tu es connecté.');
    }

    public function restartForgot()
    {
        session()->forget('reset_phone');

        return redirect('/mot-de-passe-oublie');
    }
}
