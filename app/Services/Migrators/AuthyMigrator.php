<?php

namespace App\Services\Migrators;

use App\Exceptions\InvalidMigrationDataException;
use App\Models\TwoFAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Authy Migrator
 *
 * @beta Accepts only authy-export JSON. Native Authy encrypted backups are NOT supported.
 */
class AuthyMigrator extends Migrator
{
    /**
     * Convert Authy migration data to a TwoFAccounts collection.
     *
     * @param  mixed  $migrationPayload  Migration JSON from authy-export tool
     * @return \Illuminate\Support\Collection<int|string, \App\Models\TwoFAccount> The converted accounts
     */
    public function migrate(mixed $migrationPayload) : Collection
    {
        // Native Authy backups are encrypted binary/key-value files, not JSON.
        // If json_decode fails the payload is not authy-export JSON, so we refuse it
        // explicitly rather than treating it as an empty account list.
        $json = json_decode((string) $migrationPayload, true);

        if (! is_array($json) || ! Arr::has($json, 'accounts')) {
            Log::error('Authy JSON migration data cannot be read or is not from authy-export tool');
            throw new InvalidMigrationDataException('Authy (BETA)');
        }

        $accounts = $json['accounts'];

        /**
         * @var array<int|string, \App\Models\TwoFAccount> $twofaccounts
         */
        $twofaccounts = [];

        foreach ($accounts as $key => $otp_parameters) {
            try {
                $parameters              = [];
                $parameters['otp_type']  = TwoFAccount::TOTP;
                $parameters['digits']    = $otp_parameters['digits'] ?? 6;
                $parameters['secret']    = $this->padToValidBase32Secret($otp_parameters['totp_secret']);
                $parameters['algorithm'] = 'sha1';
                $parameters['period']    = 30;
                $parameters['counter']   = null;

                // Parse name field which may be "Service:user@example.com" or just "Service"
                $nameParts = explode(':', $otp_parameters['name'] ?? '');

                if (count($nameParts) > 1) {
                    $parameters['service'] = $nameParts[0];
                    $parameters['account'] = $nameParts[1];
                } else {
                    $parameters['service'] = $otp_parameters['name'] ?? '';
                    $parameters['account'] = $parameters['service'];
                }

                $twofaccounts[$key] = new TwoFAccount;
                $twofaccounts[$key]->fillWithOtpParameters($parameters);
            } catch (\Exception $exception) {
                Log::error(sprintf('Cannot instanciate a TwoFAccount object with OTP parameters from imported item #%s', $key));
                Log::debug($exception->getMessage());

                // Create a fake account to be returned
                $fakeAccount           = new TwoFAccount;
                $fakeAccount->id       = TwoFAccount::FAKE_ID;
                $fakeAccount->otp_type = TwoFAccount::TOTP;
                $fakeAccount->account  = $otp_parameters['name'] ?? __('message.invalid_account');
                $fakeAccount->service  = __('message.invalid_service');
                $fakeAccount->secret   = $exception->getMessage();

                $twofaccounts[$key] = $fakeAccount;
            }
        }

        return collect($twofaccounts);
    }
}
