<?php

namespace App\Http\Controllers\Auth;

use App\Facades\Settings;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Handle a registration request for the application.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(UserStoreRequest $request)
    {
        $invitation = null;

        // Check if registration is via invitation
        if ($request->has('invitation')) {
            $invitation = UserInvitation::where('token', $request->invitation)->first();

            if (!$invitation || $invitation->isExpired() || !$invitation->isPending()) {
                return response()->json(['message' => 'Invalid or expired invitation'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Bypass registration open check for valid invitations
        } elseif (Settings::get('disableRegistration') == true) {
            return response()->json(['message' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validated();

        // Pre-fill email from invitation if available
        if ($invitation && empty($validated['email'])) {
            $validated['email'] = $invitation->email;
        }

        event(new Registered($user = $this->create($validated)));

        // Mark invitation as accepted
        if ($invitation) {
            $invitation->update(['accepted_at' => now()]);
        }

        $this->guard()->login($user);
        /**
         * @var \App\Models\User|null
         */
        $user = $this->guard()->user();

        return response()->json([
            'message'        => 'account created',
            'name'           => $user->name,
            'email'          => $user->email,
            'preferences'    => $user->preferences,
            'is_admin'       => $user->isAdministrator(),
            'e2ee_required'  => app(\App\Services\EncryptionService::class)->isEncryptionRequired($user),
        ], 201);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @return \App\Models\User
     */
    protected function create(array $data)
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'encryption_enabled' => false,
            'encryption_salt' => null,
            'encryption_test_value' => null,
            'encryption_version' => 0,
            'vault_locked' => false,
        ]);

        Log::info(sprintf('User ID #%s created', $user->id));

        if (User::count() == 1) {
            $user->promoteToAdministrator();
            $user->save();
            Log::notice(sprintf('User ID #%s set as administrator', $user->id));
        }

        return $user;
    }
}
