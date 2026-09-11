<?php

namespace App\Api\v1\Resources;

use App\Facades\IconStore;
use App\Models\TwoFAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @property mixed $otp_type
 * @property string $account
 * @property string $service
 * @property string $icon
 * @property string $secret
 * @property int $digits
 * @property string $algorithm
 * @property int|null $period
 * @property int|null $counter
 */
class TwoFAccountStoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'otp_type' => $this->otp_type,
            'account'  => $this->account,
            'service'  => $this->service,
            'icon'     => $this->icon && IconStore::exists($this->icon) ? $this->icon : null,
            'secret'   => $this->when(
                (! $request->has('withSecret') || (int) filter_var($request->input('withSecret'), FILTER_VALIDATE_BOOLEAN) == 1)
                && $this->requesterCanReadSecret($request),
                $this->secret
            ),
            'digits'         => (int) $this->digits,
            'algorithm'      => $this->algorithm,
            'period'         => is_null($this->period) ? null : (int) $this->period,
            'counter'        => is_null($this->counter) ? null : (int) $this->counter,
            'notes'          => $this->notes,
            'is_pinned'      => (bool) $this->is_pinned,
            'recovery_codes' => $this->recovery_codes,
        ];
    }

    /**
     * Secret-leakage gate (leak audit 2026-09-12, upstream 088aa0583 analog).
     *
     * A persisted account's secret — plaintext for non-E2EE accounts, the
     * encrypted_secret blob otherwise — must only reach users allowed by
     * TwoFAccountPolicy::readSecret (the owner). Shared-account members hold
     * only the `view` ability (server-side OTP generation) and must not
     * obtain the secret, even as ciphertext. Unpersisted models (preview and
     * migration echoes of the requester's own input) carry no cross-user
     * data and are always allowed.
     */
    private function requesterCanReadSecret(Request $request) : bool
    {
        $twofaccount = $this->resource;

        if (! $twofaccount instanceof TwoFAccount || ! $twofaccount->exists) {
            return true;
        }

        $user = $request->user();

        return $user !== null && Gate::forUser($user)->allows('readSecret', $twofaccount);
    }
}
