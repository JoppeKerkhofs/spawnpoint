<?php

namespace App\Http\Controllers;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Inertia\Inertia;

abstract class Controller
{
    /**
     * Run an action against the node and turn its failures into a toast.
     *
     * @param  array<int, string>  $messages  Texts for specific status codes from the Rust service.
     * @return mixed The action's result, or null when it failed.
     */
    protected function attempt(Closure $action, ?string $success = null, array $messages = []): mixed
    {
        try {
            $result = $action();
        } catch (ConnectionException) {
            return $this->fail(__('The Rust service did not answer.'));
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            return $this->fail($messages[$status] ?? match ($status) {
                404 => __('This server has no container yet. Save its configuration to create it.'),
                409 => __('The server is busy with another task.'),
                default => __('The Rust service refused the request (:status).', ['status' => $status]),
            });
        }

        if ($success !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $success]);
        }

        return $result;
    }

    private function fail(string $message): null
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return null;
    }
}
