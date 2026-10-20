<?php

namespace App\Services\External;

use App\Entity\User;
use App\Enum\PendoTrackEventEnum;
use App\Repository\UserRepository;
use App\Services\Security\JwtAuthenticationService;
use App\Services\System\EnvReader;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Sends server-side Track Events to the Pendo Track API (`POST https://<data host>/data/track`):
 * - tracking is disabled until the integration key is provided in env ({@see EnvReader::getPendoTrackEventSecret()}),
 * - events are only queued while the request / command is being handled and are sent on `kernel.terminate` /
 *   `console.terminate` (the response is already sent to the client by then), so tracking never delays the response,
 * - any failure is logged and swallowed, tracking must never break the application flow.
 */
class PendoTrackService implements EventSubscriberInterface
{
    private const TRACK_ENDPOINT_PATH     = "/data/track";
    private const SYSTEM_VISITOR_ID       = "system";
    private const CONNECT_TIMEOUT_SECONDS = 2;
    private const TIMEOUT_SECONDS         = 5;

    /**
     * @var array[] $queuedEvents
     */
    private array $queuedEvents = [];

    /**
     * Visitor of the events tracked without explicitly provided user, cached for current request / command
     *
     * @var string|null $implicitVisitorId
     */
    private ?string $implicitVisitorId = null;

    /**
     * @var Client|null $client
     */
    private ?Client $client = null;

    /**
     * @param LoggerInterface          $logger
     * @param JwtAuthenticationService $jwtAuthenticationService
     * @param UserRepository           $userRepository
     */
    public function __construct(
        private readonly LoggerInterface          $logger,
        private readonly JwtAuthenticationService $jwtAuthenticationService,
        private readonly UserRepository           $userRepository,
    ) {
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE  => "flush",
            ConsoleEvents::TERMINATE => "flush",
        ];
    }

    /**
     * Queues the event, it gets sent to Pendo once the current request / command is done.
     *
     * @param PendoTrackEventEnum $event
     * @param array               $properties - entries with `null` value are skipped
     * @param User|null           $user       - visitor of the event, resolved by {@see PendoTrackService::resolveImplicitVisitorId()} if not provided
     */
    public function track(PendoTrackEventEnum $event, array $properties = [], ?User $user = null): void
    {
        if (empty(EnvReader::getPendoTrackEventSecret())) {
            return;
        }

        try {
            $visitorId = is_null($user?->getId()) ? $this->resolveImplicitVisitorId() : (string) $user->getId();

            $eventProperties = [];
            foreach ($properties as $name => $value) {
                if (!is_null($value)) {
                    $eventProperties[$name] = $value;
                }
            }

            $this->queuedEvents[] = [
                "type"       => "track",
                "event"      => $event->value,
                "visitorId"  => $visitorId,
                "accountId"  => $visitorId, // there are no accounts, each instance has a single user who is also the account
                "timestamp"  => (int) (microtime(true) * 1000),
                "properties" => (object) $eventProperties,
            ];
        } catch (Throwable $e) {
            $this->logger->notice("Could not queue the Pendo track event", [
                "event"            => $event->value,
                "exceptionMessage" => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sends all the queued events to Pendo
     */
    public function flush(): void
    {
        $events             = $this->queuedEvents;
        $this->queuedEvents = [];

        foreach ($events as $event) {
            try {
                $this->getClient()->post("https://" . EnvReader::getPendoDataHost() . self::TRACK_ENDPOINT_PATH, [
                    "json"    => $event,
                    "headers" => [
                        "x-pendo-integration-key" => EnvReader::getPendoTrackEventSecret(),
                    ],
                ]);
            } catch (Throwable $e) {
                $this->logger->notice("Could not send the Pendo track event", [
                    "event"            => $event["event"],
                    "exceptionMessage" => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resolves the visitor of the events tracked without explicitly provided user:
     * - user authenticated in the current request (jwt),
     * - else the active user - there can be only one ({@see \App\Action\System\AppAction::registerUser()}),
     *   so it's the user of console commands (crons),
     * - else {@see PendoTrackService::SYSTEM_VISITOR_ID}
     *
     * @return string
     */
    private function resolveImplicitVisitorId(): string
    {
        if (!is_null($this->implicitVisitorId)) {
            return $this->implicitVisitorId;
        }

        $user = null;
        try {
            $jwtToken = $this->jwtAuthenticationService->extractJwtFromRequest();
            if (!empty($jwtToken)) {
                $user = $this->jwtAuthenticationService->getUserForToken($jwtToken);
            }

            $user ??= $this->userRepository->findOneActive();
        } catch (Throwable $e) {
            $this->logger->notice("Could not resolve the Pendo visitor, falling back to: " . self::SYSTEM_VISITOR_ID, [
                "exceptionMessage" => $e->getMessage(),
            ]);
        }

        $this->implicitVisitorId = is_null($user?->getId()) ? self::SYSTEM_VISITOR_ID : (string) $user->getId();

        return $this->implicitVisitorId;
    }

    /**
     * @return Client
     */
    private function getClient(): Client
    {
        $this->client ??= new Client([
            "connect_timeout" => self::CONNECT_TIMEOUT_SECONDS,
            "timeout"         => self::TIMEOUT_SECONDS,
        ]);

        return $this->client;
    }
}
