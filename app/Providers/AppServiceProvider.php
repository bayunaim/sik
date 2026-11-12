<?php

namespace App\Providers;

use App\Contracts\SiaProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // FR-153: provider SIA dipilih lewat config/sia.php
        $this->app->bind(SiaProvider::class, function ($app) {
            $kelas = config('sia.providers.'.config('sia.provider'));
            if (! $kelas) {
                throw new \InvalidArgumentException('SIA_PROVIDER tidak dikenal: '.config('sia.provider'));
            }

            return $app->make($kelas);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // FR-152: pembatasan panggilan API per token (atau per IP bila belum terautentikasi)
        RateLimiter::for('sia-api', fn (Request $r) => Limit::perMinute(config('sia.api_per_menit'))
            ->by('sia:'.(data_get($r->user()?->currentAccessToken(), 'id') ?? $r->user()?->id ?? $r->ip())));

        Gate::before(fn ($user, $ability) => $user->hasRole('SuperAdmin') ? true : null);
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]);
            $menit = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Atur Ulang Password - '.config('app.name'))
                ->greeting('Halo, '.$notifiable->name.'!')
                ->line('Kami menerima permintaan untuk mengatur ulang password akun Anda.')
                ->action('Atur Ulang Password', $url)
                ->line("Tautan ini berlaku {$menit} menit dan hanya bisa dipakai sekali.")
                ->line('Jika Anda tidak meminta ini, abaikan email ini.')
                ->salutation('Salam, Bagian Keuangan');
        });
    }
}
