<?php

namespace LaravelEnso\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use LaravelEnso\Api\Contracts\Endpoint;
use LaravelEnso\Api\Enums\Calls;
use LaravelEnso\Api\Exceptions\Api as Exception;
use LaravelEnso\Api\Exceptions\Handler;
use LaravelEnso\Api\Models\Log;
use Throwable;

abstract class Action
{
    private Api $api;
    private bool $handledFailure = false;

    //TODO add return type: Response
    public function handle()
    {
        if (! $this->apiEnabled()) {
            throw Exception::disabled();
        }

        try {
            $this->api = App::make(Api::class, ['endpoint' => $this->endpoint()]);

            $response = $this->api->call();

            $this->log($response);

            if ($response->failed()) {
                (new Handler(...$this->args($response)))->report();
                $this->handledFailure = true;
            }

            return $response->throw();
        } catch (Throwable $exception) {
            if (! $this->handledFailure) {
                (new Handler(...$this->args($exception)))->report();
            }

            throw $exception;
        }
    }

    public function apiEnabled(): bool
    {
        return true;
    }

    abstract protected function endpoint(): Endpoint;

    private function log(Response $response): void
    {
        Log::create([
            'user_id' => Auth::user()?->id,
            'url' => $this->endpoint()->url(),
            'route' => Route::currentRouteName(),
            'method' => $this->endpoint()->method(),
            'status' => $response->status(),
            'try' => $this->api->tries(),
            'type' => Calls::Outbound,
        ]);
    }

    private function args(Throwable | Response $response): array
    {
        $base = [static::class, $this->endpoint()->url(), $this->endpoint()->body()];

        $extra = $response instanceof Response
            ? [$response->status(), $response->body()]
            : [$response->getCode(), $response->getMessage()];

        return [...$base, ...$extra];
    }
}
