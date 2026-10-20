<?php

namespace App\Action\Modules\System;

use App\Attribute\ModuleAttribute;
use App\Entity\FilesTags;
use App\Entity\Modules\Notes\MyNotes;
use App\Entity\System\LockedResource;
use App\Enum\StorageModuleEnum;
use App\Response\Base\BaseResponse;
use App\Services\External\PendoTrackService;
use App\Services\Module\ModulesService;
use App\Services\Module\Storage\StorageService;
use App\Services\System\LockedResourceService;
use App\Services\TypeProcessor\ArrayHandler;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/module/system/search", name: "module.system.search.")]
#[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_SYSTEM])]
class SearchAction extends AbstractController {

    public function __construct(
        private readonly StorageService $storageService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockedResourceService $lockedResourceService,
        private readonly PendoTrackService $pendoTrackService
    ) {
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route("/all", name: "get_all", methods: [Request::METHOD_GET])]
    public function getAll(Request $request): JsonResponse
    {
        $query = $request->query->get('query');
        if (empty($query)) {
            throw new LogicException("Query cannot be empty");
        }

        $entriesData = [];
        $entriesData = $this->searchInStorage($query, $entriesData);
        $entriesData = $this->searchInNotes($query, $entriesData);

        $this->trackSearchCompleted($query, $entriesData);

        $response = BaseResponse::buildOkResponse();
        $response->setAllRecordsData($entriesData);

        return $response->toJsonResponse();
    }

    /**
     * @param string $query
     * @param array  $entriesData
     *
     * @return array[]
     * @throws Exception
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function searchInStorage(string $query, array $entriesData): array
    {
        $queryParts     = explode(" ", $query);
        $cartesianQuery = [];
        ArrayHandler::cartesianProduct(input: $queryParts, result: $cartesianQuery, depth: 2, stringBetween: " ");

        $queryTagMatchSet = array_merge($queryParts, $cartesianQuery);
        foreach (StorageModuleEnum::cases() as $enum) {
            $matchingFiles = [];
            $files         = [];

            $this->storageService->getTreeData($enum, $files);
            foreach ($files as $fileData) {
                $fileName = $fileData['name'] . (!empty($fileData['ext']) ? '.' . $fileData['ext'] : '');

                $fullPath  = $fileData['dir'] . DIRECTORY_SEPARATOR . $fileName;
                $tagEntity = $this->entityManager->getRepository(FilesTags::class)->getFileTagsEntityByFileFullPath($fullPath);
                $hasTag    = false;

                if (!is_null($tagEntity)) {
                    $hasTag = $tagEntity->isAnyTagMatching($queryTagMatchSet);
                }

                if (!str_contains(strtolower($fileName), strtolower($query)) && !$hasTag) {
                    continue;
                }

                $moduleName = ModulesService::getUploadModuleNameByFilePath($fileData['dir']);
                if ($this->lockedResourceService->isResourceLocked('', LockedResource::TYPE_MODULE, $moduleName)) {
                    continue;
                }

                $matchingFiles[] = [
                    'name'        => $fileName,
                    'identifiers' => [
                        basename($fileData['dir']),
                        $fileData['dir'],
                    ],
                ];
            }

            $entriesData[$enum->value] = $matchingFiles;
        }

        return $entriesData;
    }

    /**
     * @param string $query
     * @param array  $entriesData
     *
     * @return array
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    private function searchInNotes(string $query, array $entriesData): array
    {
        if ($this->lockedResourceService->isResourceLocked('', LockedResource::TYPE_MODULE, ModulesService::MODULE_NAME_NOTES)) {
            return [];
        }

        $notes = $this->entityManager->getRepository(MyNotes::class)->findByTitle($query);

        $notesData = [];
        foreach ($notes as $note) {
            $notesData[] = [
                'name'        => $note->getTitle(),
                'identifiers' => [
                    $note->getCategory()?->getId(),
                ],
            ];
        }

        $entriesData['notes'] = $notesData;

        return $entriesData;
    }

    /**
     * Pendo: the results are only known once the backend has searched the storage modules and notes.
     * The query itself is not sent, as it can contain sensitive data
     *
     * @param string $query
     * @param array  $entriesData
     */
    private function trackSearchCompleted(string $query, array $entriesData): void
    {
        $this->pendoTrackService->trackForRequestUser('global_search_completed', function () use ($query, $entriesData): array {
            $notesCount  = count($entriesData['notes'] ?? []);
            $imagesCount = count($entriesData[StorageModuleEnum::IMAGES->value] ?? []);
            $filesCount  = count($entriesData[StorageModuleEnum::FILES->value] ?? []);
            $videosCount = count($entriesData[StorageModuleEnum::VIDEOS->value] ?? []);
            $totalCount  = $notesCount + $imagesCount + $filesCount + $videosCount;

            // files are matched either by name or by tag, so returned file without the query in its name got matched by tag
            $tagMatchedFilesCount = 0;
            foreach (StorageModuleEnum::cases() as $enum) {
                foreach ($entriesData[$enum->value] ?? [] as $fileData) {
                    if (!str_contains(strtolower($fileData['name']), strtolower($query))) {
                        $tagMatchedFilesCount++;
                    }
                }
            }

            return [
                'query_length'            => mb_strlen($query),
                'query_term_count'        => count(array_filter(explode(" ", $query), fn (string $term) => $term !== "")),
                'total_results_count'     => $totalCount,
                'notes_results_count'     => $notesCount,
                'images_results_count'    => $imagesCount,
                'files_results_count'     => $filesCount,
                'videos_results_count'    => $videosCount,
                'tag_matched_files_count' => $tagMatchedFilesCount,
                'has_results'             => $totalCount > 0,
            ];
        });
    }

}