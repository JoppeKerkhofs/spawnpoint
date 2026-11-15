<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('node:register {name : Display name of the node} {base_url : URL of its Rust service, for example http://localhost:8080}')]
#[Description('Register a node and print the token its Rust service must be started with')]
class RegisterNode extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $token = Str::random(64);

        Node::create([
            'name' => $this->argument('name'),
            'base_url' => rtrim($this->argument('base_url'), '/'),
            'token' => $token,
        ]);

        $this->info('Node registered. Start its Rust service with:');
        $this->line("SPAWNPOINT_TOKEN={$token}");

        return self::SUCCESS;
    }
}
