<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// One channel per user. What is sent there is chosen per event (App\Events\Changed).
Broadcast::channel('users.{id}', fn (User $user, int $id): bool => $user->id === $id);
