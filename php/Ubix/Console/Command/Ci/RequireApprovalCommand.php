<?php

declare(strict_types=1);

namespace Ubix\Console\Command\Ci;

use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Output\OutputInterface as Output;
use Ubix\Console\Command\AbstractCommand as Command;
use Ubix\Service\Ci\MergeApprovalService;

/**
 * Fail the pipeline until the merge request has a human sign-off
 *
 * With the project setting "Pipelines must succeed", this makes the Approve button (or a
 * thumbs-up) a merge requirement. GitLab does not re-run a pipeline when someone
 * approves, so after signing off, retry this job. Wiring:
 * `docs/standards/branching-and-git-workflow.md` § Merge sign-off.
 *
 * Settings from the job's environment: `APPROVAL_GITLAB_TOKEN` (falls back to
 * `AI_REVIEW_GITLAB_TOKEN`), `MERGE_APPROVAL_OWNERS` (comma-separated usernames whose Approve or
 * thumbs-up counts, the author included; required), and GitLab's predefined `CI_*` variables.
 *
 * Fails open: no token, or GitLab unreachable, passes with a warning rather than holding
 * every merge hostage to an outage.
 *
 * @see \Ubix\Tests\Console\Command\Ci\RequireApprovalCommandTest PHPUnit test case
 */
final class RequireApprovalCommand extends Command
{
    /**
     * Constructor
     *
     * @param Logger               $logger               Logger
     * @param MergeApprovalService $mergeApprovalService Reads the MR's sign-offs
     *
     * @return void
     */
    public function __construct(
        private Logger $logger, // @phpstan-ignore property.onlyWritten (Logger is a required dependency of most uBixCore classes but has not been implemented in this class yet)
        private MergeApprovalService $mergeApprovalService,
    ) {
        parent::__construct($logger);
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('CI only: fail until this pipeline\'s merge request has a human sign-off')->setHelp(
            <<<'HELP'
Passes when the merge request has an Approve or a thumbs-up from a user listed in
MERGE_APPROVAL_OWNERS (the author included). Anyone else's does not count. Fails otherwise. With
"Pipelines must succeed" on, that makes sign-off a merge requirement. After approving,
retry the job: GitLab does not re-run pipelines on approval.

Usage:
  ubix ci:requireApproval
HELP,
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Symfony command signature
    {
        $token = $this->env('APPROVAL_GITLAB_TOKEN') !== '' ? $this->env('APPROVAL_GITLAB_TOKEN') : $this->env('AI_REVIEW_GITLAB_TOKEN');

        if ($token === '') {
            $output->writeln('<comment>UNVERIFIED -- no APPROVAL_GITLAB_TOKEN; passing rather than blocking every merge.</comment>');

            return Command::SUCCESS;
        }

        $apiUrl    = $this->env('CI_API_V4_URL');
        $projectId = $this->env('CI_PROJECT_ID');
        $owners    = array_values(array_filter(array_map('trim', explode(',', $this->env('MERGE_APPROVAL_OWNERS'))), static function (string $name): bool {
            return $name !== '';
        }));

        if ($owners === []) {
            $output->writeln('<error>require-approval: set MERGE_APPROVAL_OWNERS to the usernames whose Approve or thumbs-up counts.</error>');

            return Command::FAILURE;
        }

        try {
            $iid = $this->env('CI_MERGE_REQUEST_IID') !== '' ? (int) $this->env('CI_MERGE_REQUEST_IID') : $this->mergeApprovalService->getOpenMergeRequestIid($apiUrl, $projectId, $token, $this->env('CI_COMMIT_BRANCH'));

            if ($iid === null) {
                $output->writeln('<comment>No open merge request for this branch; nothing to sign off yet.</comment>');

                return Command::FAILURE;
            }

            $verdict = $this->mergeApprovalService->getVerdict($apiUrl, $projectId, $token, $iid, $owners);
        } catch (RuntimeException $e) {
            $output->writeln('<comment>UNVERIFIED -- ' . $e->getMessage() . '; passing rather than blocking every merge.</comment>');

            return Command::SUCCESS;
        }

        if ($verdict['ignored'] !== []) {
            $output->writeln('<comment>Not counted (not in MERGE_APPROVAL_OWNERS): ' . implode(', ', $verdict['ignored']) . '.</comment>');
        }

        if (!$verdict['approved']) {
            $output->writeln('<error>!' . $iid . ' has no sign-off from ' . implode(', ', $owners) . ' yet. Approve it (or give it a thumbs-up), then retry this job.</error>');

            return Command::FAILURE;
        }

        if ($verdict['selfSignOff']) {
            $output->writeln('<comment>SELF SIGN-OFF: ' . $verdict['author'] . ' approved their own !' . $iid . ' (listed in MERGE_APPROVAL_OWNERS).</comment>');
        }

        $output->writeln('<info>!' . $iid . ' signed off by ' . implode(', ', $verdict['signedOffBy']) . '.</info>');

        return Command::SUCCESS;
    }

    /**
     * An environment variable, or '' when unset
     *
     * @param string $name Variable name
     *
     * @return string The value
     */
    private function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
