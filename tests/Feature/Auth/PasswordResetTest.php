<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\GuestPasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', 'If you have an account with this email address, look for a password reset link in your inbox. Check your spam folder if you don’t see it.');

        Notification::assertSentTo($user, GuestPasswordResetNotification::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, GuestPasswordResetNotification::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_unknown_email_receives_the_same_recovery_confirmation(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => 'unknown@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'If you have an account with this email address, look for a password reset link in your inbox. Check your spam folder if you don’t see it.');
        Notification::assertNothingSent();
    }

    public function test_expired_email_link_returns_guest_to_the_request_form(): void
    {
        $user = User::factory()->create();
        $token = \Illuminate\Support\Facades\Password::createToken($user);
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, GuestPasswordResetNotification::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_lightweight_account_password_reset_lands_on_profile(): void
    {
        Notification::fake();

        $user = User::factory()->lightweight()->create([
            'email' => 'guest@example.com',
            'phone' => null,
        ]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, GuestPasswordResetNotification::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('profile.edit'))
                ->assertSessionHas('success', 'Add your name and mobile number so the resort can reach you.');

            $this->assertAuthenticatedAs($user->fresh());
            $this->assertFalse($user->fresh()->isLightweight());

            return true;
        });
    }

    public function test_reset_password_screen_prefills_email_from_query_string(): void
    {
        $user = User::factory()->create(['email' => 'guest@example.com']);
        $token = \Illuminate\Support\Facades\Password::createToken($user);
        $this->get('/reset-password/'.$token.'?email=guest@example.com')
            ->assertOk()
            ->assertSee('value="guest@example.com"', false);
    }

    public function test_local_log_mailer_shows_a_usable_reset_link(): void
    {
        config(['auth.passwords.users.reveal_link' => true]);
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email]);

        $response->assertRedirect('/forgot-password')
            ->assertSessionHas('password_reset_url')
            ->assertSessionHas('status', 'If you have an account with this email address, look for a password reset link in your inbox. Check your spam folder if you don’t see it.');

        Queue::assertPushed(SendQueuedNotifications::class, 1);

        $this->get(session('password_reset_url'))
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('value="'.$user->email.'"', false);
    }

    public function test_database_worker_sends_reset_mail_without_losing_local_preview_link(): void
    {
        config([
            'auth.passwords.users.reveal_link' => true,
            'queue.default' => 'database',
        ]);

        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('password_reset_url');

        $this->assertDatabaseCount('jobs', 1);
        $sent = [];
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
            $sent[] = $event;
        });
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();

        $this->assertCount(1, $sent);
        $this->assertSame('mail', $sent[0]->channel);
        $this->get(session('password_reset_url'))->assertOk();
    }
}
