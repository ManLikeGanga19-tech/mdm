<?php

namespace App\Mdm\Services;

use App\Mdm\Models\AdminRole;
use App\Mdm\Models\AuditLog;
use App\Mdm\Notifications\AccountEmail;
use App\Mdm\Notifications\AccountInvite;
use App\Mdm\Notifications\SetPasswordLink;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Admin-site accounts: invite, set-password links, disable/enable, role changes. The one hard rule: there is always
 * at least one active super admin, so nobody can lock the company out of its own MDM. Every change is audited.
 * Set-password links use Laravel's password broker and Filament's reset page; they last 48 hours (config/auth.php).
 * Once the site has a mail server the links are also emailed to the person (AccountEmail::canSend()).
 */
class AccountService
{
    /**
     * Creates the account with no usable password, emails the person a set-password link and returns it too.
     *
     * @return array{user: User, link: string}
     */
    public function invite(string $name, string $email, AdminRole $role): array
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::lower($email),
            'password' => Str::random(64), // never told to anyone: the person sets their own through the link
            'role' => $role,
            'invited_at' => now(),
        ]);
        AuditLog::record('account.invited', $user, ['email' => $user->email, 'role' => $role->name]);
        $link = $this->setPasswordLink($user);
        if (AccountEmail::canSend()) {
            $user->notify(new AccountInvite($link, auth()->user()?->name));
        }

        return ['user' => $user, 'link' => $link];
    }

    /** A one-time link to choose a password (48 h). Making a new one cancels the previous one. */
    public function setPasswordLink(User $user): string
    {
        $token = Password::broker()->createToken($user);

        return Filament::getPanel('admin')->getResetPasswordUrl($token, $user);
    }

    /** Replaces the password with an unknown one (signing the person out); emails and returns a link to choose a new one. */
    public function resetPassword(User $user): string
    {
        $user->forceFill(['password' => Str::random(64), 'remember_token' => Str::random(60)])->save();
        AuditLog::record('account.password_reset', $user, ['email' => $user->email]);
        $link = $this->setPasswordLink($user);
        if (AccountEmail::canSend()) {
            $user->notify(new SetPasswordLink($link));
        }

        return $link;
    }

    public function disable(User $user): void
    {
        $this->guardLastSuperAdmin($user, 'disable');
        $user->forceFill(['disabled_at' => now(), 'remember_token' => Str::random(60)])->save();
        AuditLog::record('account.disabled', $user, ['email' => $user->email]);
    }

    public function enable(User $user): void
    {
        $user->forceFill(['disabled_at' => null])->save();
        AuditLog::record('account.enabled', $user, ['email' => $user->email]);
    }

    public function changeRole(User $user, AdminRole $role): void
    {
        if ($user->role_id === $role->id) {
            return;
        }
        if (! $role->isSuperAdmin()) {
            $this->guardLastSuperAdmin($user, 'change the role of');
        }
        $from = $user->role?->name;
        $user->update(['role' => $role]);
        AuditLog::record('account.role_changed', $user, ['email' => $user->email, 'from' => $from, 'to' => $role->name]);
    }

    /** Whether $user is the only active super admin (then they can't be disabled, demoted or deleted). */
    public function isLastSuperAdmin(User $user): bool
    {
        if (! $user->isSuperAdmin() || $user->isDisabled()) {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($user->getKey())
            ->whereNull('disabled_at')
            ->whereHas('role', fn ($q) => $q->where('key', 'super_admin'))
            ->exists();
    }

    public function delete(User $user): void
    {
        $this->guardLastSuperAdmin($user, 'delete');
        DB::transaction(function () use ($user) {
            AuditLog::record('account.deleted', $user, ['email' => $user->email, 'role' => $user->role?->name]);
            $user->delete();
        });
    }

    private function guardLastSuperAdmin(User $user, string $what): void
    {
        if ($this->isLastSuperAdmin($user)) {
            throw new RuntimeException("You can't {$what} the last active super admin. Make someone else super admin first.");
        }
    }
}
