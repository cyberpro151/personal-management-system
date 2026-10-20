<?php

namespace App\Action\Modules\System\Settings\Modules;

use App\Attribute\ModuleAttribute;
use App\DTO\Settings\Lock\Subsettings\SettingsModuleLockDTO;
use App\Response\Base\BaseResponse;
use App\Services\External\PendoTrackService;
use App\Services\Module\ModulesService;
use App\Services\RequestService;
use App\Services\Settings\SettingsLockModuleService;
use App\Services\Settings\SettingsSaver;
use App\Services\System\EnvReader;
use App\Services\System\LockedResourceService;
use App\Services\TypeProcessor\ArrayHandler;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/module/system/settings/modules/lock", name: "module.system.settings.modules.lock")]
#[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_SYSTEM])]
class ModulesLockAction extends AbstractController {

    /**
     * @param SettingsSaver             $settingsSaverService
     * @param SettingsLockModuleService $settingsLockModuleService
     * @param LockedResourceService     $lockedResourceService
     * @param PendoTrackService         $pendoTrackService
     */
    public function __construct(
        private readonly SettingsSaver  $settingsSaverService,
        private readonly SettingsLockModuleService $settingsLockModuleService,
        private readonly LockedResourceService $lockedResourceService,
        private readonly PendoTrackService $pendoTrackService
    ) {
    }

    /**
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("/all", name: "get_all", methods: [Request::METHOD_GET])]
    public function getAll(): JsonResponse
    {
        $dtos = $this->settingsLockModuleService->getSettingsModuleLockDtos();
        
        $entriesData = [];
        foreach ($dtos as $dto) {
            $entriesData[] = [
                SettingsModuleLockDTO::KEY_NAME      => $dto->getName(),
                SettingsModuleLockDTO::KEY_IS_LOCKED => $dto->isLocked(),
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
        if (EnvReader::isDemo()) {
            return BaseResponse::buildDemoDisabledLogicResponse()->toJsonResponse();
        }

        if ($this->lockedResourceService->isSystemLocked()) {
            return BaseResponse::buildAccessDeniedResponse()->toJsonResponse();
        }

        $dataArray   = RequestService::tryFromJsonBody($request);
        $moduleLocks = ArrayHandler::get($dataArray, 'moduleLocks');

        $dtos = [];
        foreach ($moduleLocks as $lockData) {
            $lockDto = new SettingsModuleLockDTO();
            $lockDto->setName($lockData[SettingsModuleLockDTO::KEY_NAME]);
            $lockDto->setLocked($lockData[SettingsModuleLockDTO::KEY_IS_LOCKED]);

            $dtos[] = $lockDto;
        }

        $this->settingsSaverService->saveModulesLockSettings($dtos);

        $lockedModules = [];
        foreach ($dtos as $lockDto) {
            if ($lockDto->isLocked()) {
                $lockedModules[] = $lockDto->getName();
            }
        }

        $this->pendoTrackService->track('module_lock_settings_updated', [
            'lockedModules'        => $lockedModules,
            'lockedModulesCount'   => count($lockedModules),
            'unlockedModulesCount' => count($dtos) - count($lockedModules),
        ]);

        return BaseResponse::buildOkResponse()->toJsonResponse();
    }
}