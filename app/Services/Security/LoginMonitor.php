<?php

namespace App\Services\Security;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records every login attempt and flags suspicious ones (spec ch 26).
 * A successful login is suspicious when it comes from a new device and the account already
 * used `deviceLimit` other devices in the last 30 days.
 */
class LoginMonitor
{
    public const DEVICE_WINDOW_DAYS = 30;

    public function __construct(private int $deviceLimit = 3) {}

    public function recordSuccess(User $user, Request $request): LoginEvent
    {
        $device = $this->deviceHash($request);
        $knownDevices = LoginEvent::query()
            ->where('user_id', $user->id)
            ->where('outcome', 'success')
            ->where('created_at', '>=', now()->subDays(self::DEVICE_WINDOW_DAYS))
            ->whereNotNull('device_hash')
            ->distinct()
            ->pluck('device_hash');

        $isNewDevice = ! $knownDevices->contains($device);
        $overLimit = $isNewDevice && $knownDevices->count() >= $this->deviceLimit;

        return LoginEvent::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'device_hash' => $device,
            'outcome' => 'success',
            'is_suspicious' => $overLimit,
            'reason' => $overLimit ? 'device_limit_exceeded' : null,
            'created_at' => now(),
        ]);
    }

    public function recordFailure(?User $user, string $email, Request $request): LoginEvent
    {
        return LoginEvent::create([
            'user_id' => $user?->id,
            'email' => $email,
            'ip' => $request->ip(),
            'device_hash' => $this->deviceHash($request),
            'outcome' => 'failed',
            'is_suspicious' => false,
            'created_at' => now(),
        ]);
    }

    private function deviceHash(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }
}
