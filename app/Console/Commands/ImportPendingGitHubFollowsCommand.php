<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreatePendingFollows;
use App\Actions\CreateUserGitHubFollows;
use App\Models\User;
use App\Queries\GitHubRateLimitQuery;
use App\Services\GitHub;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;

#[Signature('follows:pending {--after= : Only the users after this ID, to resume a run GitHub rate limited}')]
#[Description('Remember, for every user whose GitHub follows were imported, the accounts they follow on GitHub that are not on /dev/null yet')]
final class ImportPendingGitHubFollowsCommand extends Command
{
    /**
     * The requests left to the rest of the app, which shares the rate limit: sign-ins import their follows from it.
     */
    private const int Reserve = 2000;

    public function handle(GitHub $github, CreatePendingFollows $createPendingFollows, GitHubRateLimitQuery $rateLimit): int
    {
        $imported = 0;
        $failed = 0;
        $after = $this->option('after');
        $lastId = is_string($after) ? $after : null;

        $users = User::query()
            ->whereNotNull('github_follows_imported_at')
            ->when(is_string($after), fn (Builder $query): Builder => $query->where('id', '>', $after));

        foreach ($users->lazyById() as $user) {
            $this->waitForRateLimit($rateLimit);

            try {
                // The username is the GitHub login, as of their last sign-in, which saves looking it up by ID...
                $createPendingFollows->handle($user, $github->accountIds("users/{$user->username}/following", CreateUserGitHubFollows::MaxPages));

                $imported++;
            } catch (RequestException $exception) {
                if (in_array($exception->response->status(), [403, 429], true)) {
                    $resume = $lastId === null ? '' : " with --after={$lastId}";

                    $this->components->error("GitHub rate limit reached after {$imported} users. Run the command again{$resume} later.");

                    return self::FAILURE;
                }

                // ...and an account renamed or deleted since then is skipped.
                if ($exception->response->status() !== 404) {
                    $this->failed($user, $exception);

                    $failed++;
                }
            } catch (ConnectionException $exception) {
                $this->failed($user, $exception);

                $failed++;
            }

            $lastId = $user->id;
        }

        $this->components->info("Remembered the pending GitHub follows of {$imported} users.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Waits for the rate limit to reset once it gets down to the reserve, and a minute more, so the cached read of the
     * rate limit is a fresh one by then.
     */
    private function waitForRateLimit(GitHubRateLimitQuery $rateLimit): void
    {
        $limit = $rateLimit->isConfigured() ? $rateLimit->get() : null;

        if ($limit === null || $limit['remaining'] >= self::Reserve) {
            return;
        }

        $resumesAt = CarbonImmutable::parse($limit['resets_at'])->addMinute();

        $this->components->info("Leaving the rest of the GitHub rate limit to the app until {$resumesAt->toTimeString()}.");

        Sleep::until($resumesAt);
    }

    private function failed(User $user, RequestException|ConnectionException $exception): void
    {
        report($exception);

        $this->components->warn("Could not read whom @{$user->username} follows on GitHub.");
    }
}
