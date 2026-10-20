<?php


namespace App\Action\Modules\Goals;


use App\Attribute\ModuleAttribute;
use App\Entity\Modules\Goals\MyGoalsPayments;
use App\Repository\Modules\Goals\MyGoalsPaymentsRepository;
use App\Response\Base\BaseResponse;
use App\Services\External\PendoTrackService;
use App\Services\Module\ModulesService;
use App\Services\RequestService;
use App\Services\TypeProcessor\ArrayHandler;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route("/module/my-goals-payments", name: "module.my_goals_payments.")]
#[ModuleAttribute(values: ["name" => ModulesService::MODULE_NAME_GOALS])]
class GoalsPaymentsAction extends AbstractController {

    public function __construct(
        private readonly MyGoalsPaymentsRepository $goalsPaymentsRepository,
        private readonly EntityManagerInterface $em,
        private readonly PendoTrackService $pendoTrackService
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
        $this->createOrUpdate($request);
        return BaseResponse::buildOkResponse()->toJsonResponse();
    }

    /**
     * @return JsonResponse
     */
    #[Route("/all", name: "get_all", methods: [Request::METHOD_GET])]
    public function getAll(): JsonResponse
    {
        $allPayments = $this->goalsPaymentsRepository->getAllNotDeleted();
        $entriesData = [];
        foreach ($allPayments as $payment) {
            $entriesData[] = $payment->asFrontendData();
        }

        $response = BaseResponse::buildOkResponse();
        $response->setAllRecordsData($entriesData);

        return $response->toJsonResponse();
    }

    /**
     * @param MyGoalsPayments $payment
     * @param Request         $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("/{id}", name: "update", methods: [Request::METHOD_PATCH])]
    public function update(MyGoalsPayments $payment, Request $request): JsonResponse
    {
        $this->createOrUpdate($request, $payment);
        return BaseResponse::buildOkResponse()->toJsonResponse();
    }

    /**
     * @param MyGoalsPayments $payment
     *
     * @return JsonResponse
     */
    #[Route("/{id}", name: "remove", methods: [Request::METHOD_DELETE])]
    public function remove(MyGoalsPayments $payment): JsonResponse
    {
        $payment->setDeleted(true);
        $this->em->persist($payment);
        $this->em->flush();

        return BaseResponse::buildOkResponse()->toJsonResponse();
    }

    /**
     * @param Request              $request
     * @param MyGoalsPayments|null $payment
     *
     * @throws Exception
     */
    private function createOrUpdate(Request $request, ?MyGoalsPayments $payment = null): void
    {
        if (!$payment) {
            $payment = new MyGoalsPayments();
        }

        $wasGoalReached = $this->isGoalReached($payment);

        $dataArray       = RequestService::tryFromJsonBody($request);
        $name            = ArrayHandler::get($dataArray, 'name', allowEmpty: false);
        $goal            = ArrayHandler::get($dataArray, 'goal', allowEmpty: false);
        $collected       = ArrayHandler::get($dataArray, 'collected', allowEmpty: false);
        $showOnDashboard = ArrayHandler::get($dataArray, 'isForDashboard');
        $startString     = ArrayHandler::get($dataArray, 'start', allowEmpty: false);
        $endString       = ArrayHandler::get($dataArray, 'end', allowEmpty: false);

        $start = new DateTime($startString);
        $end   = new DateTime($endString);

        $payment->setName($name);
        $payment->setMoneyGoal($goal);
        $payment->setMoneyCollected($collected);
        $payment->setCollectionStartDate($start);
        $payment->setDeadline($end);
        $payment->setDisplayOnDashboard($showOnDashboard);

        $this->em->persist($payment);
        $this->em->flush();

        // Pendo: only the saved values tell if the goal got reached, tracking the first save that reached it
        if (!$wasGoalReached && $this->isGoalReached($payment)) {
            $this->trackGoalReached($payment);
        }
    }

    /**
     * @param MyGoalsPayments $payment
     *
     * @return bool
     */
    private function isGoalReached(MyGoalsPayments $payment): bool
    {
        return !is_null($payment->getMoneyGoal())
            && !is_null($payment->getMoneyCollected())
            && $payment->getMoneyCollected() >= $payment->getMoneyGoal();
    }

    /**
     * Money amounts are not sent on purpose, only the timing relative to the start date and deadline
     *
     * @param MyGoalsPayments $payment
     */
    private function trackGoalReached(MyGoalsPayments $payment): void
    {
        $this->pendoTrackService->trackForRequestUser('savings_goal_reached', function () use ($payment): array {
            $today              = new DateTime('today');
            $daysBeforeDeadline = (int)$today->diff($payment->getDeadline())->format('%r%a');

            return [
                'goal_id'                  => $payment->getId(),
                'reached_before_deadline'  => $daysBeforeDeadline >= 0,
                'days_before_deadline'     => $daysBeforeDeadline,
                'collection_duration_days' => (int)$payment->getCollectionStartDate()->diff($today)->format('%r%a'),
                'shown_on_dashboard'       => (bool)$payment->getDisplayOnDashboard(),
            ];
        });
    }
}