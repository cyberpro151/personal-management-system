<?php

namespace App\Action\Modules\System\Settings\Notification;

use App\Attribute\ModuleAttribute;
use App\DTO\Settings\Notifications\ConfigDto;
use App\DTO\Settings\SettingNotificationDto;
use App\Entity\Setting;
use App\Enum\PendoTrackEventEnum;
use App\Response\Base\BaseResponse;
use App\Services\External\PendoTrackService;
use App\Services\Module\ModulesService;
use App\Services\RequestService;
use App\Services\Settings\SettingsLoader;
use App\Services\Settings\SettingsSaver;
use App\Services\TypeProcessor\ArrayHandler;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/module/system/settings/notifications/config", name: "module.system.settings.notifications.config.")]
#[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_SYSTEM])]
class ConfigAction extends AbstractController {

    /**
     * @param SettingsSaver     $settingsSaverService
     * @param SettingsLoader    $settingsLoaderService
     * @param PendoTrackService $pendoTrackService
     */
    public function __construct(
        private readonly SettingsSaver     $settingsSaverService,
        private readonly SettingsLoader    $settingsLoaderService,
        private readonly PendoTrackService $pendoTrackService,
    ) {
    }

    /**
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("/all", name: "get_all", methods: [Request::METHOD_GET])]
    public function getAll(): JsonResponse
    {
        $setting = $this->settingsLoaderService->getSettingsForNotifications();
        if (empty($setting) || empty($setting->getValue())) {
            return $this->getAllDefaultStateResponse();
        }

        $entriesData = [];
        $dto         = SettingNotificationDto::fromJson($setting->getValue());
        foreach ($dto->getConfig() as $configDto) {
            $entriesData[] = [
                ConfigDto::KEY_NAME                => $configDto->getName(),
                ConfigDto::KEY_VALUE               => $configDto->getValue(),
                ConfigDto::KEY_ACTIVE_FOR_REMINDER => $configDto->isActiveForReminder(),
            ];
        }

        $response = BaseResponse::buildOkResponse();
        $response->setAllRecordsData($entriesData);

        return $response->toJsonResponse();
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("", name: "update", methods: [Request::METHOD_PATCH])]
    public function update(Request $request): JsonResponse
    {
        $dataArray = RequestService::tryFromJsonBody($request);
        $configs   = ArrayHandler::get($dataArray, 'configs');

        $dtos = [];
        foreach ($configs as $configData) {
            $configDto = new ConfigDto();
            $configDto->setName($configData[ConfigDto::KEY_NAME]);
            $configDto->setValue($configData[ConfigDto::KEY_VALUE]);
            $configDto->setActiveForReminder($configData[ConfigDto::KEY_ACTIVE_FOR_REMINDER]);

            $dtos[] = $configDto;
        }

        $this->settingsSaverService->saveNotificationsConfigSettings($dtos);
        $this->trackChannelsConfigured($dtos);

        return BaseResponse::buildOkResponse()->toJsonResponse();
    }

    /**
     * Tracked once per saved config. The config value itself is never sent,
     * since for the webhook it's the url which works as a credential.
     *
     * @param ConfigDto[] $configDtos
     */
    private function trackChannelsConfigured(array $configDtos): void
    {
        $activeChannelsCount = count(array_filter(
            $configDtos,
            fn(ConfigDto $configDto) => $configDto->isActiveForReminder() && !empty($configDto->getValue())
        ));

        foreach ($configDtos as $configDto) {
            $this->pendoTrackService->track(PendoTrackEventEnum::NOTIFICATION_CHANNEL_CONFIGURED, [
                'channel_name'           => $configDto->getName(),
                'is_active_for_reminder' => $configDto->isActiveForReminder(),
                'has_value'              => !empty($configDto->getValue()),
                'active_channels_count'  => $activeChannelsCount,
            ]);
        }
    }

    /**
     * @return JsonResponse
     */
    private function getAllDefaultStateResponse(): JsonResponse
    {
        $entriesData = array_map(
            fn($configName) => [
                ConfigDto::KEY_NAME                => $configName,
                ConfigDto::KEY_ACTIVE_FOR_REMINDER => false,
                ConfigDto::KEY_VALUE               => '',
            ],
            Setting::ALL_NOTIFICATION_CONFIGS
        );

        $response = BaseResponse::buildOkResponse();
        $response->setAllRecordsData($entriesData);

        return $response->toJsonResponse();
    }
}