<?php


namespace App\Action\Modules\Payments;


use App\Attribute\ModuleAttribute;
use App\Entity\Modules\Payments\MyPaymentsMonthly;
use App\Entity\Modules\Payments\MyPaymentsSettings;
use App\Enum\PendoTrackEventEnum;
use App\Response\Base\BaseResponse;
use App\Services\External\PendoTrackService;
use App\Services\Module\ModulesService;
use App\Services\RequestService;
use App\Services\TypeProcessor\ArrayHandler;
use App\Traits\ExceptionLoggerAwareTrait;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route("/module/payment/monthly", name: "module.payment.monthly.")]
#[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_PAYMENTS])]
class MyPaymentsMonthlyAction extends AbstractController {

    use ExceptionLoggerAwareTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
        private readonly TranslatorInterface    $translator,
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
        $allPayments = $this->em->getRepository(MyPaymentsMonthly::class)->findBy(['deleted' => 0], ['date' => "DESC"]);
        $entriesData = [];
        foreach ($allPayments as $payment) {
            $entriesData[] = [
                'id'          => $payment->getId(),
                'date'        => $payment->getDate()->format('Y-m-d'),
                'money'       => $payment->getMoney() ?? 0,
                'description' => $payment->getDescription() ?? '',
                'typeName'    => $payment->getType()?->getValue() ?? '',
                'typeId'      => $payment->getType()?->getId(),
            ];
        }

        $response = BaseResponse::buildOkResponse();
        $response->setAllRecordsData($entriesData);

        return $response->toJsonResponse();
    }

    /**
     * @param MyPaymentsMonthly $payment
     * @param Request           $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("/{id}", name: "update", methods: [Request::METHOD_PATCH])]
    public function update(MyPaymentsMonthly $payment, Request $request): JsonResponse
    {
        return $this->createOrUpdate($request, $payment)->toJsonResponse();
    }

    /**
     * @param MyPaymentsMonthly $payment
     *
     * @return JsonResponse
     */
    #[Route("/{id}", name: "remove", methods: [Request::METHOD_DELETE])]
    public function remove(MyPaymentsMonthly $payment): JsonResponse
    {
        $payment->setDeleted(true);
        $this->em->persist($payment);
        $this->em->flush();

        return BaseResponse::buildOkResponse()->toJsonResponse();
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    #[Route("/import", name: "import", methods: [Request::METHOD_POST])]
    public function import(Request $request): JsonResponse
    {
        $rowsData = $request->getContent();
        $contentArray = json_decode($rowsData, true);
        $dataArray = $contentArray['data'] ?? [];

        if (empty($dataArray)) {
            $this->trackImportCompleted("no_data", $dataArray);

            $msg = $this->translator->trans('module.monthly_payments.import.message.noImportData');
            return BaseResponse::buildBadRequestErrorResponse($msg)->toJsonResponse();
        }

        try {
            $this->em->beginTransaction();
            foreach ($dataArray as $row) {
                $payment = new MyPaymentsMonthly();
                $this->createOrUpdateFromDataArray($row, $payment);
            }
            $this->em->commit();
        } catch (Exception $e) {
            $this->em->rollback();
            $this->logException($e);
            $this->trackImportCompleted("failure", $dataArray);

            $msg = $this->translator->trans('module.monthly_payments.import.message.fail');
            return BaseResponse::buildInternalServerErrorResponse($msg)->toJsonResponse();
        }

        $this->trackImportCompleted("success", $dataArray);

        $msg = $this->translator->trans('module.monthly_payments.import.message.success');
        return BaseResponse::buildOkResponse($msg)->toJsonResponse();
    }

    /**
     * Tracks the import outcome, only the counts and the dates range are sent (never descriptions or amounts)
     *
     * @param string $importStatus
     * @param mixed  $rows
     */
    private function trackImportCompleted(string $importStatus, mixed $rows): void
    {
        $rows    = is_array($rows) ? $rows : [];
        $typeIds = [];
        $dates   = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (isset($row['typeId']) && is_scalar($row['typeId'])) {
                $typeIds[(string) $row['typeId']] = true;
            }

            if (!empty($row['date']) && is_string($row['date'])) {
                try {
                    $dates[] = (new DateTime($row['date']))->format('Y-m-d');
                } catch (Exception) {
                    // not parsable date is skipped, the import itself takes care of it
                }
            }
        }

        $this->pendoTrackService->track(PendoTrackEventEnum::MONTHLY_PAYMENTS_IMPORT_COMPLETED, [
            'import_status'               => $importStatus,
            'rows_count'                  => count($rows),
            'distinct_payment_type_count' => count($typeIds),
            'earliest_payment_date'       => empty($dates) ? null : min($dates),
            'latest_payment_date'         => empty($dates) ? null : max($dates),
        ]);
    }

    /**
     * @param Request                $request
     * @param MyPaymentsMonthly|null $payment
     *
     * @return BaseResponse
     * @throws Exception
     */
    private function createOrUpdate(Request $request, ?MyPaymentsMonthly $payment = null): BaseResponse
    {
        if (!$payment) {
            $payment = new MyPaymentsMonthly();
        }

        $dataArray = RequestService::tryFromJsonBody($request);

        return $this->createOrUpdateFromDataArray($dataArray, $payment);
    }

    /**
     * @param array                  $dataArray
     * @param MyPaymentsMonthly|null $payment
     *
     * @return BaseResponse
     * @throws \App\Exception\MissingDataException
     * @throws \DateMalformedStringException
     */
    public function createOrUpdateFromDataArray(array $dataArray, ?MyPaymentsMonthly $payment): BaseResponse
    {
        $dateString  = ArrayHandler::get($dataArray, 'date', allowEmpty: false);
        $description = ArrayHandler::get($dataArray, 'description', allowEmpty: false);
        $money       = ArrayHandler::get($dataArray, 'money', allowEmpty: false);
        $typeId      = ArrayHandler::get($dataArray, 'typeId', allowEmpty: false);

        $type = $this->em->getRepository(MyPaymentsSettings::class)->findPaymentType($typeId);
        if (!$type) {
            throw new Exception("No payment type setting found for id: {$type}");
        }

        $payment->setDate(new DateTime($dateString));
        $payment->setDescription(trim($description));
        $payment->setMoney((float)abs($money));
        $payment->setType($type);

        $this->em->persist($payment);
        $this->em->flush();

        return BaseResponse::buildOkResponse();
    }

}