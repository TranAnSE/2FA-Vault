<?php

namespace App\Services;

use App\Models\Group;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\Migrators\AegisMigrator;
use App\Services\Migrators\BitwardenMigrator;
use App\Services\Migrators\GoogleAuthMigrator;
use App\Services\Migrators\Migrator;
use App\Services\Migrators\TwoFAuthMigrator;
use App\Services\Migrators\TwoFASMigrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BackupService
 *
 * Handles encrypted backup/restore for 2FA-Vault.
 *
 * Double Encryption Architecture:
 * 1. Primary encryption: Per-account encryption with user's master key (client-side)
 * 2. Secondary encryption: Backup password for the .vault file (client-side)
 */
class BackupService
{
    const CURRENT_FORMAT_VERSION = '2.0';
    const MIN_SUPPORTED_VERSION = '1.0';
    const MAX_ACCOUNTS_PER_BACKUP = 10000;

    public function __construct(
        private readonly TwoFAuthMigrator $twoFAuthMigrator,
        private readonly TwoFASMigrator $twoFASMigrator,
        private readonly AegisMigrator $aegisMigrator,
        private readonly BitwardenMigrator $bitwardenMigrator,
        private readonly GoogleAuthMigrator $googleAuthMigrator,
    ) {
    }

    /**
     * Generate encrypted backup for user
     *
     * @return array Canonical .vault envelope
     */
    public function generateEncryptedBackup(User $user, bool $includeGroups = true): array
    {
        $accountsQuery = TwoFAccount::where('user_id', $user->id);

        if ($accountsQuery->count() > self::MAX_ACCOUNTS_PER_BACKUP) {
            Log::warning('Large backup requested', [
                'user_id' => $user->id,
                'account_count' => $accountsQuery->count(),
            ]);
        }

        $accounts = $accountsQuery
            ->when($includeGroups, fn ($q) => $q->with('group'))
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'service' => $account->service,
                    'account' => $account->account,
                    'secret' => $account->secret,
                    'encrypted' => $account->encrypted,
                    'algorithm' => $account->algorithm,
                    'digits' => $account->digits,
                    'period' => $account->period,
                    'counter' => $account->counter,
                    'otp_type' => $account->otp_type,
                    'icon' => $account->icon,
                    'group_id' => $account->group_id,
                    'group_name' => $account->group?->name,
                    'created_at' => $account->created_at?->toIso8601String(),
                    'updated_at' => $account->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();

        $backupPayload = [
            'format' => '2FA-Vault',
            'version' => self::CURRENT_FORMAT_VERSION,
            'encrypted' => true,
            'double_encrypted' => true,
            'encryption_version' => $user->encryption_version ?? 0,
            'exported_at' => now()->toIso8601String(),
            'account_count' => count($accounts),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
            'accounts' => $accounts,
        ];

        if ($includeGroups) {
            $backupPayload['groups'] = $user->groups()
                ->orderBy('order_column')
                ->get()
                ->map(function ($group) {
                    return [
                        'id' => $group->id,
                        'name' => $group->name,
                        'order' => $group->order_column,
                    ];
                })
                ->toArray();
        }

        return [
            'app' => '2FA-Vault',
            'version' => self::CURRENT_FORMAT_VERSION,
            'datetime' => now()->toIso8601String(),
            'encryption' => [
                'algorithm' => 'aes-256-gcm',
                'kdf' => 'argon2id',
            ],
            'data' => base64_encode(json_encode($backupPayload)),
            'iv' => null,
            'tag' => null,
        ];
    }

    /**
     * Restore encrypted backup
     *
     * @param array $backupData Decrypted backup data from client
     * @param string $format Backup format ('vault', '2fauth', 'aegis', etc.)
     */
    public function restoreEncryptedBackup(
        User $user,
        array $backupData,
        string $format = 'vault',
        array $options = []
    ): array {
        $conflictResolution = $options['conflict_resolution'] ?? 'skip';
        $importGroups = $options['import_groups'] ?? true;

        $prepared = $this->isVaultFormat($format)
            ? $this->prepareVaultImport($backupData)
            : $this->prepareExternalImport($backupData, $format);

        return $this->persistImportedAccounts(
            $user,
            $prepared['accounts'],
            $prepared['groups'],
            $conflictResolution,
            $importGroups
        );
    }

    private function persistImportedAccounts(
        User $user,
        array $accounts,
        array $groups,
        string $conflictResolution,
        bool $importGroups
    ): array {
        $imported = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            $groupMapping = [];
            if ($importGroups && !empty($groups)) {
                foreach ($groups as $groupData) {
                    try {
                        $group = new Group();
                        $group->user_id = $user->id;
                        $group->name = $groupData['name'];
                        $group->order_column = $groupData['order'] ?? 0;
                        $group->save();

                        $groupMapping[$groupData['id']] = $group->id;
                    } catch (\Exception $e) {
                        Log::warning('Failed to import group', [
                            'group' => $groupData['name'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            foreach ($accounts as $accountData) {
                try {
                    $existingAccount = TwoFAccount::where('user_id', $user->id)
                        ->where('service', $accountData['service'] ?? 'Unknown')
                        ->where('account', $accountData['account'] ?? '')
                        ->first();

                    if ($existingAccount) {
                        switch ($conflictResolution) {
                            case 'skip':
                                $skipped++;
                                continue 2;
                            case 'replace':
                                $account = $existingAccount;
                                break;
                            case 'rename':
                                $accountData['account'] = ($accountData['account'] ?? '') . ' (' . now()->format('YmdHis') . ')';
                                $account = new TwoFAccount();
                                break;
                            default:
                                $account = new TwoFAccount();
                        }
                    } else {
                        $account = new TwoFAccount();
                    }

                    $account->user_id = $user->id;
                    $account->service = $accountData['service'] ?? 'Unknown';
                    $account->account = $accountData['account'] ?? '';
                    $account->secret = $accountData['secret'];
                    $account->encrypted = (bool) ($accountData['encrypted'] ?? false);
                    $account->algorithm = $accountData['algorithm'] ?? 'sha1';
                    $account->digits = (int) ($accountData['digits'] ?? 6);
                    $account->period = (int) ($accountData['period'] ?? 30);
                    $account->counter = $accountData['counter'] ?? null;
                    $account->otp_type = $accountData['otp_type'] ?? 'totp';
                    $account->icon = $accountData['icon'] ?? null;

                    if (!$importGroups) {
                        $account->group_id = null;
                    } elseif (isset($accountData['group_id']) && isset($groupMapping[$accountData['group_id']])) {
                        $account->group_id = $groupMapping[$accountData['group_id']];
                    } else {
                        $account->group_id = $accountData['group_id'] ?? null;
                    }

                    $account->save();
                    $imported++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = [
                        'service' => $accountData['service'] ?? 'Unknown',
                        'account' => $accountData['account'] ?? '',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $user->last_backup_at = now();
            $user->save();

            DB::commit();

            return [
                'imported' => $imported,
                'failed' => $failed,
                'skipped' => $skipped,
                'errors' => $errors,
                'conflict_resolution' => $conflictResolution,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Backup import failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function prepareVaultImport(array $backupData): array
    {
        $normalizedBackup = $this->normalizeVaultBackupData($backupData);

        return [
            'accounts' => $this->extractAccountsFromBackup($normalizedBackup),
            'groups' => $this->extractGroupsFromBackup($normalizedBackup),
        ];
    }

    private function prepareExternalImport(array $backupData, string $format): array
    {
        if (isset($backupData['accounts']) && is_array($backupData['accounts'])) {
            return [
                'accounts' => $this->normalizeExternalAccounts($backupData['accounts']),
                'groups' => [],
            ];
        }

        $migrator = $this->resolveExternalMigrator($format);
        $migrationPayload = $this->extractExternalMigrationPayload($backupData, $format);

        $accounts = $migrator->migrate($migrationPayload)
            ->map(fn (TwoFAccount $account) => $this->mapMigratedAccountToArray($account))
            ->toArray();

        return [
            'accounts' => $accounts,
            'groups' => [],
        ];
    }

    private function extractExternalMigrationPayload(array $backupData, string $format): string
    {
        if ($this->normalizeImportFormat($format) === 'googleauth' && isset($backupData['payload']) && is_string($backupData['payload'])) {
            return $backupData['payload'];
        }

        $migrationPayload = json_encode($backupData, JSON_UNESCAPED_SLASHES);

        if (!is_string($migrationPayload)) {
            throw new \Exception('Invalid backup format: migration payload could not be encoded');
        }

        return $migrationPayload;
    }

    private function mapMigratedAccountToArray(TwoFAccount $account): array
    {
        return [
            'service' => $account->service,
            'account' => $account->account,
            'secret' => $account->secret,
            'encrypted' => false,
            'algorithm' => $account->algorithm,
            'digits' => $account->digits,
            'period' => $account->period,
            'counter' => $account->counter,
            'otp_type' => $account->otp_type,
            'icon' => $account->icon,
            'group_id' => null,
        ];
    }

    private function normalizeExternalAccounts(array $accounts): array
    {
        return array_map(function (array $accountData) {
            return [
                'service' => $accountData['service'] ?? 'Unknown',
                'account' => $accountData['account'] ?? '',
                'secret' => $accountData['secret'] ?? '',
                'encrypted' => (bool) ($accountData['encrypted'] ?? false),
                'algorithm' => $accountData['algorithm'] ?? 'sha1',
                'digits' => (int) ($accountData['digits'] ?? 6),
                'period' => (int) ($accountData['period'] ?? 30),
                'counter' => $accountData['counter'] ?? null,
                'otp_type' => $accountData['otp_type'] ?? 'totp',
                'icon' => $accountData['icon'] ?? null,
                'group_id' => null,
            ];
        }, $accounts);
    }

    private function resolveExternalMigrator(string $format): Migrator
    {
        return match ($this->normalizeImportFormat($format)) {
            '2fauth' => $this->twoFAuthMigrator,
            '2fas' => $this->twoFASMigrator,
            'aegis' => $this->aegisMigrator,
            'bitwarden' => $this->bitwardenMigrator,
            'googleauth' => $this->googleAuthMigrator,
            default => throw new \Exception('Unsupported backup format: ' . $format),
        };
    }

    public function validateImportPayload(?array $backupData, string $format = 'vault'): bool
    {
        if (!is_array($backupData)) {
            return false;
        }

        if ($this->isVaultFormat($format)) {
            return $this->validateBackupFile($backupData);
        }

        try {
            $prepared = $this->prepareExternalImport($backupData, $format);
        } catch (\Throwable $e) {
            return false;
        }

        return isset($prepared['accounts'])
            && is_array($prepared['accounts'])
            && count($prepared['accounts']) <= self::MAX_ACCOUNTS_PER_BACKUP;
    }

    public function detectImportFormat(array $backupData): string
    {
        if (isset($backupData['app']) && $backupData['app'] === '2FAuth') {
            return '2fauth';
        }

        if (isset($backupData['format']) && $backupData['format'] === '2FA-Vault') {
            return 'vault';
        }

        if (isset($backupData['schemaVersion']) && isset($backupData['services'])) {
            return '2fas';
        }

        if (isset($backupData['db']) && isset($backupData['db']['entries'])) {
            return 'aegis';
        }

        if (array_key_exists('encrypted', $backupData) && isset($backupData['items'])) {
            return 'bitwarden';
        }

        if (isset($backupData['payload'])
            && is_string($backupData['payload'])
            && str_starts_with($backupData['payload'], 'otpauth-migration://offline?data=')) {
            return 'googleauth';
        }

        return 'vault';
    }

    public function supportedImportFormats(): array
    {
        return ['2fauth', '2fas', 'vault', 'aegis', 'bitwarden', 'googleauth'];
    }

    public function backupFormatValidationRule(): string
    {
        return 'nullable|in:' . implode(',', $this->supportedImportFormats());
    }

    public function normalizeImportFormat(string $format): string
    {
        return strtolower($format);
    }

    public function isImportFormatSupported(string $format): bool
    {
        return in_array($this->normalizeImportFormat($format), $this->supportedImportFormats(), true);
    }

    public function passwordRequiredForFormat(string $format): bool
    {
        return $this->isVaultFormat($format);
    }

    public function importValidationErrorMessage(string $format): string
    {
        return $this->isVaultFormat($format)
            ? 'Invalid backup format or version not supported'
            : 'Invalid backup file for selected import format';
    }

    public function importValidationErrorDetail(string $format): string
    {
        return $this->isVaultFormat($format)
            ? 'The backup file format is invalid or from an unsupported version'
            : 'The backup file cannot be parsed with the selected format';
    }

    private function isVaultFormat(string $format): bool
    {
        return $this->normalizeImportFormat($format) === 'vault';
    }

    private function extractAccountsFromBackup(array $backupData): array
    {
        if (isset($backupData['accounts']) && is_array($backupData['accounts'])) {
            return $backupData['accounts'];
        }

        throw new \Exception('Invalid .vault file format: no accounts found');
    }

    private function extractGroupsFromBackup(array $backupData): array
    {
        if (isset($backupData['groups']) && is_array($backupData['groups'])) {
            return $backupData['groups'];
        }

        return [];
    }

    /**
     * Normalize native vault backup data from either envelope or payload form.
     */
    public function normalizeVaultBackupData(array $backupData): array
    {
        $isEnvelope = isset($backupData['app'], $backupData['data'])
            && $backupData['app'] === '2FA-Vault'
            && is_string($backupData['data']);

        if (!$isEnvelope) {
            return $this->normalizeVaultPayload($backupData);
        }

        $decodedData = base64_decode($backupData['data'], true);
        if ($decodedData === false) {
            throw new \Exception('Invalid .vault file format: data payload is not valid base64');
        }

        $payload = json_decode($decodedData, true);
        if (!is_array($payload)) {
            throw new \Exception('Invalid .vault file format: data payload is not valid JSON');
        }

        return $this->normalizeVaultPayload($payload, $backupData);
    }

    /**
     * Normalize canonical vault payload keys.
     */
    private function normalizeVaultPayload(array $payload, ?array $envelope = null): array
    {
        if (!array_key_exists('accounts', $payload)) {
            throw new \Exception('Invalid .vault file format: accounts not found');
        }

        $accounts = $payload['accounts'];

        if (!is_array($accounts)) {
            throw new \Exception('Invalid .vault file format: accounts must be an array');
        }

        $normalized = [
            'format' => $payload['format'] ?? ($envelope['app'] ?? '2FA-Vault'),
            'version' => $payload['version'] ?? ($envelope['version'] ?? null),
            'encrypted' => (bool) ($payload['encrypted'] ?? true),
            'double_encrypted' => (bool) ($payload['double_encrypted'] ?? $payload['doubleEncrypted'] ?? false),
            'encryption_version' => (int) ($payload['encryption_version'] ?? 0),
            'exported_at' => $payload['exported_at'] ?? $payload['exportedAt'] ?? ($envelope['datetime'] ?? null),
            'account_count' => isset($payload['account_count'])
                ? (int) $payload['account_count']
                : count($accounts),
            'user' => $payload['user'] ?? null,
            'accounts' => $accounts,
        ];

        if (isset($payload['groups']) && is_array($payload['groups'])) {
            $normalized['groups'] = $payload['groups'];
        }

        return $normalized;
    }

    /**
     * Validate backup file structure (vault only).
     */
    public function validateBackupFile(?array $backupData): bool
    {
        if (!is_array($backupData)) {
            return false;
        }

        try {
            $normalized = $this->normalizeVaultBackupData($backupData);
        } catch (\Throwable $e) {
            return false;
        }

        foreach (['version', 'accounts'] as $field) {
            if (!isset($normalized[$field])) {
                return false;
            }
        }

        if (!$this->isVersionCompatible($normalized['version'])) {
            Log::warning('Backup version not compatible', [
                'backup_version' => $normalized['version'],
                'min_supported' => self::MIN_SUPPORTED_VERSION,
                'current' => self::CURRENT_FORMAT_VERSION,
            ]);
            return false;
        }

        if (!is_array($normalized['accounts'])) {
            return false;
        }

        if (count($normalized['accounts']) > self::MAX_ACCOUNTS_PER_BACKUP) {
            Log::warning('Backup has too many accounts', [
                'count' => count($normalized['accounts']),
                'max' => self::MAX_ACCOUNTS_PER_BACKUP,
            ]);
            return false;
        }

        return true;
    }

    private function isVersionCompatible(string $version): bool
    {
        return version_compare($version, self::MIN_SUPPORTED_VERSION, '>=');
    }

    /**
     * Get backup metadata without decrypting.
     */
    public function getBackupMetadata(array $backupData): array
    {
        $normalized = $this->normalizeVaultBackupData($backupData);
        $accounts = $normalized['accounts'] ?? [];
        $groups = $normalized['groups'] ?? [];

        return [
            'format' => $normalized['format'] ?? 'unknown',
            'version' => $normalized['version'] ?? 'unknown',
            'encrypted' => $normalized['encrypted'] ?? false,
            'double_encrypted' => $normalized['double_encrypted'] ?? false,
            'encryption_version' => $normalized['encryption_version'] ?? 0,
            'exported_at' => $normalized['exported_at'] ?? null,
            'account_count' => count($accounts),
            'group_count' => count($groups),
            'user' => $normalized['user'] ?? null,
            'compatible' => $this->isVersionCompatible($normalized['version'] ?? '0'),
            'has_encrypted_accounts' => $this->hasEncryptedAccounts($accounts),
        ];
    }

    private function hasEncryptedAccounts(array $accounts): bool
    {
        foreach ($accounts as $account) {
            if (isset($account['encrypted']) && $account['encrypted']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get backup statistics for a user.
     */
    public function getBackupStats(User $user): array
    {
        $totalAccounts = TwoFAccount::where('user_id', $user->id)->count();
        $encryptedAccounts = TwoFAccount::where('user_id', $user->id)
            ->where('encrypted', true)
            ->count();
        $totalGroups = $user->groups()->count();

        $backupSize = $this->estimateBackupSize($totalAccounts, $totalGroups);

        return [
            'total_accounts' => $totalAccounts,
            'encrypted_accounts' => $encryptedAccounts,
            'unencrypted_accounts' => $totalAccounts - $encryptedAccounts,
            'total_groups' => $totalGroups,
            'estimated_size_bytes' => $backupSize,
            'estimated_size_human' => $this->formatBytes($backupSize),
            'has_backup' => !is_null($user->last_backup_at),
            'last_backup_at' => $user->last_backup_at?->toIso8601String(),
            'should_backup' => is_null($user->last_backup_at) || $user->last_backup_at->diffInDays(now()) > 30,
        ];
    }

    private function estimateBackupSize(int $accountCount, int $groupCount): int
    {
        return ($accountCount * 500) + ($groupCount * 100) + 1024;
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
