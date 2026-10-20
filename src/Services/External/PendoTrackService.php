<?php

namespace App\Services\External;

use App\Repository\UserRepository;
use App\Services\Security\JwtAuthenticationService;
use App\Services\System\EnvReader;
use Closure;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Handles sending server-side Track Events to Pendo:
 * - nothing is sent unless the integration key is configured, see {@see EnvReader::getPendoTrackEventSecret()},
 * - events are queued and sent once the response has been delivered (or the command has finished),
 *   so tracking never slows down nor breaks the tracked logic, failures are only logged,
 * - event names must be exactly the same (case-sensitive) as the track types registered in Pendo,
 */
class PendoTrackService implements EventSubscriberInterface
{
    /**
     * Used whenever there is no user to link the event with,
     * and as account, because there is no account concept - there is only one active user per installation
     */
    public const SYSTEM_IDENTIFIER = "system";

    private const TRACK_ENDPOINT_PATH        = "/data/track";
    private const REQUEST_TIMEOUT_SECONDS    = 3;
    private const MAX_PROPERTY_STRING_LENGTH = 100;

    /**
     * @var array[] $queuedEvents
     */
    private array $queuedEvents = [];

    public function __construct(
        private readonly LoggerInterface          $logger,
        private readonly JwtAuthenticationService $jwtAuthenticationService,
        private readonly UserRepository           $userRepository
    ) {
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE  => "sendQueuedEvents",
            ConsoleEvents::TERMINATE => "sendQueuedEvents",
        ];
    }

    /**
     * Returns the value used for the `error_type` property of the events
     *
     * @param Throwable $throwable
     *
     * @return string
     */
    public static function getErrorType(Throwable $throwable): string
    {
        return (new ReflectionClass($throwable))->getShortName();
    }

    /**
     * Queues the event for given visitor.
     * Properties can be provided as closure, so that these are only built when tracking is enabled,
     * any failure while building them is caught and logged
     *
     * @param string          $eventName - exact name of the track type registered in Pendo
     * @param int|string|null $visitorId - {@see self::SYSTEM_IDENTIFIER} is used if no visitor is known
     * @param array|Closure   $properties - `null` values are skipped
     */
    public function track(string $eventName, int|string|null $visitorId, array|Closure $properties = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            if ($properties instanceof Closure) {
                $properties = $properties();
            }

            $this->queuedEvents[] = [
                "type"       => "track",
                "event"      => $eventName,
                "visitorId"  => empty($visitorId) ? self::SYSTEM_IDENTIFIER : (string)$visitorId,
                "accountId"  => self::SYSTEM_IDENTIFIER,
                "timestamp"  => (int)round(microtime(true) * 1000),
                "properties" => (object)$this->normaliseProperties($properties),
            ];
        } catch (Throwable $e) {
            $this->logFailure("Could not build Pendo track event", $eventName, $e);
        }
    }

    /**
     * Queues the event for the user authenticated with the jwt token of current request
     *
     * @param string        $eventName
     * @param array|Closure $properties
     */
    public function trackForRequestUser(string $eventName, array|Closure $properties = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $userId = null;
        try {
            $userId = $this->jwtAuthenticationService->getUserFromRequest()->getId();
        } catch (Throwable $e) {
            $this->logFailure("Could not get the request user for Pendo track event", $eventName, $e);
        }

        $this->track($eventName, $userId, $properties);
    }

    /**
     * Queues the event for the active user (there is only one per installation),
     * meant for logic executed without request context, such as cron commands
     *
     * @param string        $eventName
     * @param array|Closure $properties
     */
    public function trackForActiveUser(string $eventName, array|Closure $properties = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $userId = null;
        try {
            $userId = $this->userRepository->findOneActive()?->getId();
        } catch (Throwable $e) {
            $this->logFailure("Could not get the active user for Pendo track event", $eventName, $e);
        }

        $this->track($eventName, $userId, $properties);
    }

    /**
     * Sends all the queued events to Pendo
     */
    public function sendQueuedEvents(): void
    {
        if (empty($this->queuedEvents)) {
            return;
        }

        $events             = $this->queuedEvents;
        $this->queuedEvents = [];

        $client = new Client([
            "base_uri"        => "https://" . EnvReader::getPendoDataHost(),
            "timeout"         => self::REQUEST_TIMEOUT_SECONDS,
            "connect_timeout" => self::REQUEST_TIMEOUT_SECONDS,
        ]);

        foreach ($events as $event) {
            try {
                $client->post(self::TRACK_ENDPOINT_PATH, [
                    "headers" => [
                        "Content-Type"            => "application/json",
                        "x-pendo-integration-key" => EnvReader::getPendoTrackEventSecret(),
                    ],
                    "json"    => $event,
                ]);
            } catch (Throwable $e) {
                $this->logFailure("Could not send Pendo track event", $event["event"], $e);
            }
        }
    }

    /**
     * @return bool
     */
    private function isEnabled(): bool
    {
        return !empty(EnvReader::getPendoTrackEventSecret());
    }

    /**
     * Skips `null` values and shortens long strings, as Pendo limits the size of the event properties
     *
     * @param array $properties
     *
     * @return array
     */
    private function normaliseProperties(array $properties): array
    {
        $normalisedProperties = [];
        foreach ($properties as $name => $value) {
            if (is_null($value)) {
                continue;
            }

            $normalisedProperties[$name] = is_string($value) ? mb_substr($value, 0, self::MAX_PROPERTY_STRING_LENGTH) : $value;
        }

        return $normalisedProperties;
    }

    /**
     * Logged below the `warning` level on purpose, as warnings (and above) are being mailed (see monolog config),
     * and analytics failures should not flood the mailbox
     *
     * @param string    $message
     * @param string    $eventName
     * @param Throwable $throwable
     */
    private function logFailure(string $message, string $eventName, Throwable $throwable): void
    {
        $this->logger->notice($message, [
            "event"            => $eventName,
            "exceptionClass"   => $throwable::class,
            "exceptionMessage" => $throwable->getMessage(),
        ]);
    }
}
