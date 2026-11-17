<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Passkeys\Passkey;

/**
 * Per-credential PRF keyset (plan 261114-2101, D6).
 *
 * One row per enrolled passkey. The three stored members are opaque wrapped
 * blobs produced client-side (zero knowledge — the server never sees the PRF
 * output, the vault key, or a plaintext private key):
 *
 * - public_key:          SPKI base64 of the credential's RSA-OAEP wrapping keypair
 * - wrapped_private_key: AES-256-GCM envelope of the PKCS#8 private key, wrapped by HKDF(PRF output)
 * - wrapped_vault_key:   RSA-OAEP encapsulation of the vault key under public_key
 *
 * @property int $id
 * @property int $user_id
 * @property int $passkey_id
 * @property string $public_key
 * @property string $wrapped_private_key
 * @property string $wrapped_vault_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Passkey $passkey
 */
class PasskeyPrfCredential extends Model
{
    protected $fillable = [
        'user_id',
        'passkey_id',
        'public_key',
        'wrapped_private_key',
        'wrapped_vault_key',
    ];

    /** @return BelongsTo<User, $this> */
    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One-way relation to the package-owned Passkey model (never modified).
     *
     * @return BelongsTo<Passkey, $this>
     */
    public function passkey() : BelongsTo
    {
        return $this->belongsTo(Passkey::class);
    }
}
