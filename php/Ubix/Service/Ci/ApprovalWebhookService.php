<?php

declare(strict_types=1);

namespace Ubix\Service\Ci;

use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Re-run a merge request's sign-off job when someone approves or un-approves it
 * (or adds or removes a thumbs-up, which `ci:requireApproval` also counts)
 *
 * GitLab creates no pipeline on approval, so `ci:requireApproval`'s verdict would stay
 * frozen at the last push. A merge-request webhook delivers the approve event here, and
 * this retries just the newest sign-off job on the MR's head pipeline. A job, never a new
 * pipeline: a pipeline would re-run every check and the AI review for each click.
 *
 * Fails soft. Only a wrong secret is refused (401); every other outcome, including an
 * error, is answered 200 and logged, because GitLab disables a webhook that keeps
 * failing and a disabled webhook fails silently.
 *
 * @see \Ubix\Tests\Service\Ci\ApprovalWebhookServiceTest PHPUnit test case
 */
final class ApprovalWebhookService
{
    /**
     * Merge-request actions that change the sign-off verdict
     */
    private const SIGN_OFF_ACTIONS = ['approval', 'approved', 'unapproval', 'unapproved'];

    /**
     * The merge-request actions that take a sign-off away
     */
    private const REVOKING_ACTIONS = ['unapproval', 'unapproved'];

    /**
     * The award emoji `ci:requireApproval` counts as a sign-off
     */
    private const SIGN_OFF_EMOJI = 'thumbsup';

    /**
     * Job states where a retry would only queue a duplicate
     */
    private const ACTIVE_STATES = ['created', 'pending', 'preparing', 'running', 'waiting_for_resource'];

    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     Talks to the GitLab API
     * @param RequestFactory $requestFactory Builds those requests
     * @param JsonService    $jsonService    Decodes payloads and replies
     * @param string         $apiUrl         GitLab API v4 base URL
     * @param string         $secret         The webhook's secret token; empty refuses everything
     * @param string         $projectTokens  Pairs `id=token`, comma-separated as the environment carries them (Developer role, `api` scope)
     * @param string         $apiToken       Fallback token for a project with no pair above (e.g. a group access token); empty means none
     * @param string[]       $jobNames       Names of the sign-off jobs to retry
     */
    public function __construct(
        private Logger $logger,
        private HttpClient $httpClient,
        private RequestFactory $requestFactory,
        private JsonService $jsonService,
        private string $apiUrl,
        private string $secret,
        private string $projectTokens,
        private string $apiToken,
        private array $jobNames,
    ) {
    }

    /**
     * Handle one webhook delivery
     *
     * @param string $secretHeader The `X-Gitlab-Token` header
     * @param string $body         The raw payload
     *
     * @return array{status: int, outcome: string} HTTP status to answer, and what happened
     */
    public function handle(string $secretHeader, string $body): array
    {
        if ($this->secret === '' || !hash_equals($this->secret, $secretHeader)) {
            return ['status' => 401, 'outcome' => 'rejected: webhook secret does not match'];
        }

        try {
            $outcome = $this->retrySignOffJob($body);
        } catch (RuntimeException $e) {
            $outcome = 'error: ' . $e->getMessage();
            $this->logger->error('Approval webhook failed', ['error' => $e->getMessage()]);
        }

        $this->logger->info('Approval webhook', ['outcome' => $outcome]);

        return ['status' => 200, 'outcome' => $outcome];
    }

    /**
     * Find the MR's newest sign-off job and retry it, if the event calls for that
     *
     * @param string $body The raw payload (a bad payload or a failed GitLab call surfaces as RuntimeException)
     *
     * @return string What happened
     */
    private function retrySignOffJob(string $body): string
    {
        $event   = $this->decode($body);
        $signOff = $this->getSignOff($event);

        if ($signOff === null) {
            return 'ignored: not an approval event';
        }

        $project   = is_array($event['project'] ?? null) ? $event['project'] : [];
        $projectId = is_int($project['id'] ?? null) ? (string) $project['id'] : '';
        $iid       = $signOff['iid'];
        $action    = $signOff['action'];
        $token     = $this->getProjectTokens()[$projectId] ?? $this->apiToken;

        if ($token === '' || $iid === 0) {
            return 'ignored: no token configured for project ' . $projectId;
        }

        $base         = '/projects/' . rawurlencode($projectId);
        $mergeRequest = $this->call('GET', $base . '/merge_requests/' . $iid, $token);
        $pipeline     = is_array($mergeRequest['head_pipeline'] ?? null) ? $mergeRequest['head_pipeline'] : [];
        $pipelineId   = is_int($pipeline['id'] ?? null) ? $pipeline['id'] : 0;

        if ($pipelineId === 0) {
            return 'ignored: !' . $iid . ' has no pipeline';
        }

        $job = $this->getNewestSignOffJob($this->call('GET', $base . '/pipelines/' . $pipelineId . '/jobs?per_page=100', $token));

        if ($job === null) {
            return 'ignored: pipeline ' . $pipelineId . ' has no sign-off job';
        }

        if (in_array($job['status'], self::ACTIVE_STATES, true)) {
            return 'skipped: job ' . $job['id'] . ' is already ' . $job['status'];
        }

        if ($job['status'] === 'manual') {
            $this->call('POST', $base . '/jobs/' . $job['id'] . '/play', $token);

            return 'played: job ' . $job['id'] . ' (' . $job['name'] . ') for !' . $iid . ' after ' . $action;
        }

        // A job that passed already holds the verdict an approval would give it; only a
        // withdrawn sign-off can turn it red again.
        if ($job['status'] === 'success' && !$signOff['revoking']) {
            return 'skipped: job ' . $job['id'] . ' has already passed';
        }

        $this->call('POST', $base . '/jobs/' . $job['id'] . '/retry', $token);

        return 'retried: job ' . $job['id'] . ' (' . $job['name'] . ') for !' . $iid . ' after ' . $action;
    }

    /**
     * Whether an event is a sign-off change, and which merge request it is about
     *
     * Covers the merge-request hook (approve, un-approve) and the emoji hook (a thumbs-up
     * awarded or revoked on a merge request). The emoji verb is the top-level `event_type`
     * (GitLab 19.4.2); `object_attributes.action` is read as a fallback.
     *
     * @param array<int|string, mixed> $event The decoded payload
     *
     * @return ?array{iid: int, action: string, revoking: bool} Null when the event is not one
     */
    private function getSignOff(array $event): ?array
    {
        $attributes = is_array($event['object_attributes'] ?? null) ? $event['object_attributes'] : [];
        $action     = is_string($attributes['action'] ?? null) ? $attributes['action'] : '';
        $kind       = $event['object_kind'] ?? null;
        $verb       = is_string($event['event_type'] ?? null) ? $event['event_type'] : $action;

        if ($kind === 'merge_request' && in_array($action, self::SIGN_OFF_ACTIONS, true)) {
            return [
                'action'   => $action,
                'iid'      => is_int($attributes['iid'] ?? null) ? $attributes['iid'] : 0,
                'revoking' => in_array($action, self::REVOKING_ACTIONS, true),
            ];
        }

        if ($kind === 'emoji' && ($attributes['name'] ?? null) === self::SIGN_OFF_EMOJI && ($attributes['awardable_type'] ?? null) === 'MergeRequest' && in_array($verb, ['award', 'revoke'], true)) {
            $mergeRequest = is_array($event['merge_request'] ?? null) ? $event['merge_request'] : [];

            return [
                'action'   => 'thumbsup ' . $verb,
                'iid'      => is_int($mergeRequest['iid'] ?? null) ? $mergeRequest['iid'] : 0,
                'revoking' => $verb === 'revoke',
            ];
        }

        return null;
    }

    /**
     * The `id=token,id=token` list as a map; malformed pairs are skipped
     *
     * @return array<string, string> Project id => token
     */
    private function getProjectTokens(): array
    {
        $tokens = [];

        foreach (explode(',', $this->projectTokens) as $pair) {
            $parts = explode('=', trim($pair), 2);

            if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                $tokens[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $tokens;
    }

    /**
     * The newest job in a pipeline whose name is a sign-off job
     *
     * @param array<int|string, mixed> $jobs The pipeline's jobs
     *
     * @return ?array{id: int, name: string, status: string} The job
     */
    private function getNewestSignOffJob(array $jobs): ?array
    {
        $newest = null;

        foreach ($jobs as $job) {
            if (!is_array($job) || !is_int($job['id'] ?? null) || !is_string($job['name'] ?? null) || !is_string($job['status'] ?? null)) {
                continue;
            }

            if (in_array($job['name'], $this->jobNames, true) && ($newest === null || $job['id'] > $newest['id'])) {
                $newest = ['id' => $job['id'], 'name' => $job['name'], 'status' => $job['status']];
            }
        }

        return $newest;
    }

    /**
     * Call the GitLab API and decode the reply
     *
     * @param string $method HTTP method
     * @param string $path   Path below the API base URL
     * @param string $token  API token
     *
     * @throws RuntimeException When the request fails, is refused, or the reply is not JSON
     *
     * @return array<int|string, mixed> The decoded reply
     */
    private function call(string $method, string $path, string $token): array
    {
        $request = $this->requestFactory->createRequest($method, rtrim($this->apiUrl, '/') . $path)->withHeader('PRIVATE-TOKEN', $token);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientException $e) {
            throw new RuntimeException('GitLab could not be reached: ' . $e->getMessage(), 0, $e);
        }

        return $this->decodeResponse($response, $method . ' ' . strtok($path, '?'));
    }

    /**
     * Decode a GitLab reply, or say why it cannot be used
     *
     * @param Response $response The reply
     * @param string   $what     The call, for the message
     *
     * @throws RuntimeException When GitLab refused the call or the reply is not JSON
     *
     * @return array<int|string, mixed> The decoded reply
     */
    private function decodeResponse(Response $response, string $what): array
    {
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('GitLab answered HTTP %d to %s', $status, $what));
        }

        return $this->decode((string) $response->getBody());
    }

    /**
     * Decode JSON
     *
     * @param string $json The text
     *
     * @throws RuntimeException When it is not JSON
     *
     * @return array<int|string, mixed> The value
     */
    private function decode(string $json): array
    {
        try {
            return $this->jsonService->decode($json);
        } catch (DtoException $e) {
            throw new RuntimeException('Not JSON: ' . $e->getMessage(), 0, $e);
        }
    }
}
