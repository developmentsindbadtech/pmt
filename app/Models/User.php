<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function boards(): BelongsToMany
    {
        return $this->belongsToMany(Board::class)->withTimestamps();
    }

    /**
     * Get profile picture URL from Microsoft Graph
     */
    public function getProfilePictureUrlAttribute(): ?string
    {
        if (!config('services.microsoft.client_id') || !config('services.microsoft.client_secret')) {
            return null;
        }

        try {
            $graphService = app(\App\Services\MicrosoftGraphMailService::class);
            return $graphService->getUserPhotoUrl($this->email);
        } catch (\Exception $e) {
            return null;
        }
    }
}
