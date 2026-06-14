<?php

namespace App\Services\Migrators;

use App\Exceptions\InvalidMigrationDataException;
use App\Models\TwoFAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RaivoMigrator extends Migrator
{
    /**
     * Convert Raivo migration data to a TwoFAccounts collection.
     *
     * @param  mixed  $migrationPayload  Migration JSON from Raivo export
     * @return \Illuminate\Support\Collection<int|string, \App\Models\TwoFAccount> The converted accounts
     */
    public function migrate(mixed $migrationPayload) : Collection
    {
        $json = json_decode($migrationPayload, true);

        if (is_null($json) || !Arr::has($json, '2fas-backup.json')) {
            Log::error('Raivo JSON migration data cannot be read');
            throw new InvalidMigrationDataException('Raivo');
        }

        // Raivo exports can be in a zip file, but we expect pre-extracted JSON
        // The JSON structure is:
        // {
        //   "2fas-backup.json": [
        //     {
        //       "issuer": "Service",
        //       "account": "user@example.com",
        //       "secret": "A4GRFTVVRBGY7UIW",
        //       "algorithm": "SHA1",
        //       "digits": 6,
        //       "timer": 30
        //     }
        //   ]
        // }

        $entries = $json['2fas-backup.json'];

        /**
         * @var array<int|string, \App\Models\TwoFAccount> $twofaccounts
         */
        $twofaccounts = [];

        foreach ($entries as $key => $otp_parameters) {
            try {
                $parameters              = [];
                $parameters['otp_type']  = TwoFAccount::TOTP;
                $parameters['service']   = $otp_parameters['issuer'] ?? '';
                $parameters['account']   = $otp_parameters['account'] ?? $parameters['service'];
                $parameters['secret']    = $this->padToValidBase32Secret($otp_parameters['secret']);
                $parameters['algorithm'] = strtolower($otp_parameters['algorithm'] ?? 'sha1');
                $parameters['digits']    = $otp_parameters['digits'] ?? 6;
                $parameters['period']    = $otp_parameters['timer'] ?? 30;
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
                $fakeAccount->account  = $otp_parameters['account'] ?? __('message.invalid_account');
                $fakeAccount->service  = $otp_parameters['issuer'] ?? __('message.invalid_service');
                $fakeAccount->secret   = $exception->getMessage();

                $twofaccounts[$key] = $fakeAccount;
            }
        }

        return collect($twofaccounts);
    }
}
