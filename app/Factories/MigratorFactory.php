<?php

namespace App\Factories;

use App\Exceptions\EncryptedMigrationException;
use App\Exceptions\UnsupportedMigrationException;
use App\Services\Migrators\AegisMigrator;
use App\Services\Migrators\AndOtpMigrator;
use App\Services\Migrators\AuthyMigrator;
use App\Services\Migrators\BitwardenMigrator;
use App\Services\Migrators\FreeOtpPlusMigrator;
use App\Services\Migrators\GoogleAuthMigrator;
use App\Services\Migrators\Migrator;
use App\Services\Migrators\PlainTextMigrator;
use App\Services\Migrators\RaivoMigrator;
use App\Services\Migrators\TwoFASMigrator;
use App\Services\Migrators\TwoFAuthMigrator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;

class MigratorFactory implements MigratorFactoryInterface
{
    /**
     * Infer the type of migrator needed from a payload and create the migrator
     *
     * @param  string  $migrationPayload  The migration payload used to infer the migrator type
     */
    public function create(string $migrationPayload) : Migrator
    {
        if ($this->isTwoFAuthJSON($migrationPayload)) {
            return App::make(TwoFAuthMigrator::class);
        } elseif ($this->isAegisJSON($migrationPayload)) {
            return App::make(AegisMigrator::class);
        } elseif ($this->is2FASv2($migrationPayload)) {
            return App::make(TwoFASMigrator::class);
        } elseif (self::isGoogleAuth($migrationPayload)) {
            return App::make(GoogleAuthMigrator::class);
        } elseif ($this->isBitwardenJson($migrationPayload)) {
            return App::make(BitwardenMigrator::class);
        } elseif ($this->isRaivoJson($migrationPayload)) {
            return App::make(RaivoMigrator::class);
        } elseif ($this->isAndOtpJson($migrationPayload)) {
            return App::make(AndOtpMigrator::class);
        } elseif ($this->isFreeOtpPlusJson($migrationPayload)) {
            return App::make(FreeOtpPlusMigrator::class);
        } elseif ($this->isAuthyJson($migrationPayload)) {
            return App::make(AuthyMigrator::class);
        } elseif ($this->isPlainText($migrationPayload)) {
            return App::make(PlainTextMigrator::class);
        } else {
            throw new UnsupportedMigrationException;
        }
    }

    /**
     * Determine if a payload comes from Google Authenticator
     *
     * @param  string  $migrationPayload  The payload to analyse
     */
    public static function isGoogleAuth(string $migrationPayload) : bool
    {
        // - Google Auth migration URI : a string starting with otpauth-migration://offline?data= on a single line

        $lines = preg_split('~\R~', $migrationPayload, -1, PREG_SPLIT_NO_EMPTY);

        if (! $lines || count($lines) != 1) {
            return false;
        }

        return preg_match('/^otpauth-migration:\/\/offline\?data=.+$/', $lines[0]) == 1;
    }

    /**
     * Determine if a payload is a plain text content
     *
     * @param  string  $migrationPayload  The payload to analyse
     */
    private function isPlainText(string $migrationPayload) : bool
    {
        // - Plain text : one or more otpauth URIs (otpauth://(hotp|totp|steam)/...), one per line

        return Validator::make(
            preg_split('~\R~', $migrationPayload, -1, PREG_SPLIT_NO_EMPTY),
            [
                // The regex rule must be embraced with brackets when it cointains a pipe
                '*' => ['regex:/^otpauth:\/\/(?:steam|totp|hotp)\//i'],
            ]
        )->passes();
    }

    /**
     * Determine if a payload comes from 2FA-Vault in JSON format
     *
     * @param  string  $migrationPayload  The payload to analyse
     */
    private function isTwoFAuthJSON(string $migrationPayload) : bool
    {
        $json = json_decode($migrationPayload, true);

        if (Arr::has($json, 'schema') && (strpos(Arr::get($json, 'app'), '2fauth_') === 0)) {
            return count(Validator::validate(
                $json,
                [
                    'data.*.otp_type'  => 'present',
                    'data.*.service'   => 'present',
                    'data.*.account'   => 'present',
                    'data.*.secret'    => 'present',
                    'data.*.digits'    => 'present',
                    'data.*.algorithm' => 'present',
                    'data.*.period'    => 'present',
                    'data.*.counter'   => 'present',
                ]
            )) > 0;
        }

        return false;
    }

    /**
     * Determine if a payload comes from Aegis Authenticator in JSON format
     *
     * @param  string  $migrationPayload  The payload to analyse
     * @return bool
     */
    private function isAegisJSON(string $migrationPayload) : mixed
    {
        // - Aegis JSON : is a JSON object with the key db.entries full of objects like
        //      {
        //          "type": "totp",
        //          "uuid": "5be1c189-240d-5fe1-930b-a78xb669zd86",
        //          "name": "John DOE",
        //          "issuer": "Facebook",
        //          "note": "",
        //          "icon": null,
        //          "info": {
        //              "secret": "A4GRFTVVRBGY7UIW",
        //              "algo": "SHA1",
        //              "digits": 6,
        //              "period": 30
        //          }
        //      }

        $json = json_decode($migrationPayload, true);

        if (Arr::has($json, 'db')) {
            if (is_string($json['db']) && is_array(Arr::get($json, 'header.slots'))) {
                throw new EncryptedMigrationException;
            } else {
                return count(Validator::validate(
                    $json,
                    [
                        'db.entries.*.type'   => 'present',
                        'db.entries.*.name'   => 'present',
                        'db.entries.*.issuer' => 'present',
                        'db.entries.*.info'   => 'present',
                    ]
                )) > 0;
            }
        }

        return false;
    }

    /**
     * Determine if a payload comes from 2FAS Authenticator
     *
     * @param  string  $migrationPayload  The payload to analyse
     * @return bool
     */
    private function is2FASv2(string $migrationPayload) : mixed
    {
        // - 2FAS JSON : is a JSON object with a 'schemaVersion' key and a key 'services' full of objects like
        // {
        //     "secret": "A4GRFTVVRBGY7UIW",
        //     ...
        //     "otp":
        //     {
        //         "account": "John DOE",
        //         "digits": 6,
        //         "counter": 0,
        //         "period": 30,
        //         "algorithm": "SHA1",
        //         "tokenType": "TOTP"
        //     },
        //     "type": "ManuallyAdded",
        //     "name": "Facebook",
        //     "icon":
        //     {
        //         ...
        //     }
        // }

        $json = json_decode($migrationPayload, true);

        if (Arr::has($json, 'schemaVersion') && (Arr::has($json, 'services') || Arr::has($json, 'servicesEncrypted'))) {
            if (Arr::has($json, 'servicesEncrypted')) {
                throw new EncryptedMigrationException;
            } else {
                return count(Validator::validate(
                    $json,
                    [
                        'services.*.secret' => 'present',
                        'services.*.name'   => 'present',
                        'services.*.otp'    => 'present',
                    ]
                )) > 0;
            }
        }

        return false;
    }

    /**
     * Determine if a payload comes from Bitwarden Authenticator
     *
     * @param  string  $migrationPayload  The payload to analyse
     * @return bool
     */
    private function isBitwardenJson(string $migrationPayload) : mixed
    {
        // JSON from the bitwarden app:
        //
        // {
        //   "encrypted": false,
        //   "folders": [],
        //   "items": [
        //     {
        //       "passwordHistory": [],
        //       "revisionDate": "2025-10-28T15:27:43.012Z",
        //       "creationDate": "2024-01-03T12:30:50.043Z",
        //       "deletedDate": null,
        //       "archivedDate": null,
        //       "id": "d135d04d-58d0-4f16-83fa-576280caa73d",
        //       "organizationId": null,
        //       "folderId": null,
        //       "type": 1,
        //       "reprompt": 0,
        //       "name": "Google",
        //       "notes": null,
        //       "favorite": false,
        //       "fields": [],
        //       "login": {
        //         "uris": [
        //           {
        //             "match": null,
        //             "uri": "http://localhost/login"
        //           }
        //         ],
        //         "username": "john.doe@gmail.com",
        //         "password": "password",
        //         "totp": "otpauth://totp/Google%3Ajohn%2Edoe?issuer=Google&secret=A5GRFTVVRBGY7UIW"
        //       },
        //       "collectionIds": null
        //     }
        //   ]
        // }
        //
        // JSON form the bitwarden authenticator mobile app:
        //
        // {
        //     "encrypted": false,
        //     "items": [
        //         {
        //             "favorite": false,
        //             "id": "d135d04d-58d0-4f16-83fa-576280caa73d",
        //             "login": {
        //                 "totp": "otpauth://totp/Google:john%2Edoe%40gmail%2Ecom?secret=A5GRFTVVRBGY7UIW&issuer=Google&algorithm=SHA256&digits=7&period=60",
        //                 "username": "john.doe@gmail.com"
        //             },
        //             "name": "Google",
        //             "type": 1
        //         },
        //         {
        //             "favorite": false,
        //             "id": "d135d04d-58d0-4f16-83fa-576280caa73d",
        //             "login": {
        //                 "totp": "steam://A5GRFTVVRBGY7UIW",
        //                 "username": "john.doe@gmail.com"
        //              },
        //              "name": "Steam",
        //              "type": 1
        //         }
        //     ]
        // }

        $json = json_decode($migrationPayload, true);

        if (Arr::has($json, 'encrypted') && (Arr::has($json, 'items'))) {
            if ($json['encrypted'] == true) {
                throw new EncryptedMigrationException;
            } else {
                return count(Validator::validate(
                    $json,
                    [
                        'items.*.id'             => 'present',
                        'items.*.name'           => 'present',
                        'items.*.login.username' => 'present',
                        'items.*.login.totp'     => 'present',
                    ]
                )) > 0;
            }
        }

        return false;
    }

    /**
     * Determine if a payload comes from Raivo Authenticator
     *
     * @param  string  $migrationPayload  The payload to analyse
     * @return bool
     */
    private function isRaivoJson(string $migrationPayload) : bool
    {
        $json = json_decode($migrationPayload, true);

        // Raivo format: {"2fas-backup.json": [...]}
        if (Arr::has($json, '2fas-backup.json') && is_array($json['2fas-backup.json'])) {
            return count(Validator::validate(
                $json['2fas-backup.json'],
                [
                    '*.issuer' => 'present',
                    '*.account' => 'present',
                    '*.secret' => 'present',
                ]
            )) > 0;
        }

        return false;
    }

    /**
     * Determine if a payload comes from andOTP
     *
     * @param  string  $migrationPayload  The payload to analyse
     * @return bool
     */
    private function isAndOtpJson(string $migrationPayload) : mixed
    {
        $json = json_decode($migrationPayload, true);

        // andOTP format: {"encrypted": false, "tokens": [...]}
        if (Arr::has($json, 'tokens') && Arr::has($json, 'encrypted')) {
            if ($json['encrypted'] == true) {
                throw new EncryptedMigrationException;
            }
            return count(Validator::validate(
                $json,
                [
                    'tokens.*.type' => 'present',
                    'tokens.*.secret' => 'present',
                    'tokens.*.label' => 'present',
                ]
            )) > 0;
        }

        return false;
    }

    /**
     * Determine if a payload comes from FreeOTP+
     *
     * @param  string  $migrationPayload  The payload to analyse
     * @return bool
     */
    private function isFreeOtpPlusJson(string $migrationPayload) : mixed
    {
        $json = json_decode($migrationPayload, true);

        // FreeOTP+ format: {"tokens": [...]}
        if (Arr::has($json, 'tokens')) {
            return count(Validator::validate(
                $json,
                [
                    'tokens.*.label' => 'present',
                    'tokens.*.secret' => 'present',
                    'tokens.*.type' => 'present',
                ]
            )) > 0;
        }

        return false;
    }

    /**
     * Determine if a payload comes from Authy (authy-export tool)
     *
     * @param  string  $migrationPayload  The payload to analyse
     * @return bool
     */
    private function isAuthyJson(string $migrationPayload) : mixed
    {
        $json = json_decode($migrationPayload, true);

        // Authy (authy-export tool) format: {"accounts": [...]}
        if (Arr::has($json, 'accounts')) {
            return count(Validator::validate(
                $json,
                [
                    'accounts.*.name' => 'present',
                    'accounts.*.totp_secret' => 'present',
                ]
            )) > 0;
        }

        return false;
    }
}
