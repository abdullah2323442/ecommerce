<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountType;
use App\Enums\Section;
use App\Services\Security\TemporaryPowers;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
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
        'area',
        'business_name',
        'logo_path',
        'photo_path',
        'business_names',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
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
            'suspended_at' => 'datetime',
            'suspended_until' => 'datetime',
            'show_monthly_total' => 'boolean',
            'free_delivery_over' => 'decimal:2',
            'business_names' => 'array',
            'discount_limit' => 'decimal:2',
            'categories' => 'array',
            'moq' => 'decimal:2',
            'pay_cod' => 'boolean',
            'pay_advance' => 'boolean',
            'habit_reminders' => 'boolean',
            'buy_again' => 'boolean',
            'show_shop_name' => 'boolean',
            'seller_paused' => 'boolean',
            'seller_suspended_at' => 'datetime',
            'penalty_suspended_at' => 'datetime',
            'payout_withheld_at' => 'datetime',
            'seller_trusted_at' => 'datetime',
            'habit_reminded_on' => 'date',
            'account_type' => AccountType::class,
            'password' => 'hashed',
            'has_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class);
    }

    /** What this person bought: the invoices made for them. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'customer_id');
    }

    /** What this account lists in the market (spec ch 14). */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'seller_id');
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(CreditEntry::class);
    }

    /** Selling switched off because a commission statement went unanswered (spec ch 14). The rest of the account is untouched. */
    public function sellingStopped(): bool
    {
        return $this->seller_suspended_at !== null || $this->penalty_suspended_at !== null;
    }

    /** Stopped for an unanswered statement (it comes back when answered), as against stopped as a penalty (it comes back when we say so). */
    public function statementStopped(): bool
    {
        return $this->seller_suspended_at !== null;
    }

    /** Suspended now? A suspension for a set period lifts itself when the time is up (spec ch 26). */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null && ($this->suspended_until === null || $this->suspended_until->isFuture());
    }

    /**
     * May this buyer see a category (spec ch 14)? A trade buyer sees only what they were given; a shop buyer sees
     * medicine, which is all a shop buys today. Nobody learns that the other categories exist.
     */
    public function hasCategory(string $category): bool
    {
        if ($this->account_type === AccountType::Wholesaler) {
            return in_array($category, (array) $this->categories, true);
        }

        return $category === 'medicine';
    }

    /** The companies an MPO may work with (spec ch 10: one MPO, many companies). */
    public function mpoCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'mpo_companies');
    }

    /** The products an MPO chose to work with (one MPO, many products). */
    public function mpoProducts(): BelongsToMany
    {
        return $this->belongsToMany(Medicine::class, 'mpo_products');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->account_type === AccountType::Admin && ! $this->isSuspended();
    }

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    /**
     * What this person may do in the admin panel. The super admin may do everything. A staff account gets its role's
     * permissions, or the counter-staff set when it has no role yet. Everyone else gets none.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        if ($this->isSuspended()) {
            return [];
        }

        if ($this->account_type === AccountType::Admin) {
            return array_keys((array) config('permissions.catalog'));
        }

        if ($this->account_type !== AccountType::Staff) {
            return [];
        }

        // Their role, and anything the owner has lent them for a while (spec ch 25).
        return $this->permissionCache ??= array_values(array_unique(array_merge(
            (array) ($this->role?->permissions ?? config('permissions.defaults.Counter staff')),
            app(TemporaryPowers::class)->activeFor($this),
        )));
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Users who may do one thing, for notifications: the super admins and the staff whose role allows it. */
    public static function withPermission(string $permission)
    {
        return static::query()->whereNull('suspended_at')->whereIn('account_type', [AccountType::Admin->value, AccountType::Staff->value])->get()
            ->filter(fn (User $u) => $u->hasPermission($permission))->values();
    }

    public function canSee(Section $section): bool
    {
        return ! $this->isSuspended() && $this->account_type->canSee($section);
    }
}
