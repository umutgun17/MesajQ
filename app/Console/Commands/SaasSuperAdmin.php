<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Süper admin (tenant_id NULL) oluştur/parola sıfırla. */
class SaasSuperAdmin extends Command
{
    protected $signature = 'saas:super-admin {email} {--name=Yönetici} {--password=}';

    protected $description = 'Süper admin hesabı oluşturur ya da parolasını günceller';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Parola (min 10, harf+rakam)');
        if (strlen($password) < 10 || ! preg_match('/[a-zA-Z]/', $password) || ! preg_match('/\d/', $password)) {
            $this->error('Parola en az 10 karakter, harf ve rakam içermeli.');

            return self::FAILURE;
        }
        $user = User::whereNull('tenant_id')->where('email', $this->argument('email'))->first() ?? new User(['email' => $this->argument('email')]);
        $user->name = $this->option('name');
        $user->email = $this->argument('email');
        $user->password = $password;
        $user->role = 'admin';
        $user->status = 'active';
        $user->email_verified_at = now();
        $user->tenant_id = null;
        $user->save();
        $this->info("Süper admin hazır: {$user->email} (id {$user->id})");

        return self::SUCCESS;
    }
}
