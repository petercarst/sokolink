<?php

declare(strict_types=1);

namespace App\Controllers\Delivery;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Domain\Enums\DeliveryFailureReason;
use App\Repositories\DeliveryRepository;
use App\Services\DeliveryService;
use App\Support\View\TaskView;

/**
 * The delivery agent's screens.
 *
 * **Scoping is the whole point here** (FR-DEL-04). The agent id comes from
 * `Auth::agentUserId()`, which resolves the session user and returns null for
 * anyone without the delivery role. Every read goes through a query with
 * `agent_user_id = :actor` in its WHERE clause, so another agent's task is not
 * fetched and then hidden — it is never selected, and a request for one is a
 * 404, exactly as it is for a task that does not exist.
 *
 * **An offer shows less than a task.** Until a job is accepted, an agent sees
 * the zone, the collection point, the fee and a size band — no recipient, no
 * address, no phone. That is enforced by the query behind the offers list not
 * selecting those columns at all, rather than by this class or the template
 * choosing not to print them.
 *
 * Every state change goes through DeliveryService, which owns the task state
 * machine, the attempt counter, the confirmation code and the knock-on effects
 * on the order. Nothing here writes to a table.
 */
final class DeliveryController extends Controller
{
    /** The agent screens Phase 4c has connected to the database. */
    private const WIRED = [
        'delivery/dashboard',
        'delivery/tasks',
        'delivery/task',
        'delivery/offers',
        'delivery/history',
        'delivery/fail-report',
    ];

    public function __construct(
        private readonly DeliveryService $deliveries = new DeliveryService(),
        private readonly DeliveryRepository $tasks = new DeliveryRepository(),
    ) {
    }

    // ---- Screens ---------------------------------------------------------

    public function dashboard(): Response
    {
        $agentId = $this->agentId();

        return $this->page('delivery/dashboard', [
            'title'   => 'Today',
            'tasks'   => TaskView::assignedList($this->deliveries->myTasks($agentId)),
            'offers'  => TaskView::offerList($this->deliveries->availableToClaim($agentId)),
            'done'    => TaskView::assignedList($this->completed($agentId)),
            'summary' => TaskView::summary($this->deliveries->agentSummary($agentId)),
        ]);
    }

    public function tasks(): Response
    {
        return $this->page('delivery/tasks', [
            'title' => 'My deliveries',
            'tasks' => TaskView::assignedList($this->deliveries->myTasks($this->agentId())),
        ]);
    }

    public function task(string $ref): Response
    {
        $row = $this->requireOwnTask($ref);

        return $this->page('delivery/task', [
            'title'  => 'Delivery ' . (string) $row['task_ref'],
            'order'  => TaskView::assigned($row),
            'events' => $this->tasks->eventsFor((int) $row['id']),
        ]);
    }

    public function offers(): Response
    {
        return $this->page('delivery/offers', [
            'title'  => 'Available jobs',
            'offers' => TaskView::offerList($this->deliveries->availableToClaim($this->agentId())),
        ]);
    }

    public function history(): Response
    {
        return $this->page('delivery/history', [
            'title' => 'Delivery history',
            'tasks' => TaskView::assignedList($this->completed($this->agentId())),
        ]);
    }

    public function failReport(string $ref): Response
    {
        $row = $this->requireOwnTask($ref);

        return $this->page('delivery/fail-report', [
            'title'   => 'Report a failed attempt',
            'order'   => TaskView::assigned($row),
            'reasons' => $this->failureReasons(),
        ]);
    }

    // ---- Actions ---------------------------------------------------------

    /** Taking a job from the pool. Races are settled by the guarded UPDATE. */
    public function claim(): Response
    {
        return $this->taskAction(
            fn (int $taskId, int $agentId) => $this->deliveries->claimTask($taskId, $agentId),
            'Job taken. The collection point and the address are on the task now.',
            'delivery.offers'
        );
    }

    public function decline(): Response
    {
        return $this->taskAction(
            fn (int $taskId, int $agentId) => $this->deliveries->declineTask(
                $taskId,
                $agentId,
                (string) $this->request()->input('reason', '')
            ),
            'Declined. It goes back to the pool for another agent.',
            'delivery.offers'
        );
    }

    public function pickedUp(): Response
    {
        return $this->taskAction(
            fn (int $taskId, int $agentId) => $this->deliveries->markPickedUp($taskId, $agentId),
            'Collected from the store.'
        );
    }

    public function outForDelivery(): Response
    {
        return $this->taskAction(
            fn (int $taskId, int $agentId) => $this->deliveries->markOutForDelivery($taskId, $agentId),
            'On the way. The customer has been sent their delivery code.'
        );
    }

    /**
     * The doorstep: the recipient reads out their code and the agent types it.
     *
     * The comparison is against a hash, inside DeliveryService, with an attempt
     * counter that survives the failure. Nothing here can tell the agent
     * whether they were close.
     */
    public function confirmDelivery(): Response
    {
        return $this->taskAction(
            function (int $taskId, int $agentId): void {
                $code = trim((string) $this->request()->input('code', ''));

                if ($code === '') {
                    throw new DomainRuleException(
                        'Ask the recipient for the code in their message.',
                        'no_code'
                    );
                }

                $this->deliveries->confirmDelivery($taskId, $agentId, $code);
            },
            'Delivered. Thank you - that order is complete.',
            'delivery.tasks'
        );
    }

    /**
     * A failed attempt, which always carries a reason.
     *
     * The reason matters beyond bookkeeping: a customer-caused failure counts
     * towards whether that customer may keep paying cash, and a road or a
     * parcel problem must not. So it comes from an allow-list rather than a
     * free-text field.
     */
    public function reportFailure(): Response
    {
        return $this->taskAction(
            function (int $taskId, int $agentId): void {
                $code = (string) $this->request()->input('reason_code', '');
                $note = trim((string) $this->request()->input('note', ''));

                $reason = DeliveryFailureReason::tryFrom($code);

                if ($reason === null) {
                    throw new DomainRuleException('Choose what went wrong.', 'reason_required');
                }

                $this->deliveries->recordFailedAttempt($taskId, $agentId, $reason, $note);
            },
            'Attempt recorded. The seller and the customer have both been told.',
            'delivery.tasks'
        );
    }

    public function retry(): Response
    {
        return $this->taskAction(
            fn (int $taskId, int $agentId) => $this->deliveries->retryDelivery($taskId, $agentId),
            'Back out for delivery.'
        );
    }

    // ---- internals -------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data): Response
    {
        return $this->view($view, array_merge([
            'role'       => 'delivery',
            'sampleData' => !in_array($view, self::WIRED, true),
        ], $data), 'dashboard');
    }

    /**
     * The agent this request acts as.
     *
     * Null for anybody without the delivery role, which the route gate has
     * already refused - so reaching this with null means the role was removed
     * mid-session, and 403 is the honest answer.
     */
    private function agentId(): int
    {
        $id = Auth::agentUserId();

        if ($id === null) {
            throw new HttpException(403, 'Your account is not set up as a delivery agent.');
        }

        return $id;
    }

    /**
     * One of this agent's tasks by reference, or a 404.
     *
     * By `task_ref` rather than id, because that is what the pages show and
     * what an agent reads out on the phone.
     *
     * @return array<string,mixed>
     */
    private function requireOwnTask(string $ref): array
    {
        $task = $this->tasks->findByRef($ref);

        $row = $task === null
            ? null
            : $this->tasks->findForAgent((int) $task['id'], $this->agentId());

        if ($row === null) {
            throw new HttpException(404, 'That delivery is not assigned to you.');
        }

        return $row;
    }

    /**
     * The shared shape of every action handler.
     *
     * Each one resolves a posted reference to a task id, calls a service, and
     * redirects. The reference is posted rather than the id, because that is
     * what the page is showing - and it is resolved through a query scoped to
     * this agent, so a reference belonging to somebody else resolves to
     * nothing.
     *
     * @param callable(int,int):void $action
     */
    private function taskAction(callable $action, string $message, ?string $fallback = null): Response
    {
        $ref = (string) $this->request()->input('ref', '');

        return $this->attempt(function () use ($action, $message, $ref): Response {
            $agentId = $this->agentId();
            $task    = $this->tasks->findByRef($ref);

            if ($task === null) {
                throw new DomainRuleException('We could not find that delivery.', 'not_found');
            }

            $action((int) $task['id'], $agentId);

            // Back to the task if it is still theirs to look at; an unclaimed
            // job they just declined is not.
            $stillTheirs = $this->tasks->findForAgent((int) $task['id'], $agentId) !== null;

            return $this->success(
                $message,
                $stillTheirs
                    ? $this->toRoute('delivery.tasks.show', ['ref' => $ref])
                    : $this->toRoute('delivery.tasks')
            );
        }, $this->toRoute($fallback ?? 'delivery.tasks'));
    }

    /** @return list<array<string,mixed>> */
    private function completed(int $agentId): array
    {
        return array_values(array_filter(
            $this->tasks->forAgent($agentId, false),
            static fn (array $t): bool => in_array(
                (string) $t['status'],
                ['delivered', 'returned_to_seller'],
                true
            )
        ));
    }

    /**
     * The failure reasons an agent may choose from.
     *
     * @return array<string,string>
     */
    private function failureReasons(): array
    {
        $options = ['' => 'What went wrong?'];

        foreach (DeliveryFailureReason::cases() as $reason) {
            $options[$reason->value] = $reason->label();
        }

        return $options;
    }
}
