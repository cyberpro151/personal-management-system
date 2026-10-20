<?php

namespace App\Services\External;

use App\Enum\PendoTrackEventEnum;
use App\Repository\UserRepository;
use App\Services\Security\JwtAuthenticationService;
use App\Services\System\EnvReader;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Handles sending the server side Track Events to Pendo (Track API: `POST https://{dataHost}/data/track`).
 *
 * In http requests the events are only queued and get sent on {@see KernelEvents::TERMINATE},
 * which is called after the response was already delivered, so neither the network latency nor an unreachable
 * Pendo host (offline setup) can slow down or break the request.
 *
 * Tracking is disabled (no-op) as long as the integration key is not provided {@see EnvReader::getPendoTrackEventSecret()}
 */
class PendoTrackService implements EventSubscriberInterface
{
    /**
     * Used for the actions done by visitor who is not registered yet (there is no user to bind the event to)
     */
    public const string ANONYMOUS_VISITOR_ID = "anonymous";

    /**
     * Used when there is no user at all (for example cron running before any user got registered)
     */
    private const string SYSTEM_VISITOR_ID = "system";

    /**
     * The project has no account / tenant concept (single user installation)
     */
    private const string ACCOUNT_ID = "system";

    private const int CONNECT_TIMEOUT_SECONDS = 2;
    private const int REQUEST_TIMEOUT_SECONDS = 5;

    /**
     * @var Client $client
     */
    private readonly Client $client;

    /**
     * @var array[] $queuedEvents
     */
    private array $queuedEvents = [];

    public function __construct(
        private readonly JwtAuthenticationService $jwtAuthenticationService,
        private readonly UserRepository           $userRepository,
        private readonly LoggerInterface          $logger
    ) {
        $this->client = new Client([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout'         => self::REQUEST_TIMEOUT_SECONDS,
        ]);
    }

    /**
     * Tracks the event, properties with `null` value are skipped.
     * Properties must never contain any credentials or personal data (names, paths, free text etc.).
     *
     * @param PendoTrackEventEnum $event
     * @param array               $properties
     * @param string|null         $visitorId - if not provided then it's resolved from the logged-in / active user
     */
    public function track(PendoTrackEventEnum $event, array $properties = [], ?string $visitorId = null): void
    {
        if ("" === EnvReader::getPendoTrackEventSecret()) {
            return;
        }

        try {
            $eventData = [
                'type'       => 'track',
                'event'      => $event->value,
                'visitorId'  => $visitorId ?? $this->resolveVisitorId(),
                'accountId'  => self::ACCOUNT_ID,
                'timestamp'  => (int) round(microtime(true) * 1000),
                'properties' => (object) array_filter($properties, fn(mixed $value) => !is_null($value)),
            ];
        } catch (Throwable $e) {
            $this->logFailure($event->value, $e);
            return;
        }

        // console commands (crons) have no response which should be delivered first
        if ("cli" === PHP_SAPI) {
            $this->send($eventData);
            return;
        }

        $this->queuedEvents[] = $eventData;
    }

    /**
     * Sends all the events queued during the request
     */
    public function sendQueuedEvents(): void
    {
        $queuedEvents       = $this->queuedEvents;
        $this->queuedEvents = [];

        foreach ($queuedEvents as $eventData) {
            $this->send($eventData);
        }
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => "sendQueuedEvents",
        ];
    }

    /**
     * Failures are only logged, tracking must never break the app logic
     *
     * @param array $eventData
     */
    private function send(array $eventData): void
    {
        try {
            $this->client->post("https://" . EnvReader::getPendoDataHost() . "/data/track", [
                'json'    => $eventData,
                'headers' => [
                    'x-pendo-integration-key' => EnvReader::getPendoTrackEventSecret(),
                ],
            ]);
        } catch (Throwable $e) {
            $this->logFailure($eventData['event'], $e);
        }
    }

    /**
     * Visitor id is the user id. The project allows only one active user,
     * so that one is used whenever there is no logged-in user in the request (cron commands etc.).
     *
     * @return string
     */
    private function resolveVisitorId(): string
    {
        $jwtToken = $this->jwtAuthenticationService->extractJwtFromRequest();
        $user     = empty($jwtToken) ? null : $this->jwtAuthenticationService->getUserForToken($jwtToken);
        if (is_null($user)) {
            $user = $this->userRepository->findOneActive();
        }

        $userId = $user?->getId();
        if (is_null($userId)) {
            return self::SYSTEM_VISITOR_ID;
        }

        return (string) $userId;
    }

    /**
     * Logged below the `warning` level on purpose, as warnings get mailed (see monolog config),
     * while not being able to reach Pendo (for example, offline setup) is nothing to be alarmed about.
     *
     * @param string    $eventName
     * @param Throwable $throwable
     */
    private function logFailure(string $eventName, Throwable $throwable): void
    {
        $this->logger->notice("Could not send the Pendo track event", [
            "event"            => $eventName,
            "exceptionMessage" => $throwable->getMessage(),
        ]);
    }
}
