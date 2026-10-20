<?php

namespace App\Command\Module\Schedules;

use App\DTO\NotificationDto;
use App\DTO\Settings\Notifications\ConfigDto;
use App\DTO\Settings\SettingNotificationDto;
use App\Entity\Modules\Schedules\MyScheduleReminder;
use App\Enum\PendoTrackEventEnum;
use App\Repository\Modules\Schedules\MyScheduleRepository;
use App\Services\External\DiscordService;
use App\Services\External\PendoTrackService;
use App\Services\Settings\SettingsLoader;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand('schedules:send-reminders', 'Send reminders over configured notification handlers')]
class SendRemindersCommand extends Command
{
    /**
     * @var SymfonyStyle $io
     */
    private SymfonyStyle $io;

    /**
     * @var string $reminderUserName
     */
    private string $reminderUserName;

    /**
     * @param MyScheduleRepository   $myScheduleRepository
     * @param SettingsLoader         $settingsLoader
     * @param LoggerInterface        $logger
     * @param ParameterBagInterface  $parameterBag
     * @param DiscordService         $discordService
     * @param EntityManagerInterface $entityManager
     * @param PendoTrackService      $pendoTrackService
     */
    public function __construct(
        private readonly MyScheduleRepository   $myScheduleRepository,
        private readonly SettingsLoader         $settingsLoader,
        private readonly LoggerInterface        $logger,
        readonly ParameterBagInterface          $parameterBag,
        private readonly DiscordService         $discordService,
        private readonly EntityManagerInterface $entityManager,
        private readonly PendoTrackService      $pendoTrackService,
    ) {
        parent::__construct(self::$defaultName);
        $this->reminderUserName = $parameterBag->get('project.name') . " Reminder";

    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $remindersProcessedCount = 0;
        $notificationsSentCount  = 0;
        $activeChannels          = [];

        try {
            $io                 = new SymfonyStyle($input, $output);
            $setting            = $this->settingsLoader->getSettingsForNotifications();
            $notificationConfig = SettingNotificationDto::fromJson($setting->getValue());

            if (empty($notificationConfig->getConfig())) {
                $io->info("No notification configs were found");
                return Command::INVALID;
            }

            $activeChannels        = $this->getActiveChannelNames($notificationConfig->getConfig());
            $incomingSchedulesDTOS = $this->myScheduleRepository->getSchedulesWithRemindersInformation();
            foreach ($incomingSchedulesDTOS as $scheduleDto) {
                $title = "Schedule: {$scheduleDto->getTitle()} - {$scheduleDto->getDate()}";
                $notificationDto = new NotificationDto(title: $title, message: $scheduleDto->getBody(), username: $this->reminderUserName);
                $notificationsSentCount += $this->handleNotifications($notificationDto, $notificationConfig->getConfig());

                $reminder = $this->entityManager->find(MyScheduleReminder::class, $scheduleDto->getReminderId());
                $reminder->setProcessed(true);

                $this->entityManager->persist($reminder);
                $this->entityManager->flush();
                $remindersProcessedCount++;
            }
        } catch (Exception $e) {
            $this->logger->critical($e->getMessage(), ['exception' => $e]);
            $this->trackRemindersSent("failure", $remindersProcessedCount, $notificationsSentCount, $activeChannels);
            return Command::FAILURE;
        }

        // runs without any due reminder are not tracked
        if ($remindersProcessedCount > 0) {
            $this->trackRemindersSent("success", $remindersProcessedCount, $notificationsSentCount, $activeChannels);
        }

        return Command::SUCCESS;
    }

    /**
     * @param NotificationDto $notificationDto
     * @param array           $notificationConfigs
     *
     * @return int - count of sent notifications
     * @throws GuzzleException
     */
    private function handleNotifications(NotificationDto $notificationDto, array $notificationConfigs): int
    {
        $sentCount = 0;
        foreach ($notificationConfigs as $config) {
            if (!$config->isActiveForReminder()) {
                continue;
            }

            if (empty($config->getValue())) {
                $this->io->info("Config value for this config is empty: {$config->getName()}");
                continue;
            }

            switch ($config->getName()) {
                case ConfigDto::NAME_DISCORD_WEBHOOK:
                    $notificationDto->setUrl($config->getValue());
                    $this->discordService->sendWebhookMessage($notificationDto);
                    $sentCount++;
                break;
            };
        }

        return $sentCount;
    }

    /**
     * Returns names of the channels which can deliver the reminders (active for reminder and having value)
     *
     * @param ConfigDto[] $notificationConfigs
     *
     * @return string[]
     */
    private function getActiveChannelNames(array $notificationConfigs): array
    {
        $activeConfigs = array_filter(
            $notificationConfigs,
            fn(ConfigDto $config) => $config->isActiveForReminder() && !empty($config->getValue())
        );

        return array_values(array_map(fn(ConfigDto $config) => $config->getName(), $activeConfigs));
    }

    /**
     * Notifications are counted separately from the reminders, because reminders are marked as processed
     * even if no channel sent them - so `0` sent notifications with processed reminders means silently dropped reminders.
     *
     * @param string   $runStatus
     * @param int      $remindersProcessedCount
     * @param int      $notificationsSentCount
     * @param string[] $activeChannels
     */
    private function trackRemindersSent(string $runStatus, int $remindersProcessedCount, int $notificationsSentCount, array $activeChannels): void
    {
        sort($activeChannels);
        $this->pendoTrackService->track(PendoTrackEventEnum::SCHEDULE_REMINDERS_SENT, [
            'reminders_processed_count' => $remindersProcessedCount,
            'notifications_sent_count'  => $notificationsSentCount,
            'active_channels'           => implode(",", $activeChannels),
            'run_status'                => $runStatus,
        ]);
    }

}
