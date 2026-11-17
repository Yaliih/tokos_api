<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendVerificationCodeRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\VerifyPhoneRequest;
use App\Models\Preference;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\SmsServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly SmsServiceInterface $smsService
    ) {}

    public function preferences(): JsonResponse
    {
        try {
            $preferences = Preference::active()
                ->orderBy('category')
                ->orderBy('name')
                ->get()
                ->groupBy('category');

            return response()->json([
                'success' => true,
                'data' => ['preferences' => $preferences],
            ]);
        } catch (\Throwable $e) {
            Log::error('Preferences error', ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => __('Failed to fetch preferences.'),
            ], 500);
        }
    }

    /** Connexion Next.js SPA : session Laravel, aucun token retourné. */
    public function webLogin(LoginRequest $request): JsonResponse
    {
        $credentials = $this->loginCredentials($request);
        $remember = $request->boolean('remember');

        if (! Auth::guard('web')->attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'phone_number' => ['Numéro ou mot de passe incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'phone_number' => ['Ce compte est désactivé.'],
            ]);
        }

        $this->recordLogin($user, $request);

        return response()->json([
            'success' => true,
            'message' => __('Login successful.'),
            'data' => [
                'user' => $user->load('preferences'),
                'requires_phone_verification' => ! $user->hasVerifiedPhone(),
            ],
        ]);
    }

    /** Connexion mobile : token Sanctum retourné au client mobile. */
    public function mobileLogin(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('phone_code', $request->phone_code)
            ->where('phone_number', $request->phone_number)
            ->first();

        if (! $user || ! $user->validatePassword($request->password)) {
            throw ValidationException::withMessages([
                'phone_number' => ['Numéro ou mot de passe incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'phone_number' => ['Ce compte est désactivé.'],
            ]);
        }

        $deviceName = trim((string) $request->input('device_name'));

        if ($deviceName === '') {
            throw ValidationException::withMessages([
                'device_name' => [__('The device name is required for mobile login.')],
            ]);
        }

        $user->tokens()->where('name', $deviceName)->delete();
        $token = $user->createToken($deviceName)->plainTextToken;

        $this->recordLogin($user, $request);

        return response()->json([
            'success' => true,
            'message' => __('Login successful.'),
            'data' => [
                'user' => $user->load('preferences'),
                'token' => $token,
                'token_type' => 'Bearer',
                'requires_phone_verification' => ! $user->hasVerifiedPhone(),
            ],
        ]);
    }

    /** Inscription web SPA : crée l'utilisateur puis ouvre une session. */
    public function webRegister(RegisterRequest $request): JsonResponse
    {
        app(\App\Services\Competition\AntiFraud::class)->checkSignup();
        $user = $this->createUserFromRegistration($request);
        app(\App\Services\Competition\AntiFraud::class)->markSignup($user);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => 'Compte créé.',
            'data' => [
                'user' => $user->load('preferences'),
                'requires_phone_verification' => false,
            ],
        ], 201);
    }

    /** Inscription mobile : crée l'utilisateur puis retourne un token. */
    public function mobileRegister(RegisterRequest $request): JsonResponse
    {
        app(\App\Services\Competition\AntiFraud::class)->checkSignup();
        $user = $this->createUserFromRegistration($request);
        app(\App\Services\Competition\AntiFraud::class)->markSignup($user);
        $deviceName = trim((string) $request->input('device_name'));

        if ($deviceName === '') {
            $deviceName = 'mobile-device';
        }

        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Compte créé.',
            'data' => [
                'user' => $user->load('preferences'),
                'token' => $token,
                'token_type' => 'Bearer',
                'requires_phone_verification' => false,
            ],
        ], 201);
    }

    public function sendVerificationCode(SendVerificationCodeRequest $request): JsonResponse
    {
        $phoneCode = $request->phone_code;
        $phoneNumber = $request->phone_number;
        $fullPhone = $phoneCode.$phoneNumber;

        if (User::where('phone_code', $phoneCode)->where('phone_number', $phoneNumber)->exists()) {
            throw ValidationException::withMessages([
                'phone_number' => ['Ce numéro a déjà un compte. Connecte-toi.'],
            ]);
        }

        VerificationCode::cleanExpired();
        $this->enforceVerificationRateLimit($phoneCode, $phoneNumber);

        $code = VerificationCode::generateUniqueCode();
        $verificationCode = VerificationCode::create([
            'phone_code' => $phoneCode,
            'phone_number' => $phoneNumber,
            'code' => $code,
            'expires_at' => now()->addMinutes((int) config('messaging.code_ttl', 10)),
            'is_used' => false,
            'attempts' => 0,
        ]);

        $sent = app(\App\Services\Messaging\Messenger::class)->sendCode($fullPhone, $code);

        if (! $sent) {
            $verificationCode->delete();

            return response()->json([
                'success' => false,
                'message' => 'Le code n\'a pas pu être envoyé. Réessaie.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Code envoyé.',
            'data' => [
                'id' => $verificationCode->id,
                'phone_code' => $phoneCode,
                'phone_number' => $phoneNumber,
                'expires_in' => 120,
            ],
        ]);
    }

    public function resendVerificationCode(Request $request): JsonResponse
    {
        $phoneCode = $request->input('phone_code');
        $phoneNumber = $request->input('phone_number');

        if (! $phoneCode || ! $phoneNumber) {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => __('Phone number is required.'),
                ], 422);
            }

            $phoneCode = $user->phone_code;
            $phoneNumber = $user->phone_number;
        }

        if (User::where('phone_code', $phoneCode)->where('phone_number', $phoneNumber)->exists()) {
            throw ValidationException::withMessages([
                'phone_number' => ['Ce numéro a déjà un compte. Connecte-toi.'],
            ]);
        }

        VerificationCode::cleanExpired();
        $this->enforceVerificationRateLimit($phoneCode, $phoneNumber);

        $code = VerificationCode::generateUniqueCode();
        $verificationCode = VerificationCode::create([
            'phone_code' => $phoneCode,
            'phone_number' => $phoneNumber,
            'code' => $code,
            'expires_at' => now()->addMinutes((int) config('messaging.code_ttl', 10)),
            'is_used' => false,
            'attempts' => 0,
        ]);

        $sent = app(\App\Services\Messaging\Messenger::class)->sendCode($phoneCode.$phoneNumber, $code);

        if (! $sent) {
            $verificationCode->delete();

            return response()->json([
                'success' => false,
                'message' => 'Le code n\'a pas pu être envoyé. Réessaie.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => __('New verification code sent successfully.'),
            'data' => [
                'id' => $verificationCode->id,
                'phone_code' => $phoneCode,
                'phone_number' => $phoneNumber,
                'expires_in' => 120,
            ],
        ]);
    }

    public function verifyPhone(VerifyPhoneRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => __('Unauthenticated.')], 401);
        }

        if ($user->hasVerifiedPhone()) {
            return response()->json(['success' => false, 'message' => __('Phone already verified.')], 400);
        }

        $verificationCode = VerificationCode::where('phone_code', $user->phone_code)
            ->where('phone_number', $user->phone_number)
            ->where('code', $request->verification_code)
            ->where('is_used', false)
            ->first();

        if (! $verificationCode) {
            $this->logFailedAttempt($user->phone_code, $user->phone_number);
            throw ValidationException::withMessages([
                'verification_code' => [__('Invalid verification code.')],
            ]);
        }

        if (! $verificationCode->isValid()) {
            throw ValidationException::withMessages([
                'verification_code' => [__('Verification code expired. Please request a new one.')],
            ]);
        }

        if ($verificationCode->attempts >= 3) {
            $verificationCode->delete();
            throw ValidationException::withMessages([
                'verification_code' => [__('Too many failed attempts. Please request a new code.')],
            ]);
        }

        $verificationCode->markAsUsed();
        $user->markPhoneAsVerified();
        VerificationCode::cleanExpired();

        return response()->json([
            'success' => true,
            'message' => __('Phone verified successfully!'),
            'data' => ['user' => $user->fresh()->load('preferences')],
        ]);
    }

    /** Déconnexion web SPA : détruit la session, ne touche pas aux tokens mobiles. */
    public function webLogout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => __('Logged out successfully.'),
        ]);
    }

    /** Déconnexion mobile : révoque uniquement le token courant. */
    public function mobileLogout(Request $request): JsonResponse
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken) {
            $accessToken->delete();
        }

        return response()->json([
            'success' => true,
            'message' => __('Logged out successfully.'),
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['user' => $request->user()->load('preferences')],
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        if (isset($data['username']) && $user->username_changed) {
            return response()->json([
                'success' => false,
                'message' => __('You can only change your username once.'),
            ], 422);
        }

        if (isset($data['username'])) {
            $data['username_changed'] = true;
        }

        if ($request->hasFile('avatar')) {
            $data['avatar'] = $request->file('avatar')->store('avatars', config('filesystems.media', 'public'));
        }

        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('photos', config('filesystems.media', 'public'));
        }

        $user->update($data);

        if ($request->has('preferences')) {
            $user->preferences()->sync($request->preferences);
        }

        return response()->json([
            'success' => true,
            'message' => __('Profile updated successfully.'),
            'data' => ['user' => $user->fresh()->load('preferences')],
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::where('phone_code', $request->phone_code)
            ->where('phone_number', $request->phone_number)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'phone_number' => [__('No account found with this number.')],
            ]);
        }

        $verificationCode = VerificationCode::where('phone_code', $request->phone_code)
            ->where('phone_number', $request->phone_number)
            ->where('code', $request->verification_code)
            ->where('is_used', false)
            ->first();

        if (! $verificationCode || ! $verificationCode->isValid()) {
            throw ValidationException::withMessages([
                'verification_code' => ['Code invalide ou expiré.'],
            ]);
        }

        $verificationCode->markAsUsed();
        $user->update(['password' => Hash::make($request->password)]);

        // Révoque les anciens tokens mobiles après changement de mot de passe.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => __('Password reset successfully.'),
        ]);
    }

    private function loginCredentials(LoginRequest $request): array
    {
        return [
            'phone_code' => $request->phone_code,
            'phone_number' => $request->phone_number,
            'password' => $request->password,
        ];
    }

    private function recordLogin(User $user, Request $request): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();
    }

    private function createUserFromRegistration(RegisterRequest $request): User
    {
        return DB::transaction(function () use ($request): User {
            if (User::where('phone_code', $request->phone_code)
                ->where('phone_number', $request->phone_number)
                ->exists()) {
                throw ValidationException::withMessages([
                    'phone_number' => ['Ce numéro a déjà un compte.'],
                ]);
            }

            $verificationCode = VerificationCode::where('phone_code', $request->phone_code)
                ->where('phone_number', $request->phone_number)
                ->where('code', $request->verification_code)
                ->where('is_used', false)
                ->first();

            if (! $verificationCode || ! $verificationCode->isValid()) {
                throw ValidationException::withMessages([
                    'verification_code' => ['Code invalide ou expiré.'],
                ]);
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
                // Goûts choisis à l'inscription (mêmes disciplines que le site)
                'interests' => array_values(array_intersect((array) $request->input('interests', []), array_keys(config('competition.categories')))) ?: null,
            ]);

            if ($request->filled('preferences')) {
                $user->preferences()->attach($request->preferences);
            }

            $verificationCode->markAsUsed();
            $user->markPhoneAsVerified();

            return $user;
        });
    }

    private function enforceVerificationRateLimit(string $phoneCode, string $phoneNumber): void
    {
        $recentAttempts = VerificationCode::where('phone_code', $phoneCode)
            ->where('phone_number', $phoneNumber)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentAttempts >= 5) {
            throw ValidationException::withMessages([
                'phone_number' => [__('Too many attempts. Please try again in 1 hour.')],
            ]);
        }

        $lastAttempt = VerificationCode::where('phone_code', $phoneCode)
            ->where('phone_number', $phoneNumber)
            ->latest()
            ->first();

        if ($lastAttempt && $lastAttempt->created_at->diffInSeconds(now()) < 60) {
            $waitTime = 60 - $lastAttempt->created_at->diffInSeconds(now());
            throw ValidationException::withMessages([
                'phone_number' => ["Please wait {$waitTime} seconds before requesting a new code."],
            ]);
        }
    }

    private function logFailedAttempt(string $phoneCode, string $phoneNumber): void
    {
        $verificationCode = VerificationCode::where('phone_code', $phoneCode)
            ->where('phone_number', $phoneNumber)
            ->where('is_used', false)
            ->latest()
            ->first();

        if ($verificationCode) {
            $verificationCode->incrementAttempts();

            if ($verificationCode->attempts >= 3) {
                $verificationCode->delete();
            }
        }
    }
}
