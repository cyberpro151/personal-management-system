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
        private readonly SettingsSaver  $settingsSaverService,
        private readonly SettingsLoader $settingsLoaderService,
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

        // the config values (webhook urls) are credentials, so only their presence is sent
        $configNames         = [];
        $isActiveForReminder = false;
        $hasValue            = false;
        $activeChannelCount  = 0;
        foreach ($dtos as $dto) {
            $configNames[]       = $dto->getName();
            $isActiveForReminder = $isActiveForReminder || $dto->isActiveForReminder();
            $hasValue            = $hasValue || !empty($dto->getValue());
            if ($dto->isActiveForReminder() && !empty($dto->getValue())) {
                $activeChannelCount++;
            }
        }

        $this->pendoTrackService->track(PendoTrackEventEnum::REMINDER_NOTIFICATIONS_CONFIGURED, [
            'configName'         => implode(",", $configNames),
            'activeForReminder'  => $isActiveForReminder,
            'hasValue'           => $hasValue,
            'activeChannelCount' => $activeChannelCount,
        ]);

        return BaseResponse::buildOkResponse()->toJsonResponse();
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