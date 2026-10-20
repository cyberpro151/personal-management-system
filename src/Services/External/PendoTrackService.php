<?php

namespace App\Services\External;

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
 * Handles sending the server side track events to Pendo (POST https://{data host}/data/track)
 *
 * - events are queued and sent once the response has been sent / the console command has finished,
 *   so the tracking neither slows down nor breaks the tracked logic (failures are only logged),
 * - nothing is sent if the track event secret is not configured {@see EnvReader::getPendoTrackEventSecret()},
 * - properties must never contain personal / sensitive data (names, emails, file names, passwords, config values etc.),
 */
class PendoTrackService implements EventSubscriberInterface
{
    /**
     * Used when there is no user context at all, and as account id because there is no account / tenant concept
     * in this project (only one active user per installation)
     */
    private const SYSTEM_ID = "system";

    /**
     * Pendo limit for the json encoded properties (including the "properties" key)
     */
    private const MAX_PROPERTIES_JSON_BYTES = 512;

    private const REQUEST_TIMEOUT_SECONDS = 3;

    /**
     * @var Client $client
     */
    private Client $client;

    /**
     * @var array[] $queuedEvents
     */
    private array $queuedEvents = [];

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
        $this->client = new Client([
            'timeout'         => self::REQUEST_TIMEOUT_SECONDS,
            'connect_timeout' => self::REQUEST_TIMEOUT_SECONDS,
        ]);
    }

    /**
     * Queues the track event, it gets sent at the end of current request / console command
     *
     * @param string      $event      - name of the track event registered in Pendo, must be used as-is
     * @param array       $properties - event metadata, properties with `null` value are skipped
     * @param string|null $visitorId  - if not provided then the user is taken from the request jwt token,
     *                                  or the only active user is used (no request context - cron commands)
     */
    public function track(string $event, array $properties = [], ?string $visitorId = null): void
    {
        if (empty(EnvReader::getPendoTrackEventSecret())) {
            return;
        }

        try {
            $properties = array_filter($properties, fn (mixed $value): bool => !is_null($value));

            $this->queuedEvents[] = [
                'type'       => 'track',
                'event'      => $event,
                'visitorId'  => $visitorId ?? $this->resolveVisitorId(),
                'accountId'  => self::SYSTEM_ID,
                'timestamp'  => (int) floor(microtime(true) * 1000),
                'properties' => (object) $this->limitPropertiesSize($event, $properties),
            ];
        } catch (Throwable $e) {
            $this->logger->warning("Could not queue the Pendo track event: {$event}", [
                'exceptionMessage' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sends all the queued events
     */
    public function flush(): void
    {
        $events             = $this->queuedEvents;
        $this->queuedEvents = [];

        foreach ($events as $eventData) {
            try {
                $this->client->post("https://" . EnvReader::getPendoDataHost() . "/data/track", [
                    'json'    => $eventData,
                    'headers' => [
                        'x-pendo-integration-key' => EnvReader::getPendoTrackEventSecret(),
                    ],
                ]);
            } catch (Throwable $e) {
                $this->logger->warning("Could not send the Pendo track event: {$eventData['event']}", [
                    'exceptionMessage' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE  => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
        ];
    }

    /**
     * Project allows only one active user, so:
     * - for requests the user is taken from the jwt token,
     * - for console commands (no token) the only active user is used,
     *
     * @return string
     */
    private function resolveVisitorId(): string
    {
        $user     = null;
        $jwtToken = $this->jwtAuthenticationService->extractJwtFromRequest();
        if (!empty($jwtToken)) {
            $user = $this->jwtAuthenticationService->getUserForToken($jwtToken);
        }

        $user ??= $this->userRepository->findOneActive();
        if (is_null($user?->getId())) {
            return self::SYSTEM_ID;
        }

        return (string) $user->getId();
    }

    /**
     * Removes the biggest properties until the encoded properties fit into the size accepted by Pendo
     *
     * @param string $event
     * @param array  $properties
     *
     * @return array
     */
    private function limitPropertiesSize(string $event, array $properties): array
    {
        $removedProperties = [];
        while (!empty($properties) && strlen(json_encode(['properties' => $properties])) > self::MAX_PROPERTIES_JSON_BYTES) {
            $propertiesSizes = array_map(fn (mixed $value): int => strlen(json_encode($value)), $properties);
            $biggestProperty = array_search(max($propertiesSizes), $propertiesSizes, true);

            $removedProperties[] = $biggestProperty;
            unset($properties[$biggestProperty]);
        }

        if (!empty($removedProperties)) {
            $this->logger->warning("Pendo track event properties are too big, some of them were not sent", [
                'event'             => $event,
                'removedProperties' => $removedProperties,
            ]);
        }

        return $properties;
    }
}
