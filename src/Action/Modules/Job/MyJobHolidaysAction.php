<?php


namespace App\Action\Modules\Job;


use App\Attribute\ModuleAttribute;
use App\Entity\Modules\Job\MyJobHolidays;
use App\Entity\Modules\Job\MyJobHolidaysPool;
use App\Enum\PendoTrackEventEnum;
use App\Response\Base\BaseResponse;
use App\Services\External\PendoTrackService;
use App\Services\Module\ModulesService;
use App\Services\RequestService;
use App\Services\TypeProcessor\ArrayHandler;
use App\Services\Validation\EntityValidatorService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/module/job/holidays/days-spent", name: "module.job.holidays.days_spent.")]
#[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_JOB])]
class MyJobHolidaysAction extends AbstractController {

    public function __construct(
        private readonly EntityValidatorService $entityValidator,
        private readonly EntityManagerInterface $em,
        private readonly PendoTrackService      $pendoTrackService
    ) {
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("", name: "new", methods: [Request::METHOD_POST])]
    public function new(Request $request): JsonResponse
    {
        return $this->createOrUpdate($request)->toJsonResponse();
    }

    /**
     * @return JsonResponse
     */
    #[Route("/all", name: "get_all", methods: [Request::METHOD_GET])]
    public function getAll(): JsonResponse
    {
        $holidays    = $this->em->getRepository(MyJobHolidays::class)->getAllNotDeleted();
        $entriesData = [];
        foreach ($holidays as $holiday) {
            $entriesData[] = [
                'id'          => $holiday->getId(),
                'year'        => $holiday->getYear(),
                'daysSpent'   => $holiday->getDaysSpent(),
                'information' => $holiday->getInformation(),
            ];
        }

        $response = BaseResponse::buildOkResponse();
        $response->setAllRecordsData($entriesData);

        return $response->toJsonResponse();
    }

    /**
     * @param MyJobHolidays $holidayEntry
     * @param Request     $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("/{id}", name: "update", methods: [Request::METHOD_PATCH])]
    public function update(MyJobHolidays $holidayEntry, Request $request): JsonResponse
    {
        return $this->createOrUpdate($request, $holidayEntry)->toJsonResponse();
    }

    /**
     * @param MyJobHolidays $holidayEntry
     *
     * @return JsonResponse
     */
    #[Route("/{id}", name: "remove", methods: [Request::METHOD_DELETE])]
    public function remove(MyJobHolidays $holidayEntry): JsonResponse
    {
        $holidayEntry->setDeleted(true);
        $this->em->persist($holidayEntry);
        $this->em->flush();

        return BaseResponse::buildOkResponse()->toJsonResponse();
    }

    /**
     * @param Request            $request
     * @param MyJobHolidays|null $holidayEntry
     *
     * @return BaseResponse
     * @throws Exception
     */
    private function createOrUpdate(Request $request, ?MyJobHolidays $holidayEntry = null): BaseResponse
    {
        $isNew = is_null($holidayEntry);
        if ($isNew) {
            $holidayEntry = new MyJobHolidays();
        }

        $dataArray   = RequestService::tryFromJsonBody($request);
        $year        = ArrayHandler::get($dataArray, 'year', allowEmpty: false);
        $days        = ArrayHandler::get($dataArray, 'daysSpent', allowEmpty: false);
        $information = ArrayHandler::get($dataArray, 'information', allowEmpty: false);

        $holidayEntry->setYear($year);
        $holidayEntry->setDaysSpent($days);
        $holidayEntry->setInformation($information);

        $action = $isNew ? EntityValidatorService::ACTION_CREATE : EntityValidatorService::ACTION_UPDATE;
        $validationResult = $this->entityValidator->handleValidation($holidayEntry, $action);

        if (!$validationResult->isValid()) {
            $this->trackHolidayDaysRecorded($holidayEntry, $isNew, "rejected");
            return BaseResponse::buildBadRequestErrorResponse($validationResult->concatFailedValidations());
        }

        $this->em->persist($holidayEntry);
        $this->em->flush();
        $this->trackHolidayDaysRecorded($holidayEntry, $isNew, "accepted");

        return BaseResponse::buildOkResponse();
    }

    /**
     * Days left in pool are calculated from the saved state, so for rejected entry it's the state without the change.
     * The free text `information` is never sent.
     *
     * @param MyJobHolidays $holidayEntry
     * @param bool          $isNew
     * @param string        $validationStatus
     *
     * @throws Exception
     */
    private function trackHolidayDaysRecorded(MyJobHolidays $holidayEntry, bool $isNew, string $validationStatus): void
    {
        $daysLeftInPool = $this->em->getRepository(MyJobHolidaysPool::class)->getDaysInPoolLeftForYear((string) $holidayEntry->getYear());

        $this->pendoTrackService->track(PendoTrackEventEnum::HOLIDAY_DAYS_RECORDED, [
            'year'              => $holidayEntry->getYear(),
            'days_spent'        => $holidayEntry->getDaysSpent(),
            'days_left_in_pool' => $daysLeftInPool,
            'is_new_entry'      => $isNew,
            'validation_status' => $validationStatus,
        ]);
    }

}