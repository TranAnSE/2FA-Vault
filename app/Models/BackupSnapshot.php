<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A server-side backup snapshot: an APP_KEY-encrypted row-level dump of a
 * user's accounts and groups as stored (E2EE secrets remain ciphertext).
 * See BackupSnapshotService for the payload format and quota semantics.
 *
 * @property int $id
 * @property int $user_id
 * @property string $source manual|pre_restore|scheduled
 * @property string|null $label
 * @property int $accounts_count
 * @property int $groups_count
 * @property int $size_bytes
 * @property string $checksum
 * @property string|null $encryption_salt_at_snapshot
 * @property int|null $encryption_version_at_snapshot
 * @property string $app_key_fingerprint
 * @property string $file_path
 */
class BackupSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'source',
        'label',
        'accounts_count',
        'groups_count',
        'size_bytes',
        'checksum',
        'encryption_salt_at_snapshot',
        'encryption_version_at_snapshot',
        'app_key_fingerprint',
        'file_path',
    ];

    protected $casts = [
        'accounts_count' => 'integer',
        'groups_count' => 'integer',
        'size_bytes' => 'integer',
        'encryption_version_at_snapshot' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
