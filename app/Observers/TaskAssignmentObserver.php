<?php

namespace App\Observers;

use App\Models\TaskAssignment;
use App\Services\NotificationService;

class TaskAssignmentObserver
{
    /** @var array<int, string|null> */
    private array $previousStatuses = [];

    public function __construct(
        private NotificationService $notifications,
    ) {}

    public function created(TaskAssignment $assignment): void
    {
        if ($assignment->suppressCreatedNotification) {
            return;
        }

        $this->notifications->notifyAssignmentCreated($assignment);
    }

    public function updating(TaskAssignment $assignment): void
    {
        $this->previousStatuses[spl_object_id($assignment)] = $assignment->getOriginal('status');
    }

    public function updated(TaskAssignment $assignment): void
    {
        $key = spl_object_id($assignment);
        $previous = $this->previousStatuses[$key] ?? null;
        unset($this->previousStatuses[$key]);

        $this->notifications->notifyStatusChanged($assignment, $previous);
    }
}
