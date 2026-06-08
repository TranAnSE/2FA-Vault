<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vault;
use Illuminate\Support\Facades\Log;

class VaultService
{
    const MAX_VAULTS = 10;

    public function createVault(User $user, string $name): Vault
    {
        $count = $user->vaults()->count();
        if ($count >= self::MAX_VAULTS) {
            throw new \OverflowException("Maximum of " . self::MAX_VAULTS . " vaults allowed.");
        }

        $vault = Vault::create([
            'user_id'    => $user->id,
            'name'       => $name,
            'is_default' => $count === 0,
        ]);

        Log::info('Vault created', ['vault_id' => $vault->id, 'user_id' => $user->id]);

        return $vault;
    }

    public function renameVault(Vault $vault, string $name): Vault
    {
        $vault->update(['name' => $name]);
        return $vault;
    }

    public function deleteVault(Vault $vault): void
    {
        if ($vault->is_default) {
            throw new \RuntimeException('Cannot delete the default vault.');
        }

        Log::info('Vault deleted', ['vault_id' => $vault->id]);
        $vault->delete();
    }

    public function setupEncryption(Vault $vault, string $salt, string $testValue): void
    {
        $vault->update([
            'encryption_salt'       => $salt,
            'encryption_test_value' => $testValue,
            'encryption_version'    => 1,
            'is_locked'             => true,
        ]);
    }

    public function unlock(Vault $vault): void
    {
        $vault->update(['is_locked' => false, 'last_opened_at' => now()]);
    }

    public function lock(Vault $vault): void
    {
        $vault->update(['is_locked' => true]);
    }
}
