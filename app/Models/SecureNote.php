<?php

namespace App\Models;

use App\Enums\ContentType;
use App\Models\Traits\CanEncryptField;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecureNote extends Model
{
    use CanEncryptField;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'content',
        'content_type',
        'is_pinned',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'content_type' => ContentType::class,
    ];

    protected $appends = ['title_decrypted', 'content_decrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getTitleAttribute(mixed $value): mixed
    {
        return $this->decryptOrReturn($value);
    }

    public function setTitleAttribute(mixed $value): void
    {
        $this->attributes['title'] = $this->encryptOrReturn($value);
    }

    public function getContentAttribute(mixed $value): mixed
    {
        return $this->decryptOrReturn($value);
    }

    public function setContentAttribute(mixed $value): void
    {
        $this->attributes['content'] = $this->encryptOrReturn($value);
    }
}
