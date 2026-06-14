<?php

namespace App\Services\Migrators;

use App\Exceptions\InvalidMigrationDataException;
use App\Models\TwoFAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class FreeOtpPlusMigrator extends Migrator
{
    /**
     * Convert FreeOTP+ migration data to a TwoFAccounts collection.
     *
     * @param  mixed  $migrationPayload  Migration JSON from FreeOTP+ export
     * @return \Illuminate\Support\Collection<int|string, \App\Models\TwoFAccount> The converted accounts
     */
    public function migrate(mixed $migrationPayload) : Collection
    {
        $json = json_decode($migrationPayload, true);

        // FreeOTP+ export format:
        // {
        //   "tokens": [
        //     {
        //       "issuerExt": "Service",
        //       "label": "user@example.com",
        //       "secret": "A4GRFTVVRBGY7UIW",
        //       "algo": "SHA1",
        //       "digits": 6,
        //       "period": 30,
        //       "type": "TOTP"
        //     }
        //   ]
        // }

        if (is_null($json) || !Arr::has($json, 'tokens')) {
            Log::error('FreeOTP+ JSON migration data cannot be read');
            throw new InvalidMigrationDataException('FreeOTP+');
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
                $parameters['service']   = $otp_parameters['issuerExt'] ?? '';
                $parameters['account']   = $otp_parameters['label'] ?? $parameters['service'];
                $parameters['secret']    = $this->padToValidBase32Secret($otp_parameters['secret']);
                $parameters['algorithm'] = strtolower($otp_parameters['algo'] ?? 'sha1');
                $parameters['digits']    = $otp_parameters['digits'] ?? 6;
                $parameters['period']    = $otp_parameters['period'] ?? null;
                $parameters['counter']   = null;

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
                $fakeAccount->service  = $otp_parameters['issuerExt'] ?? __('message.invalid_service');
                $fakeAccount->secret   = $exception->getMessage();

                $twofaccounts[$key] = $fakeAccount;
            }
        }

        return collect($twofaccounts);
    }
}
