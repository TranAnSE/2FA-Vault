<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * EncryptionServiceNotesTest test class
 *
 * Verifies that the twofaccounts.notes column is re-encrypted together with the
 * other sensitive columns when the useEncryption setting is toggled.
 */
#[CoversClass(\App\Services\SettingService::class)]
class EncryptionServiceNotesTest extends FeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_notes_column_is_encrypted_when_encryption_enabled()
    {
        $user = User::factory()->create();
        $plaintext = 'these are my secret recovery notes';

        /** @var \App\Models\TwoFAccount $account */
        $account = TwoFAccount::factory()->forUser($user)->create();
        // Write notes directly to bypass the model's encrypting mutator (encryption off here).
        DB::table('twofaccounts')->where('id', $account->id)->update(['notes' => $plaintext]);

        // Sanity: notes are plaintext before encryption is enabled.
        $this->assertSame($plaintext, DB::table('twofaccounts')->where('id', $account->id)->value('notes'));

        Settings::set('useEncryption', true);

        $storedNotes = DB::table('twofaccounts')->where('id', $account->id)->value('notes');

        // The stored value is no longer the plaintext and round-trips through Crypt.
        $this->assertNotSame($plaintext, $storedNotes);
        $this->assertSame($plaintext, Crypt::decryptString($storedNotes));
    }

    #[Test]
    public function test_notes_column_is_decrypted_when_encryption_disabled()
    {
        $user = User::factory()->create();
        $plaintext = 'secret notes that must round-trip back to plaintext';

        $account = TwoFAccount::factory()->forUser($user)->create();
        DB::table('twofaccounts')->where('id', $account->id)->update(['notes' => $plaintext]);

        Settings::set('useEncryption', true);
        Settings::set('useEncryption', false);

        $this->assertSame($plaintext, DB::table('twofaccounts')->where('id', $account->id)->value('notes'));
    }

    #[Test]
    public function test_null_notes_are_left_null_during_toggle()
    {
        $user = User::factory()->create();
        $account = TwoFAccount::factory()->forUser($user)->create();
        // notes default to null (migration: ->nullable())

        Settings::set('useEncryption', true);
        Settings::set('useEncryption', false);

        $this->assertNull(DB::table('twofaccounts')->where('id', $account->id)->value('notes'));
    }
}
