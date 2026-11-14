<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'node_id' => Node::factory(),
            'owner_id' => User::factory(),
            'name' => fake()->words(2, true),
            'slug' => $slug,
            'hostname' => $slug.'.'.config('panel.base_domain'),
            'server_type' => 'PAPER',
            'mc_version' => 'LATEST',
            'java_version' => 21,
            'memory_mb' => 4096,
            'settings' => ['motd' => 'A Minecraft server', 'difficulty' => 'normal', 'mode' => 'survival', 'max_players' => 20],
            'eula_accepted_at' => now(),
        ];
    }
}
