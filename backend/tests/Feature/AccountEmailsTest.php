<?php

namespace Tests\Feature;

use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Mdm\Notifications\AccountInvite;
use App\Mdm\Notifications\ForgotPassword;
use App\Mdm\Notifications\SetPasswordLink;
use App\Mdm\Role;
use App\Mdm\Services\AccountService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/** Invite, set-password and "forgot password" emails. The test mailer is "array"; nothing reaches a mail server. */
class AccountEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function tokenOf(string $link): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return $query['token'] ?? basename(parse_url($link, PHP_URL_PATH));
    }

    public function test_an_invite_emails_a_working_set_password_link(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin, 'name' => 'Daniel Admin']));

        Livewire::test(ManageUsers::class)
            ->callAction('invite', ['name' => 'Grace Achieng', 'email' => 'grace@noahport.test', 'role_id' => Role::Coordinator->model()->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Set-password link emailed to grace@noahport.test');

        $grace = User::where('email', 'grace@noahport.test')->sole();
        Notification::assertSentTo($grace, AccountInvite::class, function (AccountInvite $mail) use ($grace) {
            $html = (string) $mail->toMail($grace)->render();

            return Password::broker()->tokenExists($grace, $this->tokenOf($mail->link))
                && str_contains($html, 'Daniel Admin has made you a Shift coordinator account')
                && str_contains($html, e($mail->link))
                && str_contains($html, '48 hours');
        });
    }

    public function test_a_new_set_password_link_is_emailed(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));
        $mary = User::factory()->create(['role' => Role::Manager]);

        $link = app(AccountService::class)->resetPassword($mary);

        Notification::assertSentTo($mary, SetPasswordLink::class, fn (SetPasswordLink $mail) => $mail->link === $link);
    }

    public function test_disabled_accounts_get_no_email(): void
    {
        $mary = User::factory()->create(['role' => Role::Manager, 'disabled_at' => now()]);

        app(AccountService::class)->resetPassword($mary);

        Notification::assertNotSentTo($mary, SetPasswordLink::class);
    }

    public function test_without_a_mail_server_links_are_only_shown_to_the_admin(): void
    {
        config(['mail.default' => 'log']);
        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]));

        Livewire::test(ManageUsers::class)
            ->callAction('invite', ['name' => 'Grace Achieng', 'email' => 'grace@noahport.test', 'role_id' => Role::Coordinator->model()->id])
            ->assertNotified('Set-password link for Grace Achieng');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_emails_a_reset_link_in_our_wording(): void
    {
        $grace = User::factory()->create(['role' => Role::Coordinator, 'email' => 'grace@noahport.test']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'grace@noahport.test'])
            ->call('request');

        Notification::assertSentTo($grace, ForgotPassword::class, function (ForgotPassword $mail) use ($grace) {
            $html = (string) $mail->toMail($grace)->render();

            return str_contains($html, 'Someone asked to reset the password')
                && Password::broker()->tokenExists($grace, $mail->token);
        });
    }

    public function test_forgot_password_sends_nothing_to_a_disabled_account(): void
    {
        $grace = User::factory()->create(['role' => Role::Coordinator, 'email' => 'grace@noahport.test', 'disabled_at' => now()]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'grace@noahport.test'])
            ->call('request');

        Notification::assertNothingSentTo($grace);
    }

    public function test_the_mail_test_command_sends_one_email(): void
    {
        $this->artisan('mdm:mail-test', ['email' => 'it@noahport.test'])->assertSuccessful();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('it@noahport.test', $sent->first()->getEnvelope()->getRecipients()[0]->getAddress());
    }
}
