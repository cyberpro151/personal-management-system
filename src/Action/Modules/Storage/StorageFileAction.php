<?php

namespace App\Action\Modules\Storage;

use App\Attribute\ModuleAttribute;
use App\Entity\FilesTags;
use App\Response\Base\BaseResponse;
use App\Services\External\PendoTrackService;
use App\Services\Module\ModulesService;
use App\Services\Module\Storage\StorageFileService;
use App\Services\Module\Storage\StorageService;
use App\Services\RequestService;
use App\Services\TypeProcessor\ArrayHandler;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route("/module/storage/file", name: "module.storage.file.")]
class StorageFileAction extends AbstractController
{
    public function __construct(
        private readonly StorageService         $storageService,
        private readonly StorageFileService     $storageFileService,
        private readonly LoggerInterface        $logger,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface    $translator,
        private readonly PendoTrackService      $pendoTrackService
    ) {
    }

    /**
     * @throws Exception
     */
    #[Route("/update", name: "rename", methods: [Request::METHOD_POST])]
    #[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_VIDEO])]
    #[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_FILES])]
    #[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_IMAGES])]
    public function update(Request $request): JsonResponse
    {
        $dataArray    = RequestService::tryFromJsonBody($request);
        $currFileName = ArrayHandler::get($dataArray, 'currFileName', allowEmpty: false);
        $newFileName  = ArrayHandler::get($dataArray, 'newFileName', allowEmpty: false);
        $dirPath      = ArrayHandler::get($dataArray, 'dirPath', allowEmpty: false);
        $tags         = ArrayHandler::get($dataArray, 'tags');

        $oldPath = $dirPath . DIRECTORY_SEPARATOR . $currFileName;
        $newPath = $dirPath . DIRECTORY_SEPARATOR . $newFileName;

        $response = $this->storageFileService->validatePreUpdate($newPath, $oldPath, $dirPath, $currFileName, $newFileName);
        if ($response instanceof BaseResponse) {
            return $response->toJsonResponse();
        }

        $isRenamed = rename($oldPath, $newPath);
        if (!$isRenamed) {
            $msg = $this->translator->trans('module.storage.common.unknownError');
            $this->logger->critical($msg, [
                'info'          => "rename function failed",
                'oldPath'       => $oldPath,
                'newPath'       => $newPath,
                'possibleError' => error_get_last(),
            ]);
            return BaseResponse::buildOkResponse($msg)->toJsonResponse();
        }

        $tagsEntity = $this->em->getRepository(FilesTags::class)->getFileTagsEntityByFileFullPath($oldPath);
        if (is_null($tagsEntity)) {
            $tagsEntity = new FilesTags();
        }

        $tagsEntity->setTags(json_encode($tags));
        $tagsEntity->setFullFilePath($newPath);

        $this->em->persist($tagsEntity);
        $this->em->flush();

        $msg = $this->translator->trans('module.storage.common.fileHasBeenUpdated');
        return BaseResponse::buildOkResponse($msg)->toJsonResponse();
    }

    /**
     * Handles files removal
     *
     * @param Request $request
     *
     * @return JsonResponse
     *
     * @throws Exception
     */
    #[Route("/remove", name: "remove", methods: [Request::METHOD_POST])]
    #[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_VIDEO])]
    #[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_FILES])]
    #[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_IMAGES])]
    public function removeFile(Request $request): JsonResponse
    {
        $dataArray = RequestService::tryFromJsonBody($request);
        $fileNames = ArrayHandler::get($dataArray, 'fileNames');
        $dirPath   = ArrayHandler::get($dataArray, 'dirPath');

        $this->storageService->ensureStorageManipulation($dirPath);

        $filePaths = [];
        foreach ($fileNames as $fileName) {
            $filePaths[] = $dirPath . DIRECTORY_SEPARATOR . $fileName;
        }

        $notRemovedPaths = $this->storageFileService->removeFiles($filePaths);
        $this->trackBulkActionCompleted('remove', $dirPath, count($filePaths), count($notRemovedPaths));

        if (!empty($notRemovedPaths)) {
            $msg = $this->translator->trans('module.storage.remove.couldNotRemoveSomeFiles') . ": " . json_encode($notRemovedPaths);
            return BaseResponse::buildBadRequestErrorResponse($msg)->toJsonResponse();
        }

        $msg = $this->translator->trans('module.storage.remove.filesHaveBeenRemoved');
        return BaseResponse::buildOkResponse($msg)->toJsonResponse();
    }

    /**
     * @throws Exception
     */
    #[Route("/move", name: "move", methods: [Request::METHOD_POST])]
    #[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_VIDEO])]
    #[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_FILES])]
    #[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_IMAGES])]
    public function moveFiles(Request $request): JsonResponse
    {
        $dataArray   = RequestService::tryFromJsonBody($request);
        $currDirPath = ArrayHandler::get($dataArray, 'currDirPath', allowEmpty: false);
        $newDirPath  = ArrayHandler::get($dataArray, 'newDirPath', allowEmpty: false);
        $filesNames  = ArrayHandler::get($dataArray, 'fileNames', allowEmpty: false);

        $this->storageService->ensureStorageManipulation($currDirPath);
        $this->storageService->ensureStorageManipulation($newDirPath);

        if (empty($filesNames)) {
            $msg = $this->translator->trans('module.storage.moveFileOrDir.filesListEmpty');
            return BaseResponse::buildBadRequestErrorResponse($msg)->toJsonResponse();
        }

        $failedFiles  = [];
        $renamedFiles = [];
        $response     = $this->storageService->moveFiles($currDirPath, $newDirPath, $filesNames, $failedFiles, $renamedFiles);

        // no file is failing when the move gets rejected upfront (e.g. target folder is the current one)
        $isRejected = !$response->isSuccess() && empty($failedFiles);
        $this->trackBulkActionCompleted('move', $currDirPath, count($filesNames), count($failedFiles), $isRejected, count($renamedFiles));

        return $response->toJsonResponse();
    }

    /**
     * Pendo: the number of actually affected files is only known once the backend has processed the files
     *
     * @param string   $action
     * @param string   $dirPath
     * @param int      $requestedCount
     * @param int      $failedCount
     * @param bool     $isRejected
     * @param int|null $renamedOnConflictCount
     */
    private function trackBulkActionCompleted(
        string $action,
        string $dirPath,
        int    $requestedCount,
        int    $failedCount,
        bool   $isRejected = false,
        ?int   $renamedOnConflictCount = null
    ): void {
        $this->pendoTrackService->trackForRequestUser('storage_files_bulk_action_completed', fn () => [
            'action'                    => $action,
            'module'                    => ModulesService::getUploadModuleNameByFilePath($dirPath) ?? 'unknown',
            'requested_count'           => $requestedCount,
            'failed_count'              => $failedCount,
            'renamed_on_conflict_count' => $renamedOnConflictCount,
            'outcome'                   => match (true) {
                $isRejected                    => 'rejected',
                $failedCount === 0             => 'success',
                $failedCount < $requestedCount => 'partial_failure',
                default                        => 'failed',
            },
        ]);
    }

}