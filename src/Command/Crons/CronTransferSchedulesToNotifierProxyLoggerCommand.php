<?php

namespace App\Command\Crons;

use App\DTO\Modules\Schedules\IncomingScheduleDTO;
use App\Enum\PendoTrackEventEnum;
use App\Repository\Modules\Schedules\MyScheduleReminderRepository;
use App\Repository\Modules\Schedules\MyScheduleRepository;
use App\Response\BaseResponse;
use App\Services\External\NotifierProxyLoggerService;
use App\Services\External\PendoTrackService;
use App\Traits\ExceptionLoggerAwareTrait;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CronTransferSchedulesToNotifierProxyLoggerCommand extends Command
{
    use ExceptionLoggerAwareTrait;

    protected static $defaultName = 'cron:transfer-schedules-to-notifier-proxy-logger';

    const OPTION_TRANSFER_CHANNEL = "transfer-channel";

    const TRANSFER_CHANNEL_MAIL    = "mail";
    const TRANSFER_CHANNEL_DISCORD = "discord";

    /**
     * @var string $channel
     */
    private string $channel;

    /**
     * @var NotifierProxyLoggerService $notifierProxyLoggerService
     */
    private NotifierProxyLoggerService $notifierProxyLoggerService;

    public function __construct(
        NotifierProxyLoggerService $notifierProxyLoggerService,
        private readonly MyScheduleReminderRepository $scheduleReminderRepository,
        private readonly MyScheduleRepository $myScheduleRepository,
        private readonly LoggerInterface $logger,
        private readonly PendoTrackService $pendoTrackService,
    )
    {
        parent::__construct(self::$defaultName);

        $this->notifierProxyLoggerService = $notifierProxyLoggerService;
    }


    protected function configure()
    {
        $this
            ->setDescription('Will transfer all schedules with reminders to the NPL.')
            ->addOption(self::OPTION_TRANSFER_CHANNEL, "type", InputOption::VALUE_REQUIRED, "What channel should be used to send the message later on" )
            ->addUsage("--transfer-channel=mail (Will send the schedules via mailing)")
            ->addUsage("--transfer-channel=discord (Will send the schedules via discord)")
            ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws Exception
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
         $this->channel = $input->getOption(self::OPTION_TRANSFER_CHANNEL, null);

         if( empty($this->channel) ){
             throw new Exception("No transfer channel was provided");
         }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $remindersProcessedCount = 0;
        $notificationsSentCount  = 0;

        try{
            $this->logger->info("Started transferring the schedules to npl");
            {
                $incomingSchedulesDTOS = $this->myScheduleRepository->getSchedulesWithRemindersInformation();

                if( empty($incomingSchedulesDTOS) ){
                    $this->logger->info("No schedules were found to transfer");
                    return Command::SUCCESS;
                }

                foreach($incomingSchedulesDTOS as $incomingScheduleDTO){
                    $this->logger->info("Now handling schedule with id {$incomingScheduleDTO->getId()}, with reminder of id {$incomingScheduleDTO->getReminderId()}");
                    $response = $this->handleTransferForChannel($incomingScheduleDTO);

                    if($response->isSuccess()){
                        $notificationsSentCount++;

                        $reminder = $this->scheduleReminderRepository->findOneById($incomingScheduleDTO->getReminderId());
                        $reminder->setProcessed(true);
                        $this->scheduleReminderRepository->saveReminder($reminder);
                        $remindersProcessedCount++;
                    }
                }
            }
            $this->logger->info("Finished transferring the schedules to npl");

        }catch(Exception $e){
            $this->logException($e);
            $this->trackRemindersSent("failure", $remindersProcessedCount, $notificationsSentCount);
            return Command::FAILURE;
        }

        // runs without any processed reminder are not tracked
        if ($remindersProcessedCount > 0) {
            $this->trackRemindersSent("success", $remindersProcessedCount, $notificationsSentCount);
        }

        return Command::SUCCESS;
    }

    /**
     * This is the second delivery path of the schedule reminders, so it's tracked the same way as in
     * {@see \App\Command\Module\Schedules\SendRemindersCommand}, with the channel used for transfer
     *
     * @param string $runStatus
     * @param int    $remindersProcessedCount
     * @param int    $notificationsSentCount
     */
    private function trackRemindersSent(string $runStatus, int $remindersProcessedCount, int $notificationsSentCount): void
    {
        $this->pendoTrackService->track(PendoTrackEventEnum::SCHEDULE_REMINDERS_SENT, [
            'reminders_processed_count' => $remindersProcessedCount,
            'notifications_sent_count'  => $notificationsSentCount,
            'active_channels'           => $this->channel,
            'run_status'                => $runStatus,
        ]);
    }

    /**
     * Handle sending the schedule depending on the provided channel in the console (as option)
     *
     * @param IncomingScheduleDTO $incomingScheduleDTO
     * @return BaseResponse
     * @throws GuzzleException
     */
    private function handleTransferForChannel(IncomingScheduleDTO $incomingScheduleDTO): BaseResponse
    {
        switch($this->channel)
        {
            case self::TRANSFER_CHANNEL_DISCORD:
            {
                $response = $this->notifierProxyLoggerService->insertDiscordMessageForIncomingScheduleDto($incomingScheduleDTO);
            }
            break;

            case self::TRANSFER_CHANNEL_MAIL:
            {
                $response = $this->notifierProxyLoggerService->insertEmailForIncomingScheduleDto($incomingScheduleDTO);
            }
            break;

            default:
                throw new Exception("Unsupported channel {$this->channel}");
        }

        $this->logger->info("Got response", [
            $response->toJson(),
        ]);

        return $response;
    }
}
