<?php

declare(strict_types=1);

namespace App\Models;

use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The browser session the S-Etukortti sync replays.
 *
 * Only the newest row is ever used; a new /sgroup simply adds one. The two
 * secrets are a working login to the S-ryhmä account, so they are encrypted
 * with APP_KEY and hidden from serialisation.
 *
 * @property string $session_id
 * @property string $xsrf_token
 * @property int|null $telegraph_chat_id
 */
class SGroupSession extends Model
{
    protected $table = 'sgroup_sessions';

    protected $fillable = ['session_id', 'xsrf_token', 'telegraph_chat_id', 'last_ok_at', 'expired_at'];

    protected $hidden = ['session_id', 'xsrf_token'];

    protected function casts(): array
    {
        return [
            'session_id' => 'encrypted',
            'xsrf_token' => 'encrypted',
            'last_ok_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /** The session to use, or null when none was sent or the newest one has expired. */
    public static function active(): ?self
    {
        $latest = static::latest('id')->first();

        return $latest && $latest->expired_at === null ? $latest : null;
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegraphChat::class, 'telegraph_chat_id');
    }
}
