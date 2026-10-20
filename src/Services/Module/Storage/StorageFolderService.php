<?php

namespace App\Services\Module\Storage;

use App\Entity\FilesTags;
use App\Entity\Modules\ModuleData;
use App\Entity\System\LockedResource;
use App\Response\Base\BaseResponse;
use App\Services\External\PendoTrackService;
use App\Services\Files\PathService;
use App\Services\Module\ModulesService;
use App\Traits\ExceptionLoggerAwareTrait;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\Translation\TranslatorInterface;

class StorageFolderService
{
    use ExceptionLoggerAwareTrait;

    public function __construct(
        private readonly TranslatorInterface    $translator,
        private readonly LoggerInterface        $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly PendoTrackService      $pendoTrackService
    ) {
    }

    /**
     * @param string $existingDirPath
     * @param string $newDirName
     * @param string $newDirPath
     *
     * @return BaseResponse|null
     */
    public function validateCreateAndRename(string $existingDirPath, string $newDirName, string $newDirPath): ?BaseResponse
    {
        if (empty($newDirName)) {
            $msg = $this->translator->trans('module.storage.common.dirNameIsEmpty');
            return BaseResponse::buildBadRequestErrorResponse($msg);
        }

        if (empty($existingDirPath)) {
            $msg = $this->translator->trans('module.storage.common.existingDirPath');
            return BaseResponse::buildBadRequestErrorResponse($msg);
        }

        if (!file_exists($existingDirPath)) {
            $msg = $this->translator->trans('module.storage.common.folderNotExist') . $existingDirPath;
            return BaseResponse::buildBadRequestErrorResponse($msg);
        }

        if (file_exists($newDirPath) && is_dir($newDirPath)) {
            $msg = $this->translator->trans('module.storage.common.dirExists');
            return BaseResponse::buildBadRequestErrorResponse($msg);
        }

        if (!is_writable($existingDirPath)) {
            $msg = $this->translator->trans('module.storage.common.dirNotWritable') . " {$existingDirPath}";
            return BaseResponse::buildBadRequestErrorResponse($msg);
        }

        return null;
    }

    /**
     * Handles moving or copying folder, or its content around
     *
     * @param string $oldDirPath
     * @param string $newDirParentPath
     * @param bool   $moveDir
     * @param string $targetModuleName
     *
     * @return BaseResponse
     * @throws Exception
     */
    public function moveOrCopyFolder(string $oldDirPath, string $newDirParentPath, bool $moveDir, string $targetModuleName): BaseResponse
    {
        $outcomeData = [];
        $response    = $this->handleMoveOrCopyFolder($oldDirPath, $newDirParentPath, $moveDir, $targetModuleName, $outcomeData);

        $this->trackMoveOrCopyCompleted($oldDirPath, $newDirParentPath, $moveDir, $outcomeData);

        return $response;
    }

    /**
     * @param string $oldDirPath
     * @param string $newDirParentPath
     * @param bool   $moveDir
     * @param string $targetModuleName
     * @param array  $outcomeData - gets filled with the outcome details
     *
     * @return BaseResponse
     * @throws Exception
     */
    private function handleMoveOrCopyFolder(string $oldDirPath, string $newDirParentPath, bool $moveDir, string $targetModuleName, array &$outcomeData): BaseResponse
    {
        if (str_contains($newDirParentPath, $oldDirPath)) {
            $outcomeData['outcome'] = 'rejected_target_inside_source';
            return BaseResponse::buildBadRequestErrorResponse($this->translator->trans('module.storage.moveFileOrDir.parentIntoDirNotAllowed'));
        }

        if ($moveDir) {
            if (in_array($oldDirPath, PathService::getAllStorageBaseDirs())) {
                $outcomeData['outcome'] = 'rejected_base_folder';
                $msg = $this->translator->trans('module.storage.moveOrCopyFolder.cannotMoveBaseDir');
                return BaseResponse::buildBadRequestErrorResponse($msg);
            }

            $newDirPath = $newDirParentPath . DIRECTORY_SEPARATOR . basename($oldDirPath);
            if (file_exists($newDirPath)) {
                $outcomeData['outcome']                 = 'rejected_name_exists';
                $outcomeData['name_conflicts_detected'] = true;
                return BaseResponse::buildBadRequestErrorResponse($this->translator->trans('module.storage.moveFileOrDir.folderWithThisNameAlreadyExist'));
            }

            return $this->moveFolder($oldDirPath, $newDirPath, $targetModuleName, $outcomeData);
        }

        return $this->copyFilesFromDir($oldDirPath, $newDirParentPath, $outcomeData);
    }

    /**
     * Pendo: the outcome is only known once the filesystem and database work is done
     *
     * @param string $oldDirPath
     * @param string $newDirParentPath
     * @param bool   $moveDir
     * @param array  $outcomeData
     */
    private function trackMoveOrCopyCompleted(string $oldDirPath, string $newDirParentPath, bool $moveDir, array $outcomeData): void
    {
        $this->pendoTrackService->trackForRequestUser('storage_folder_move_or_copy_completed', function () use ($oldDirPath, $newDirParentPath, $moveDir, $outcomeData): array {
            $sourceModule = ModulesService::getUploadModuleNameByFilePath($oldDirPath) ?? 'unknown';
            $targetModule = ModulesService::getUploadModuleNameByFilePath($newDirParentPath) ?? 'unknown';

            return [
                'operation'               => $moveDir ? 'move' : 'copy',
                'source_module'           => $sourceModule,
                'target_module'           => $targetModule,
                'is_cross_module'         => $sourceModule !== $targetModule,
                'copied_files_count'      => $outcomeData['copied_files_count'] ?? 0,
                'name_conflicts_detected' => $outcomeData['name_conflicts_detected'] ?? false,
                'outcome'                 => $outcomeData['outcome'] ?? 'unknown',
            ];
        });
    }

    /**
     * @param string $oldDirPath
     * @param string $newDirPath
     * @param string $targetModuleName
     * @param array  $outcomeData - gets filled with the outcome details
     *
     * @return BaseResponse
     */
    private function moveFolder(string $oldDirPath, string $newDirPath, string $targetModuleName, array &$outcomeData): BaseResponse
    {
        if (!rename($oldDirPath, $newDirPath)) {
            $msg = $this->translator->trans('module.storage.moveFileOrDir.unknownError');
            $this->logger->critical($msg, [
                'info'          => "rename function failed",
                'oldDirPath'    => $oldDirPath,
                'newDirPath'    => $newDirPath,
                'possibleError' => error_get_last(),
            ]);

            $outcomeData['outcome'] = 'move_failed';
            return BaseResponse::buildInternalServerErrorResponse($msg);
        }

        try {
            $this->entityManager->beginTransaction();
            $this->entityManager->getRepository(FilesTags::class)->updateFilePathByFolderPathChange($oldDirPath, $newDirPath);

            // skipping module match here, it's not really needed for path based entry
            $data = $this->entityManager->getRepository(ModuleData::class)->findOneBy(['recordIdentifier' => $oldDirPath]);
            if (!is_null($data)) {
                $data->setRecordIdentifier($newDirPath);
                $this->entityManager->persist($data);
            }

            $lock = $this->entityManager->getRepository(LockedResource::class)->findByDirectoryLocation($oldDirPath);
            if (!is_null($lock)) {
                $lock->setRecord($newDirPath);
                $lock->setTarget($targetModuleName);
                $this->entityManager->persist($lock);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (Exception $e) {
            $this->entityManager->rollback();
            $this->logException($e, [
                'info' => "something went wrong during db updates"
            ]);

            if (!rename($newDirPath, $oldDirPath)) {
                $msg = $this->translator->trans('module.storage.moveFileOrDir.unknownError');
                $this->logger->critical($msg, [
                    'info'          => "could not move back folder, from {$newDirPath} to {$oldDirPath}",
                    'possibleError' => error_get_last(),
                ]);

                $outcomeData['outcome'] = 'db_failure_not_reverted';
                $msg = $this->translator->trans('module.storage.moveFileOrDir.folderMovedDatabaseChangesFailed');
                return BaseResponse::buildInternalServerErrorResponse($msg);
            }

            $outcomeData['outcome'] = 'reverted_after_db_failure';
            $msg = $this->translator->trans('module.storage.moveFileOrDir.folderCouldNotBeMovedReverted');
            return BaseResponse::buildInternalServerErrorResponse($msg);
        }

        $outcomeData['outcome'] = 'moved';
        return BaseResponse::buildOkResponse($this->translator->trans('module.storage.moveFileOrDir.folderHaveBeenMoved'));
    }

    /**
     * @param string $oldDirPath
     * @param string $newDirPath
     * @param array  $outcomeData - gets filled with the outcome details
     *
     * @return BaseResponse
     */
    public function copyFilesFromDir(string $oldDirPath, string $newDirPath, array &$outcomeData = []): BaseResponse
    {
        $copiedFilePaths = [];
        $someFilesExisted = false;
        foreach ((new Finder())->files()->in($oldDirPath)->depth(0) as $file) {
            $newFilePath = $newDirPath . DIRECTORY_SEPARATOR . $file->getFilename();
            if (file_exists($newFilePath)) {
                $postfix = "_" . (new DateTime())->format('Y_m_d_H_i_s');
                $newFilePath = $newDirPath . DIRECTORY_SEPARATOR . $file->getFilenameWithoutExtension() . $postfix . "." . $file->getExtension();
                $someFilesExisted = true;
            }

            $tags = $this->entityManager->getRepository(FilesTags::class)->findOneBy(['fullFilePath' => $file->getPathname()]);
            if (!is_null($tags)) {
                $clonedEntity = clone $tags;
                $clonedEntity->setId(null);
                $clonedEntity->setFullFilePath($newFilePath);
                $this->entityManager->persist($clonedEntity);
            }

            $isCopied = copy($file->getPathname(), $newFilePath);
            if ($isCopied) {
                $copiedFilePaths[] = $newFilePath;
                continue;
            }

            $this->logger->critical("Could not copy {$file->getPathname()} to {$newFilePath}", [
                'possibleError' => error_get_last(),
            ]);

            foreach ($copiedFilePaths as $filePath) {
                if (!unlink($filePath)) {
                    $this->logger->critical("Could not remove copied file: {$filePath}", [
                        'possibleError' => error_get_last(),
                    ]);
                }
            }

            $outcomeData['outcome']                 = 'copy_failed_reverted';
            $outcomeData['name_conflicts_detected'] = $someFilesExisted;
            return BaseResponse::buildOkResponse($this->translator->trans('module.storage.common.errorReverted'));
        }

        // skipping module match here, it's not really needed for path based entry
        $data = $this->entityManager->getRepository(ModuleData::class)->findOneBy(['recordIdentifier' => $oldDirPath]);
        if (!is_null($data)) {
            $clonedEntity = clone $data;
            $clonedEntity->setId(null);
            $clonedEntity->setRecordIdentifier($newDirPath);

            $this->entityManager->persist($data);
        }

        $this->entityManager->flush();

        $outcomeData['copied_files_count']      = count($copiedFilePaths);
        $outcomeData['name_conflicts_detected'] = $someFilesExisted;
        if ($someFilesExisted) {
            $outcomeData['outcome'] = 'copied_with_renamed_duplicates';
            return BaseResponse::buildOkResponse($this->translator->trans('module.storage.moveFileOrDir.filesHaveBeenCopiedButExisted'));
        }

        $outcomeData['outcome'] = 'copied';
        return BaseResponse::buildOkResponse($this->translator->trans('module.storage.moveFileOrDir.filesHaveBeenCopied'));
    }
}