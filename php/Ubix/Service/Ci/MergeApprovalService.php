<?php

declare(strict_types=1);

namespace Ubix\Service\Ci;

use Psr\Http\Client\ClientExceptionInterface as ClientException;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Whether a merge request carries a human sign-off: an Approve, or a thumbs-up
 *
 * Backs `ci:requireApproval`, a pipeline job that fails until someone signs off, so
 * with the project setting "Pipelines must succeed" a merge needs that sign-off. It
 * works on any GitLab tier (approval *rules* need a paid one) and copes with a team
 * where every MR is opened under the owner's account: a username listed as an owner
 * may sign off on their own MR, and the job says so in its log.
 *
 * A guard against merging in haste, not a security boundary: a click costs nothing,
 * and anyone holding the owner's token can make it.
 *
 * @see \Ubix\Tests\Service\Ci\MergeApprovalServiceTest PHPUnit test case
 */
final class MergeApprovalService
{
    /**
     * Constructor
     *
     * @param Logger         $logger         Logger
     * @param HttpClient     $httpClient     Talks to the GitLab API
     * @param RequestFactory $requestFactory Builds those requests
     * @param JsonService    $jsonService    Decodes the replies
     */
    public function __construct(
        private Logger $logger, // @phpstan-ignore property.onlyWritten (Logger is a required dependency of most uBixCore classes but has not been implemented in this class yet)
        private HttpClient $httpClient,
        private RequestFactory $requestFactory,
        private JsonService $jsonService,
    ) {
    }

    /**
     * The iid of the open MR a branch belongs to, or null when it has none
     *
     * Branch pipelines carry no MR variables, so the MR is looked up by its source branch.
     *
     * @param string $apiUrl    GitLab API v4 base URL
     * @param string $projectId Project id
     * @param string $token     API token (read access)
     * @param string $branch    Source branch
     *
     * @return ?int The MR iid (GitLab failures surface as RuntimeException)
     */
    public function getOpenMergeRequestIid(string $apiUrl, string $projectId, string $token, string $branch): ?int
    {
        $list = $this->get($apiUrl, $token, '/projects/' . rawurlencode($projectId) . '/merge_requests?state=opened&source_branch=' . rawurlencode($branch));

        foreach ($list as $mergeRequest) {
            if (is_array($mergeRequest) && is_int($mergeRequest['iid'] ?? null)) {
                return $mergeRequest['iid'];
            }
        }

        return null;
    }

    /**
     * Who has signed off on an MR, and whether that is enough
     *
     * A sign-off is an Approve or a thumbs-up from one of `$owners`, the author included when listed.
     * Anyone else's (another user, a bot or an agent token) is reported but does not count, so the
     * gate cannot be satisfied by an account that is not a listed owner. No owners means nothing counts.
     *
     * @param string   $apiUrl    GitLab API v4 base URL
     * @param string   $projectId Project id
     * @param string   $token     API token (read access)
     * @param int      $iid       MR iid
     * @param string[] $owners    Usernames whose Approve or thumbs-up counts
     *
     * GitLab failures surface as RuntimeException.
     *
     * @return array{approved: bool, author: string, signedOffBy: list<string>, selfSignOff: bool, ignored: list<string>} The verdict
     */
    public function getVerdict(string $apiUrl, string $projectId, string $token, int $iid, array $owners): array
    {
        $base   = '/projects/' . rawurlencode($projectId) . '/merge_requests/' . $iid;
        $mr     = $this->get($apiUrl, $token, $base);
        $author = is_array($mr['author'] ?? null) && is_string($mr['author']['username'] ?? null) ? $mr['author']['username'] : '';

        $people = [];

        $approvals = $this->get($apiUrl, $token, $base . '/approvals');
        foreach (is_array($approvals['approved_by'] ?? null) ? $approvals['approved_by'] : [] as $entry) {
            $people[] = $this->getUsername($entry);
        }

        foreach ($this->get($apiUrl, $token, $base . '/award_emoji') as $award) {
            if (is_array($award) && ($award['name'] ?? null) === 'thumbsup') {
                $people[] = $this->getUsername($award);
            }
        }

        $people      = array_values(array_unique(array_filter($people, static function (string $name): bool {
            return $name !== '';
        })));
        $counted     = array_values(array_filter($people, static function (string $name) use ($owners): bool {
            return in_array($name, $owners, true);
        }));
        $ignored     = array_values(array_diff($people, $counted));
        $selfSignOff = $counted === [$author];

        return ['approved' => $counted !== [], 'author' => $author, 'signedOffBy' => $counted, 'selfSignOff' => $selfSignOff, 'ignored' => $ignored];
    }

    /**
     * The username inside an approval or award entry
     *
     * @param mixed $entry One entry of the API's list
     *
     * @return string The username, or '' when there is none
     */
    private function getUsername(mixed $entry): string
    {
        $user = is_array($entry) && is_array($entry['user'] ?? null) ? $entry['user'] : [];

        return is_string($user['username'] ?? null) ? $user['username'] : '';
    }

    /**
     * GET a GitLab API path and decode it
     *
     * @param string $apiUrl GitLab API v4 base URL
     * @param string $token  API token
     * @param string $path   Path below the base URL
     *
     * @throws RuntimeException When the request fails or the reply is not JSON
     *
     * @return array<int|string, mixed> The decoded reply
     */
    private function get(string $apiUrl, string $token, string $path): array
    {
        $request  = $this->requestFactory->createRequest('GET', rtrim($apiUrl, '/') . $path)->withHeader('PRIVATE-TOKEN', $token);
        $response = $this->send($request);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf('GitLab answered HTTP %d for %s', $response->getStatusCode(), strtok($path, '?')));
        }

        try {
            return $this->jsonService->decode((string) $response->getBody());
        } catch (DtoException $e) {
            throw new RuntimeException('GitLab answered with something that is not JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Send a request; a transport failure becomes the RuntimeException callers already handle
     *
     * @param Request $request The request
     *
     * @throws RuntimeException When the request cannot be sent (timeout, DNS, TLS)
     *
     * @return Response The response
     */
    private function send(Request $request): Response
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientException $e) {
            throw new RuntimeException('Request to ' . $request->getUri()->getHost() . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
