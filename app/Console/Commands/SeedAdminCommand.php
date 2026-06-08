<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('webhook-relay:seed-admin {--email=} {--name=Admin} {--password=}')]
#[Description('Create or update the Filament admin user. If --password is omitted a random one is generated and printed.')]
class SeedAdminCommand extends Command
{
    public function handle(): int
    {
        $email = $this->option('email') ?: 'admin@example.com';
        $name = $this->option('name') ?: 'Admin';
        $password = $this->option('password') ?: Str::random(20);

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->newLine();
        $this->info($user->wasRecentlyCreated ? 'Admin user created.' : 'Admin user updated.');
        $this->line("  id:       {$user->id}");
        $this->line("  email:    {$user->email}");
        $this->line("  name:     {$user->name}");

        if (! $this->option('password')) {
            $this->newLine();
            $this->comment('Generated password (record this now, only shown once):');
            $this->line("  <fg=cyan>{$password}</>");
        }
        $this->newLine();
        $this->comment('Panel URL: '.config('app.url').'/admin');
        $this->newLine();

        return self::SUCCESS;
    }
}
