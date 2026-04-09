<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

class E2eSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->e2eAdmin()->create();
        $user = User::factory()->e2eUser()->create();
        $encryptedUser = User::factory()->e2eEncrypted()->create();
        User::factory()->e2eLockedEncrypted()->create();
        $conflictUser = User::factory()->e2eConflictUser()->create();
        $backupUser = User::factory()->e2eBackupUser()->create();

        // Pre-populate accounts/groups for admin CRUD and group tests
        $adminPrimaryGroup = Group::factory()->create([
            'name' => 'E2E Test Group',
            'user_id' => $admin->id,
        ]);
        $adminSecondaryGroup = Group::factory()->create([
            'name' => 'E2E Shared Group',
            'user_id' => $admin->id,
        ]);

        TwoFAccount::factory()->forUser($admin)->inGroup($adminPrimaryGroup)->create([
            'otp_type' => 'totp',
            'account' => 'admin@test.com',
            'service' => 'GitHub',
            'secret' => 'A4GRFTVVRBGY7UIW',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
            'legacy_uri' => 'otpauth://totp/GitHub:admin@test.com?secret=A4GRFTVVRBGY7UIW&issuer=GitHub',
        ]);

        TwoFAccount::factory()->forUser($admin)->create([
            'otp_type' => 'totp',
            'account' => 'user@example.com',
            'service' => 'Google',
            'secret' => 'JBSWY3DPEHPK3PXP',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
            'legacy_uri' => 'otpauth://totp/Google:user@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Google',
        ]);

        TwoFAccount::factory()->forUser($admin)->inGroup($adminSecondaryGroup)->create([
            'otp_type' => 'totp',
            'account' => 'support@example.com',
            'service' => 'Slack',
            'secret' => 'KRSXG5DSNFXGOIDM',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
            'legacy_uri' => 'otpauth://totp/Slack:support@example.com?secret=KRSXG5DSNFXGOIDM&issuer=Slack',
        ]);

        // Encrypted user: both grouped and ungrouped encrypted accounts
        $encryptedGroup = Group::factory()->create([
            'name' => 'E2E Encrypted Group',
            'user_id' => $encryptedUser->id,
        ]);

        TwoFAccount::factory()->forUser($encryptedUser)->encrypted(json_encode([
            'ciphertext' => base64_encode(random_bytes(32)),
            'iv' => base64_encode(random_bytes(12)),
            'authTag' => base64_encode(random_bytes(16)),
        ]))->inGroup($encryptedGroup)->create([
            'service' => 'VaultDrive',
            'account' => 'secure@vault.test',
            'otp_type' => 'totp',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
        ]);

        TwoFAccount::factory()->forUser($encryptedUser)->encrypted()->create([
            'service' => 'Banking',
            'account' => 'banking@vault.test',
            'otp_type' => 'totp',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
        ]);

        // Conflict user: duplicate key pairs for backup conflict-resolution scenarios
        TwoFAccount::factory()->forUser($conflictUser)->duplicateOf('ConflictService', 'duplicate@vault.test')->create([
            'secret' => 'JBSWY3DPEHPK3PXP',
            'otp_type' => 'totp',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
        ]);

        TwoFAccount::factory()->forUser($conflictUser)->duplicateOf('ConflictService', 'duplicate@vault.test')->create([
            'secret' => 'KRSXG5DSNFXGOIDM',
            'otp_type' => 'totp',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
        ]);

        // Backup user: richer mix of encrypted/unencrypted and grouped accounts for export/import metadata
        $backupGroup = Group::factory()->create([
            'name' => 'E2E Backup Group',
            'user_id' => $backupUser->id,
        ]);

        TwoFAccount::factory()->forUser($backupUser)->inGroup($backupGroup)->create([
            'service' => 'Recovery',
            'account' => 'recovery@vault.test',
            'secret' => 'A4GRFTVVRBGY7UIW',
            'otp_type' => 'totp',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
        ]);

        TwoFAccount::factory()->forUser($backupUser)->encrypted()->create([
            'service' => 'EncryptedBackup',
            'account' => 'encrypted-backup@vault.test',
            'otp_type' => 'totp',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
        ]);
    }
}
