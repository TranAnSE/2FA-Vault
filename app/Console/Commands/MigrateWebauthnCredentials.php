<?php

namespace App\Console\Commands;

use App\Support\Passkeys\CredentialRecordFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\CredentialRecord;

/**
 * One-time ETL (plan 261009-passkeys-migration phase 4, D1): converts every
 * laragear webauthn_credentials row into a laravel/passkeys row webauthn-lib
 * can verify.
 *
 * The conversion chain per row: public_key (encrypted PEM behind laragear's
 * `encrypted` cast) -> PemToCoseConverter -> COSE CBOR bytes ->
 * CredentialRecord -> JSON -> passkeys.credential.
 *
 * Run order for operators (runbook in docs/guides/): backup -> --dry-run ->
 * ETL -> --verify. Idempotent: rows already converted are SKIPPED, so the
 * mandatory post-cutover re-run sweeps registrations made by old-code
 * containers during the window (C6).
 */
class MigrateWebauthnCredentials extends Command
{
    protected $signature = '2fauth:migrate-webauthn-credentials
        {--dry-run : Report without writing}
        {--force : Run without confirmation prompt}
        {--verify : Post-conversion structural verification of every passkeys row}';

    protected $description = 'One-time migration of laragear webauthn_credentials into laravel/passkeys rows';

    protected const CHUNK = 500;

    protected bool $verifyFailed = false;

    public function handle() : int
    {
        $legacyCount = DB::table('webauthn_credentials')->count();

        $this->info("webauthn_credentials rows: {$legacyCount}");

                if ($legacyCount === 0 && DB::table('passkeys')->count() === 0) {
            $this->info('Nothing to migrate.');

            return self::SUCCESS;
        }

        $passkeyCount = DB::table('passkeys')->count();

        // R6: never convert into a populated target without --force.
        if ($passkeyCount > 0 && ! $this->option('dry-run') && ! $this->option('force')) {
            $this->error('The passkeys table already has rows. Re-run with --force (idempotent: converted rows are skipped).');

            return self::FAILURE;
        }

        // R6: APP_KEY decrypt pre-flight on a random sample before any write.
        if (! $this->option('dry-run') && ! $this->preflightDecryptCheck()) {
            return self::FAILURE;
        }

        $report = [
            'total' => $legacyCount,
            'ok' => 0,
            'fail' => 0,
            'skipped' => 0,
            'algorithms' => [],
            'nullColumns' => ['transports' => 0, 'counter' => 0, 'aaguid' => 0, 'alias' => 0],
            'credentialId' => ['min' => PHP_INT_MAX, 'max' => 0],
            'failures' => [],
        ];

        $this->migrate($report);

        $this->printReport($report);

        if ($this->option('verify') && $report['fail'] === 0) {
            $this->verify();
        }

        if ($this->verifyFailed || $report['fail'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The conversion pass (dry-run or live). Live rows convert inside their
     * own transaction: a failure aborts only its row (R3).
     */
    protected function migrate(array &$report) : void
    {
        WebAuthnCredential::query()->orderBy('id')->chunkById(self::CHUNK, function ($rows) use (&$report) {
            foreach ($rows as $row) {
                $this->collectRowStats($row, $report);

                if (DB::table('passkeys')->where('credential_id', $row->id)->exists()) {
                    $report['skipped']++;
                    continue;
                }

                if ($this->option('dry-run')) {
                    $ok = $this->canConvert($row);
                } else {
                    try {
                        $ok = DB::transaction(function () use ($row, $report) {
                            $record = CredentialRecordFactory::fromLegacyCredential($row);

                            DB::table('passkeys')->insert([
                                'user_id' => $row->authenticatable_id,
                                'name' => $row->alias ?? ('Passkey '.($report['ok'] + $report['skipped'] + 1)),
                                'credential_id' => $row->id,
                                'credential' => CredentialRecordFactory::toCredentialJson($record),
                                'last_used_at' => null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            return true;
                        });
                    } catch (\Throwable $e) {
                        // R3: a failing row aborts only itself.
                        $ok = $e->getMessage();
                    }
                }

                if ($ok === true) {
                    $report['ok']++;
                    $report['algorithms'][$this->algorithmOf($row)] = ($report['algorithms'][$this->algorithmOf($row)] ?? 0) + 1;
                } else {
                    $report['fail']++;
                    $report['failures'][] = ['id' => $row->id, 'reason' => $ok];
                }
            }
        });
    }

    /**
     * Structural verification (R5/H7, honestly scoped): every converted row
     * must JSON round-trip through the real denormalizer, carry a parseable
     * COSE key and a well-formed userHandle/aaguid. Production rows cannot
     * be signature-verified here (private keys live on user devices); the
     * live-login matrix remains the functional gate.
     */
    protected function verify() : void
    {
        $this->info('Verifying converted rows...');

        $checked = 0;
        $failed = 0;

        DB::table('passkeys')->orderBy('id')->chunk(500, function ($rows) use (&$checked, &$failed) {
            foreach ($rows as $row) {
                try {
                    // Full denormalization round-trip: parses the embedded
                    // COSE key, aaguid and trust path.
                    $record = WebAuthn::fromJson($row->credential, CredentialRecord::class);

                    if (! is_string($record->credentialPublicKey) || strlen($record->credentialPublicKey) === 0) {
                        throw new \RuntimeException('Empty COSE key.');
                    }

                    // C2 correction: converted handles are base64UrlDecode of the
                    // 32-hex user id -- exactly 24 bytes.
                    if (strlen($record->userHandle) !== 24) {
                        throw new \RuntimeException('Unexpected userHandle length.');
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn('VERIFY FAIL row #' . $row->id . ' : ' . $e->getMessage());
                    continue;
                }

                $checked++;
            }
        });

        if ($failed > 0) {
            $this->error('Verification failures: ' . $failed . ' (do NOT cut over traffic).');

            $this->verifyFailed = true;
        }

        $this->info('Verified ' . $checked . ' converted row(s).');
    }

    /**
     * Per-row failure probe (dry-run): returns true or the failure reason.
     *
     * @return true|string
     */
    protected function canConvert(WebAuthnCredential $row) : bool|string
    {
        try {
            $record = CredentialRecordFactory::fromLegacyCredential($row);

            // Round-trip through the real denormalizer (as --verify does).
            WebAuthn::fromJson(CredentialRecordFactory::toCredentialJson($record), CredentialRecord::class);

            return true;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Null-column and credential-id length stats (R2, H6/M4).
     */
    protected function collectRowStats(WebAuthnCredential $row, array &$report) : void
    {
        foreach (['transports', 'counter', 'aaguid', 'alias'] as $column) {
            if ($row->{$column} === null) {
                $report['nullColumns'][$column]++;
            }
        }

        $len = strlen($row->id);

        $report['credentialId']['min'] = min($report['credentialId']['min'], $len);
        $report['credentialId']['max'] = max($report['credentialId']['max'], $len);
    }

    /**
     * Algorithm class of the row's key (for the R2 histogram).
     */
    protected function algorithmOf(WebAuthnCredential $row) : string
    {
        try {
            $details = openssl_pkey_get_details(openssl_pkey_get_public($row->public_key));

            return match ($details['type'] ?? null) {
                OPENSSL_KEYTYPE_EC => 'ES256',
                OPENSSL_KEYTYPE_RSA => 'RS256',
                OPENSSL_KEYTYPE_ED25519 => 'EdDSA',
                default => 'unknown',
            };
        } catch (\Throwable) {
            return 'undecryptable';
        }
    }

    /**
     * APP_KEY decrypt check (R6/H2): a random sample of legacy rows must
     * decrypt to PEM before any write happens.
     */
    protected function preflightDecryptCheck() : bool
    {
        $sample = DB::table('webauthn_credentials')->inRandomOrder()->limit(min(5, DB::table('webauthn_credentials')->count()))->get();

        if ($sample->isEmpty()) {
            return true;
        }

        foreach ($sample as $row) {
            $pem = $row->public_key;

            // Raw table access: values are still APP_KEY-encrypted here
            // (no model cast), so decrypt manually.
            try {
                $decrypted = \Illuminate\Support\Facades\Crypt::decryptString($pem);
            } catch (\Throwable $e) {
                $this->error('Pre-flight decrypt FAILED (APP_KEY mismatch?): run aborted. '.$e->getMessage());

                return false;
            }

            if (! str_contains($decrypted, 'BEGIN PUBLIC KEY')) {
                $this->error('Pre-flight decrypt: payload is not a PEM public key. Run aborted.');

                return false;
            }
        }

        $this->info('Pre-flight decrypt check passed on '.$sample->count().' sample row(s).');

        return true;
    }

    /**
     * The R2 report.
     */
    protected function printReport(array $report) : void
    {
        $this->info('');
        $this->info('=== WebAuthn credentials migration report ===');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total legacy rows', $report['total']],
                ['Converted'.($this->option('dry-run') ? ' (dry-run)' : ''), $report['ok']],
                ['Skipped (already converted)', $report['skipped']],
                ['Failed', $report['fail']],
                ['Credential-id length (min/max)', $report['credentialId']['min'].' / '.$report['credentialId']['max']],
                ['Null transports', $report['nullColumns']['transports']],
                ['Null counter', $report['nullColumns']['counter']],
                ['Null aaguid', $report['nullColumns']['aaguid']],
                ['Null alias', $report['nullColumns']['alias']],
            ]
        );

        if ($report['algorithms'] !== []) {
            $rows = array_map(fn ($algo, $count) => [$algo, $count], array_keys($report['algorithms']), $report['algorithms']);
            $this->table(['Algorithm', 'Count'], $rows);
        }

        foreach ($report['failures'] as $failure) {
            $this->warn('FAIL '.$failure['id'].' : '.$failure['reason']);
        }

        if ($report['fail'] > 0) {
            $this->warn('Non-zero failures: do NOT cut over traffic. See the runbook (docs/guides/passkeys-migration.md).');
        }
    }
}
