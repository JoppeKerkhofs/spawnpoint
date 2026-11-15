<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:setup')]
#[Description('Register the node this panel was started with and create the first admin; run on every container start')]
class SetupPanel extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Read straight from the environment, so these secrets never land in the cached config file.
        $token = (string) getenv('MCPANEL_TOKEN');
        $email = (string) getenv('PANEL_ADMIN_EMAIL');
        $password = (string) getenv('PANEL_ADMIN_PASSWORD');

        if (strlen($token) < 32) {
            $this->error('MCPANEL_TOKEN must be at least 32 characters.');

            return self::FAILURE;
        }

        // The token can be rotated by changing the environment and restarting.
        Node::updateOrCreate(['name' => 'local'], ['base_url' => config('panel.node_url'), 'token' => $token]);

        if (User::query()->exists()) {
            return self::SUCCESS;
        }

        if ($email === '' || $password === '') {
            $this->warn('No users yet. Set PANEL_ADMIN_EMAIL and PANEL_ADMIN_PASSWORD and restart to create the first admin.');

            return self::SUCCESS;
        }

        // The first account has no email to click a link in, so it starts verified.
        User::create(['name' => 'Admin', 'email' => $email, 'password' => $password])
            ->forceFill(['role' => 'admin', 'email_verified_at' => now()])
            ->save();
        $this->info("Created the first admin, {$email}.");

        return self::SUCCESS;
    }
}
