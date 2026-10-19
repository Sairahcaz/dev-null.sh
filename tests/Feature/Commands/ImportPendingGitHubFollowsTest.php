<?php

declare(strict_types=1);

use App\Models\PendingFollow;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('remembers whom every imported user follows on github who is not on devnull yet', function (): void {
    $alice = User::factory()->create(['github_follows_imported_at' => now()]);
    $bob = User::factory()->create(['github_follows_imported_at' => now()]);
    $notImported = User::factory()->create();

    fakeGitHubFollows($alice, following: [$bob, User::factory()->make(['github_id' => '111'])]);
    fakeGitHubFollows($bob, following: [User::factory()->make(['github_id' => '222'])]);

    $this->artisan('follows:pending')
        ->expectsOutputToContain('Remembered the pending GitHub follows of 2 users.')
        ->assertSuccessful();

    expect($alice->pendingFollows()->pluck('github_id')->all())->toBe(['111'])
        ->and($bob->pendingFollows()->pluck('github_id')->all())->toBe(['222'])
        ->and(PendingFollow::query()->count())->toBe(2);

    // One request each, since their username is their GitHub login, and none for the user whose follows weren't imported.
    Http::assertSentCount(2);
});

it('skips users whose github account was renamed or deleted since they signed in', function (): void {
    $user = User::factory()->create(['github_follows_imported_at' => now()]);

    Http::fake(["api.github.com/users/{$user->username}/following*" => Http::response(['message' => 'Not Found'], 404)]);

    $this->artisan('follows:pending')
        ->expectsOutputToContain('Remembered the pending GitHub follows of 0 users.')
        ->assertSuccessful();

    expect(PendingFollow::query()->count())->toBe(0);
});

it('only reads the users after the given one', function (): void {
    $first = User::factory()->create(['github_follows_imported_at' => now()]);
    $second = User::factory()->create(['github_follows_imported_at' => now()]);

    fakeGitHubFollows($second, following: [User::factory()->make(['github_id' => '222'])]);

    $this->artisan('follows:pending', ['--after' => $first->id])
        ->expectsOutputToContain('Remembered the pending GitHub follows of 1 users.')
        ->assertSuccessful();

    expect($second->pendingFollows()->pluck('github_id')->all())->toBe(['222']);
});

it('keeps going when whom a user follows cannot be read', function (string $exception, Closure $response): void {
    Exceptions::fake();

    $failing = User::factory()->create(['username' => 'failing', 'github_follows_imported_at' => now()]);
    $user = User::factory()->create(['github_follows_imported_at' => now()]);

    Http::fake(['api.github.com/users/failing/following*' => $response]);
    fakeGitHubFollows($user, following: [User::factory()->make(['github_id' => '222'])]);

    $this->artisan('follows:pending')
        ->expectsOutputToContain('Could not read whom @failing follows on GitHub.')
        ->expectsOutputToContain('Remembered the pending GitHub follows of 1 users.')
        ->assertFailed();

    expect($user->pendingFollows()->count())->toBe(1);

    Exceptions::assertReported($exception);
})->with([
    'server error' => [RequestException::class, fn (): Closure => fn () => Http::response(['message' => 'Server Error'], 500)],
    'connection error' => [ConnectionException::class, fn (): Closure => fn () => Http::failedConnection()],
]);

it('stops when github rate limits the requests, saying where to resume', function (): void {
    $first = User::factory()->create(['github_follows_imported_at' => now()]);
    $second = User::factory()->create(['github_follows_imported_at' => now()]);

    fakeGitHubFollows($first);
    Http::fake(["api.github.com/users/{$second->username}/following*" => Http::response(['message' => 'API rate limit exceeded'], 403)]);

    $this->artisan('follows:pending')
        ->expectsOutputToContain("GitHub rate limit reached after 1 users. Run the command again with --after={$first->id} later.")
        ->assertFailed();
});

it('stops when github rate limits the first request', function (): void {
    $user = User::factory()->create(['github_follows_imported_at' => now()]);

    Http::fake(["api.github.com/users/{$user->username}/following*" => Http::response(['message' => 'API rate limit exceeded'], 429)]);

    $this->artisan('follows:pending')
        ->expectsOutputToContain('GitHub rate limit reached after 0 users. Run the command again later.')
        ->assertFailed();
});

it('keeps the resume point when github rate limits the first user after it', function (): void {
    $first = User::factory()->create(['github_follows_imported_at' => now()]);
    $second = User::factory()->create(['github_follows_imported_at' => now()]);

    Http::fake(["api.github.com/users/{$second->username}/following*" => Http::response(['message' => 'API rate limit exceeded'], 403)]);

    $this->artisan('follows:pending', ['--after' => $first->id])
        ->expectsOutputToContain("GitHub rate limit reached after 0 users. Run the command again with --after={$first->id} later.")
        ->assertFailed();
});

it('leaves the rest of the rate limit to the app, waiting for it to reset', function (): void {
    config(['services.github.client_id' => 'client-id', 'services.github.client_secret' => 'client-secret']);
    Sleep::fake(syncWithCarbon: true);

    $first = User::factory()->create(['github_follows_imported_at' => now()]);
    $second = User::factory()->create(['github_follows_imported_at' => now()]);
    $resetsAt = now()->addMinutes(10)->startOfSecond();

    Http::fake(['api.github.com/rate_limit' => Http::sequence()
        ->push(['resources' => ['core' => ['limit' => 5000, 'remaining' => 1999, 'reset' => $resetsAt->getTimestamp()]]])
        ->push(['resources' => ['core' => ['limit' => 5000, 'remaining' => 5000, 'reset' => $resetsAt->addHour()->getTimestamp()]]]),
    ]);
    fakeGitHubFollows($first, following: [User::factory()->make(['github_id' => '111'])]);
    fakeGitHubFollows($second, following: [User::factory()->make(['github_id' => '222'])]);

    $this->artisan('follows:pending')
        ->expectsOutputToContain("Leaving the rest of the GitHub rate limit to the app until {$resetsAt->addMinute()->toTimeString()}.")
        ->expectsOutputToContain('Remembered the pending GitHub follows of 2 users.')
        ->assertSuccessful();

    Sleep::assertSleptTimes(1);

    expect(PendingFollow::query()->count())->toBe(2);
});
