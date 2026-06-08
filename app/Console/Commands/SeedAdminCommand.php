<?php

namespace App\Console\Commands;

use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('inkwell:seed-admin {--email=} {--name=Admin} {--password=} {--workspace=Default Workspace}')]
#[Description('Create or update the Filament admin user and a default workspace.')]
class SeedAdminCommand extends Command
{
    public function handle(): int
    {
        $email = $this->option('email') ?: 'admin@example.com';
        $name = $this->option('name') ?: 'Admin';
        $password = $this->option('password') ?: Str::random(20);
        $workspaceName = $this->option('workspace');

        $workspace = Workspace::firstOrCreate(
            ['slug' => Str::slug($workspaceName)],
            ['name' => $workspaceName],
        );

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'current_workspace_id' => $workspace->id,
            ],
        );

        WorkspaceMembership::firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['role' => 'owner'],
        );

        $this->newLine();
        $this->info($user->wasRecentlyCreated ? 'Admin user created.' : 'Admin user updated.');
        $this->line("  id:        {$user->id}");
        $this->line("  email:     {$user->email}");
        $this->line("  workspace: {$workspace->slug}");

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
