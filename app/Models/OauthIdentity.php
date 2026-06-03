<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property string|null $name
 * @property string|null $avatar
 * @property array|null $raw
 * @property Carbon $updated_at
 * @property Carbon $created_at
 * @property-read User $user
 */
class OauthIdentity extends Model
{
    use HasFactory;

    public const PROVIDER_CASDOOR = 'casdoor';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'name',
        'avatar',
        'raw',
    ];

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'raw' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
