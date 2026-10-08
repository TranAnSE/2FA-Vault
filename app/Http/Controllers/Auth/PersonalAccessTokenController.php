<?php

namespace App\Http\Controllers\Auth;

use App\Services\Auth\PassportTokenRepository;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenResult;
use Laravel\Passport\Token;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PersonalAccessTokenController
{
    /**
     * Create a controller instance.
     */
    public function __construct(
        protected PassportTokenRepository $tokenRepository,
        protected ValidationFactory $validation,
    ) {}

    /**
     * Get all of the personal access tokens for the authenticated user.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Passport\Token>|\Illuminate\Http\JsonResponse
     */
    public function forUser(Request $request)
    {
        if (Gate::denies('manage-pat')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        return $this->tokenRepository->forUser($request->user())
            ->filter(
                fn (Token $token) : bool => ! $token->client->revoked && $token->client->hasGrantType('personal_access')
            )
            ->values();
    }

    /**
     * Create a new personal access token for the user.
     *
     * @return \Laravel\Passport\PersonalAccessTokenResult<\Laravel\Passport\Token>
     */
    public function store(Request $request) : PersonalAccessTokenResult
    {
        if (Gate::denies('manage-pat')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        // A6 / RT1: a new PAT must explicitly request at least one valid
        // scope. The omitted-scopes default ([] from Passport's parent
        // implementation) must 422 — otherwise attackers could mint
        // unscoped, effectively-full-access tokens.
        $this->validation->make($request->all(), [
            'name'   => ['required', 'max:255'],
            'scopes' => ['required', 'array', 'min:1', Rule::in(Passport::scopeIds())],
        ])->validate();

        return $request->user()->createToken(
            $request->name,
            Passport::validScopes($request->scopes)
        );
    }

    /**
     * Delete the given token.
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, string $tokenId) : Response
    {
        if (Gate::denies('manage-pat')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        $token = $this->tokenRepository->findForUser(
            $tokenId, $request->user()
        );

        if (is_null($token)) {
            return new Response('', 404);
        }

        $token->revoke();

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
