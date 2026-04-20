<?php

namespace App\Models;

use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'shipping_country',
        'shipping_region',
        'shipping_city',
        'shipping_street',
        'shipping_house',
        'shipping_postcode',
        'is_company',
        'company_name',
        'inn',
        'kpp',
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
            'is_company' => 'bool',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isFilamentAdmin();
    }

    public function isFilamentAdmin(): bool
    {
        return self::isFilamentAdminEmail((string) $this->email);
    }

    public function canUseStorefront(): bool
    {
        return ! $this->isFilamentAdmin();
    }

    public function favoriteProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'favorite_products')->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public static function isFilamentAdminEmail(string $email): bool
    {
        return in_array(Str::lower(trim($email)), self::allowedPanelEmails(), true);
    }

    /**
     * @return list<string>
     */
    public static function filamentAdminEmails(): array
    {
        return self::allowedPanelEmails();
    }

    #[Scope]
    protected function storefrontCustomers(Builder $query): void
    {
        $emails = self::filamentAdminEmails();

        if ($emails !== []) {
            $query->whereNotIn('email', $emails);
        }
    }

    /**
     * @return list<string>
     */
    protected static function allowedPanelEmails(): array
    {
        $configuredLists = [
            config('settings.general.filament_admin_emails', []),
            config('settings.general.manager_emails', []),
        ];

        foreach ($configuredLists as $emails) {
            $normalized = self::normalizeAllowedEmails($emails);

            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    protected static function normalizeAllowedEmails(mixed $emails): array
    {
        if (! is_array($emails)) {
            return [];
        }

        return collect($emails)
            ->filter(fn ($email) => filled($email))
            ->map(fn ($email) => Str::lower(trim((string) $email)))
            ->unique()
            ->values()
            ->all();
    }
}
