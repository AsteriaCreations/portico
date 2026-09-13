<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * Local-only visibility into whether a configured upstream git remote has
 * commits this checkout hasn't merged yet -- see App\Console\Commands\
 * CheckUpstream (the scheduled `git fetch`) and App\Filament\Admin\Pages\
 * UpstreamUpdates (the page that reads this). pendingCommits() never fetches
 * over the network -- it's a local `git rev-parse`/`git log` diff only, so a
 * page load never blocks on network I/O. See docs/DEPLOYMENT.md "Checking
 * for upstream updates".
 */
class UpstreamUpdateChecker
{
    // Anchored start/end alphanumeric, only [A-Za-z0-9._/-] between -- keep
    // this identical to the `->rule('regex:...')` on the upstream_remote/
    // upstream_branch fields in App\Filament\Admin\Pages\MembershipSettings.
    // Rejects a ref/remote name starting with '-', which git itself could
    // otherwise parse as an option (the same argument-injection class of bug
    // that affects any CLI tool taking a user-supplied ref name). Process's
    // array-form command already avoids shell-string injection; this is a
    // second, independent defense against git's own argument parsing.
    private const SAFE_REF_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9])?$/';

    public function fetch(string $remote): void
    {
        $this->assertSafeRef($remote);

        $result = Process::path(base_path())
            ->timeout(30)
            ->run([...$this->gitPrefix(), 'fetch', $remote]);

        if ($result->failed()) {
            throw new RuntimeException("git fetch {$remote} failed: {$result->errorOutput()}");
        }
    }

    /**
     * Null means "not resolvable" -- never fetched yet, or the remote/branch
     * is misconfigured -- distinct from an empty array, which means
     * "resolved, zero commits pending." No network I/O here: rev-parse and
     * log both only read what the last fetch() already brought down.
     *
     * @return array<int, array{hash: string, short_hash: string, subject: string, author: string, date: Carbon}>|null
     */
    public function pendingCommits(string $remote, string $branch, int $limit = 50): ?array
    {
        $this->assertSafeRef($remote);
        $this->assertSafeRef($branch);

        $ref = "{$remote}/{$branch}";

        $resolved = Process::path(base_path())
            ->timeout(10)
            ->run([...$this->gitPrefix(), 'rev-parse', '--verify', '--quiet', $ref]);

        if ($resolved->failed()) {
            return null;
        }

        $log = Process::path(base_path())
            ->timeout(10)
            ->run([...$this->gitPrefix(), 'log', "HEAD..{$ref}", '--format=%H%x1f%h%x1f%s%x1f%an%x1f%aI', '-n', (string) $limit]);

        if ($log->failed()) {
            throw new RuntimeException("git log HEAD..{$ref} failed: {$log->errorOutput()}");
        }

        return $this->parseLog($log->output());
    }

    /**
     * @return array<int, string>
     */
    private function gitPrefix(): array
    {
        // A web-request-triggered call (the "Check for updates now" button)
        // can run as a different OS account than whoever owns this checkout
        // -- e.g. Apache running as LocalSystem against a repo owned by a
        // dedicated deploy account -- and git's "dubious ownership" check
        // (CVE-2022-24765) then refuses to operate at all unless that exact
        // directory is allow-listed. Scoping the exception to just this
        // invocation (rather than requiring a one-time `git config --system
        // --add safe.directory ...` on every box) means this works out of
        // the box for any fork, with no manual setup step to forget.
        return ['git', '-c', 'safe.directory='.base_path()];
    }

    private function assertSafeRef(string $ref): void
    {
        if (! preg_match(self::SAFE_REF_PATTERN, $ref)) {
            throw new InvalidArgumentException("Not a safe git ref/remote name: {$ref}");
        }
    }

    /**
     * @return array<int, array{hash: string, short_hash: string, subject: string, author: string, date: Carbon}>
     */
    private function parseLog(string $output): array
    {
        $commits = [];

        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if ($line === '') {
                continue;
            }

            [$hash, $short, $subject, $author, $date] = explode("\x1f", $line);

            $commits[] = [
                'hash' => $hash,
                'short_hash' => $short,
                'subject' => $subject,
                'author' => $author,
                'date' => Carbon::parse($date),
            ];
        }

        return $commits;
    }
}
