<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\GitHubApp;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Parser\MarkdownParser;

final readonly class CreateIssue
{
    public function __construct(private GitHubApp $github)
    {
        //
    }

    /**
     * The issue is opened by the app, crediting the reporter, whose GitHub login is their username here.
     *
     * @param  array<string, string>  $environment
     * @return array{number: int, url: string}
     */
    public function handle(User $user, string $command, string $body, array $environment = []): array
    {
        $type = mb_substr($command, 1);
        $title = mb_trim(Str::before($body, "\n"));
        $markdown = new Environment();
        $markdown->addExtension(new CommonMarkCoreExtension());

        foreach (new MarkdownParser($markdown)->parse($body)->children() as $node) {
            if (! $node instanceof Heading || $node->getLevel() !== 1) {
                continue;
            }

            $title = StringContainerHelper::getChildText($node);
            $start = $node->getStartLine();
            $end = $node->getEndLine();
            assert(is_int($start) && is_int($end));
            $lines = explode("\n", $body);
            array_splice($lines, $start - 1, $end - $start + 1);
            $body = mb_trim(implode("\n", $lines), "\n");

            break;
        }

        return $this->github->createIssue(
            Str::limit($title, 256, '') ?: ucfirst($type).' report',
            $body.$this->details($environment)."\n\n---\n\nReported by @{$user->username} via [/dev/null](".route('user.show', $user).').',
            [$type === 'feature' ? 'enhancement' : 'bug'],
        );
    }

    /**
     * Folded away, so the issue reads as written while maintainers still find how to reproduce it.
     *
     * @param  array<string, string>  $environment
     */
    private function details(array $environment): string
    {
        if ($environment === []) {
            return '';
        }

        $rows = collect($environment)
            ->map(fn (string $value, string $name): string => '| '.str_replace('_', ' ', ucfirst($name)).' | `'.str_replace(['`', '|', "\n"], ' ', $value).'` |')
            ->implode("\n");

        return "\n\n<details>\n<summary>Environment</summary>\n\n| | |\n|---|---|\n{$rows}\n\n</details>";
    }
}
