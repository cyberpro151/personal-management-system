<?php

namespace App\Action\File;

use App\DTO\Internal\Upload\UploadConfigurationDTO;
use App\Enum\File\UploadStatusEnum;
use App\Enum\PendoTrackEventEnum;
use App\Exception\File\UploadValidationException;
use App\Response\Base\BaseResponse;
use App\Response\UploadedFile\UploadConfigurationResponse;
use App\Response\UploadedFile\UploadResponse;
use App\Services\External\PendoTrackService;
use App\Services\Files\PathService;
use App\Services\Files\Upload\FileUploadConfigurator;
use App\Services\Files\Upload\FileUploadService;
use App\Services\Files\Upload\TemporaryFileHandlerService;
use App\Services\RequestService;
use App\Services\System\EnvReader;
use App\Services\TypeProcessor\ArrayHandler;
use App\Traits\ExceptionLoggerAwareTrait;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use TypeError;

/**
 * Provides endpoints for uploaded file related logic
 */
class UploadedFileAction extends AbstractController
{
    use ExceptionLoggerAwareTrait;

    public function __construct(
        private readonly FileUploadService           $fileUploadService,
        private readonly LoggerInterface             $logger,
        private readonly FileUploadConfigurator      $fileUploadConfigurator,
        private readonly TranslatorInterface         $translator,
        private readonly TemporaryFileHandlerService $temporaryFileHandlerService,
        private readonly PendoTrackService           $pendoTrackService
    ) {
    }

    /**
     * Handles the file upload.
     * Keep in mind that trailing slash in route is A MUST, had already issues with that.
     *
     * Info: upload won't work if the route is set to "/upload/"
     *
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("/upload/send/", name: "upload.send", methods: [Request::METHOD_POST, Request::METHOD_OPTIONS])]
    public function upload(Request $request): JsonResponse
    {
        $dataArray       = RequestService::tryFromJsonBody($request);
        $extraData       = ArrayHandler::get($dataArray, 'extraData', true, []);
        $encodedContent  = ArrayHandler::get($dataArray, 'fileContent');
        $fileName        = ArrayHandler::get($dataArray, 'fileName');
        $fileSizeBytes   = ArrayHandler::get($dataArray, 'fileSize');
        $uploadConfigId  = ArrayHandler::get($dataArray, 'uploadConfigId');
        $userDefinedName = ArrayHandler::get($dataArray, 'userDefinedName', true);
        $tags            = ArrayHandler::get($dataArray, 'tags', true, []);
        $uploadDir       = $extraData['uploadDir'] ?? null;

        $decodedFileContent = base64_decode($encodedContent);

        $errorMessage = $this->translator->trans('file.upload.msg.uploadError');
        $isError      = false;
        $errorType    = null;
        $status       = UploadStatusEnum::SUCCESS;
        $response     = UploadResponse::buildOkResponse();

        try {
            $usedFileName = $fileName;
            if (!empty($userDefinedName)) {
                $usedFileName = $userDefinedName . "." . pathinfo($fileName, PATHINFO_EXTENSION);
            }

            $uploadedTmpFile = $this->temporaryFileHandlerService->saveFile($decodedFileContent, $fileName);
            $filePath = $this->fileUploadService->handleUpload($uploadedTmpFile, $uploadConfigId, $usedFileName, $fileSizeBytes, $uploadDir);
            $this->fileUploadService->tagFile($filePath, $tags);

            $response->setPublicPath($filePath);
            $response->setLocalFileName($usedFileName);
            $response->setUploadId($uploadConfigId);
            $response->setMessage($this->translator->trans('file.upload.msg.uploadSuccess'));

            if (EnvReader::isDemo()) {
                $response->setMessage("Demo: file has been uploaded but for security reasons it has been removed.");
                unlink($filePath);
            }
        } catch (UploadValidationException) {
            $status    = UploadStatusEnum::ERROR;
            $errorType = "validation";
            $isError   = true;
        } catch (Exception|TypeError $e) {
            $status    = UploadStatusEnum::ERROR;
            $errorType = "unexpected";
            $this->logException($e);
            $isError = true;
        }

        if ($isError) {
            $response->prefillBaseFieldsForBadRequestResponse();
            $response->setMessage($errorMessage);
            if (isset($uploadedTmpFile)) {
                unlink($uploadedTmpFile->getRealPath());
            }
        }

        $response->setStatus($status->value);

        // the upload outcome is final here (validations, moving out of the temp dir and tagging are done)
        $this->pendoTrackService->track(PendoTrackEventEnum::FILE_UPLOAD_COMPLETED, [
            'upload_status'   => $status->value,
            'error_type'      => $errorType,
            'upload_target'   => is_string($uploadConfigId) ? $this->fileUploadConfigurator->getUploadTarget($uploadConfigId) : "unknown",
            'file_extension'  => $this->getFileExtensionForTracking($fileName),
            'file_size_bytes' => is_numeric($fileSizeBytes) ? (int) $fileSizeBytes : null,
            'tag_count'       => is_array($tags) ? count($tags) : 0,
            'has_custom_name' => !empty($userDefinedName),
            'is_demo'         => EnvReader::isDemo(),
        ]);

        return $response->toJsonResponse();
    }

    /**
     * Returns the serialized {@see UploadConfigurationDTO} from {@see FileUploadConfigurator}
     *
     * @param string $id
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("/upload/get-configuration/{id}", name: "upload.get.configuration", methods: [Request::METHOD_GET, Request::METHOD_OPTIONS])]
    public function getUploadConfiguration(string $id): JsonResponse
    {
        $configurationDto = $this->fileUploadConfigurator->getConfiguration($id);
        $response         = UploadConfigurationResponse::buildOkResponse();
        $response->setConfiguration($configurationDto);

        return $response->toJsonResponse();
    }

    /**
     * Will remove given file
     *
     * @param string $filePath
     *
     * @return JsonResponse
     */
    #[Route("/upload/delete/{filePath}", name: "upload.delete", methods: [Request::METHOD_GET, Request::METHOD_OPTIONS])]
    public function delete(string $filePath): JsonResponse
    {
        if (!file_exists($filePath)) {
            $msg = $this->translator->trans('file.msg.fileDoesNotExist');
            return BaseResponse::buildBadRequestErrorResponse($msg)->toJsonResponse();
        }

        try {
            PathService::validatePathSafety($filePath);
        } catch (Throwable) {
            $msg = $this->translator->trans('file.msg.unsafePathCancelled');
            return BaseResponse::buildBadRequestErrorResponse($msg)->toJsonResponse();
        }

        $message   = $this->translator->trans('file.msg.fileRemoved');
        $response  = BaseResponse::buildOkResponse();
        $response->setMessage($message);

        $isRemoved = $this->fileUploadService->deleteFile($filePath);
        if (!$isRemoved) {
            $message = $this->translator->trans('file.msg.couldNotRemove');
            $response->prefillBaseFieldsForBadRequestResponse();
            $response->setMessage($message);
        }

        return $response->toJsonResponse();
    }

    /**
     * Returns only the extension, as the file name itself can contain personal data,
     * anything that does not look like a file extension is skipped.
     *
     * @param mixed $fileName
     *
     * @return string|null
     */
    private function getFileExtensionForTracking(mixed $fileName): ?string
    {
        if (!is_string($fileName)) {
            return null;
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!preg_match("#^[a-z0-9_-]{1,16}$#", $extension)) {
            return null;
        }

        return $extension;
    }

}