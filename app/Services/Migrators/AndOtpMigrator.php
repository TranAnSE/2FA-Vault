<?php

namespace App\Services\Migrators;

use App\Exceptions\InvalidMigrationDataException;
use App\Models\TwoFAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AndOtpMigrator extends Migrator
{
    /**
     * Convert andOTP migration data to a TwoFAccounts collection.
     *
     * @param  mixed  $migrationPayload  Migration JSON from andOTP export
     * @return \Illuminate\Support\Collection<int|string, \App\Models\TwoFAccount> The converted accounts
     */
    public function migrate(mixed $migrationPayload) : Collection
    {
        $json = json_decode($migrationPayload, true);

        // andOTP export format:
        // {
        //   "encrypted": false,
        //   "tokens": [
        //     {
        //       "type": "TOTP",
        //       "secret": "A4GRFTVVRBGY7UIW",
        //       "issuer": "Service",
        //       "label": "user@example.com",
        //       "digits": 6,
        //       "period": 30,
        //       "algorithm": "SHA1",
        //       "thumbnail": null
        //     }
        //   ]
        // }

        if (is_null($json) || !Arr::has($json, 'tokens')) {
            Log::error('andOTP JSON migration data cannot be read');
            throw new InvalidMigrationDataException('andOTP');
        }

        // Check if encrypted (not supported)
        if (Arr::get($json, 'encrypted') === true) {
            Log::error('andOTP encrypted export is not supported');
            throw new InvalidMigrationDataException('andOTP encrypted');
        }

        $tokens = $json['tokens'];

        /**
         * @var array<int|string, \App\Models\TwoFAccount> $twofaccounts
         */
        $twofaccounts = [];

        foreach ($tokens as $key => $otp_parameters) {
            try {
                $parameters              = [];
                $parameters['otp_type']  = strtolower($otp_parameters['type']) === 'hotp' ? TwoFAccount::HOTP : TwoFAccount::TOTP;
                $parameters['service']   = $otp_parameters['issuer'] ?? '';
                $parameters['account']   = $otp_parameters['label'] ?? $parameters['service'];
                $parameters['secret']    = $this->padToValidBase32Secret($otp_parameters['secret']);
                $parameters['algorithm'] = strtolower($otp_parameters['algorithm'] ?? 'sha1');
                $parameters['digits']    = $otp_parameters['digits'] ?? 6;
                $parameters['period']    = $otp_parameters['period'] ?? null;
                $parameters['counter']   = $parameters['otp_type'] === TwoFAccount::HOTP
                    ? ($otp_parameters['counter'] ?? 0)
                    : null;

                $twofaccounts[$key] = new TwoFAccount;
                $twofaccounts[$key]->fillWithOtpParameters($parameters);
            } catch (\Exception $exception) {
                Log::error(sprintf('Cannot instanciate a TwoFAccount object with OTP parameters from imported item #%s', $key));
                Log::debug($exception->getMessage());

                // Create a fake account to be returned
                $fakeAccount           = new TwoFAccount;
                $fakeAccount->id       = TwoFAccount::FAKE_ID;
                $fakeAccount->otp_type = TwoFAccount::TOTP;
                $fakeAccount->account  = $otp_parameters['label'] ?? __('message.invalid_account');
                $fakeAccount->service  = $otp_parameters['issuer'] ?? __('message.invalid_service');
                $fakeAccount->secret   = $exception->getMessage();

                $twofaccounts[$key] = $fakeAccount;
            }
        }

        return collect($twofaccounts);
    }
}
