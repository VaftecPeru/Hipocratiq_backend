<?php

namespace App\Services\Support;

use App\Enums\SupportTicketActivityType;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketActivity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupportTicketWorkflow
{
    public function dueAt(Carbon $createdAt, SupportTicketPriority|string $priority): Carbon
    {
        $priorityValue = $priority instanceof SupportTicketPriority ? $priority : SupportTicketPriority::from($priority);
        $hours = match ($priorityValue) {
            SupportTicketPriority::Urgent => 2,
            SupportTicketPriority::High => 8,
            SupportTicketPriority::Normal => 24,
            SupportTicketPriority::Low => 72,
        };

        return $createdAt->copy()->addHours($hours);
    }

    /**
     * @param  array{status?: string, priority?: string, assigned_to_user_id?: int|null}  $changes
     */
    public function update(SupportTicket $ticket, User $actor, array $changes): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $changes): SupportTicket {
            $originalStatus = $ticket->status;
            $originalPriority = $ticket->priority;
            $originalAssigneeId = $ticket->assigned_to_user_id;
            $now = now();

            if (isset($changes['priority']) && $changes['priority'] !== $originalPriority->value) {
                $ticket->priority = $changes['priority'];
                $ticket->due_at = $this->dueAt($ticket->created_at ?? $now, $changes['priority']);
            }

            if (array_key_exists('assigned_to_user_id', $changes)) {
                $ticket->assigned_to_user_id = $changes['assigned_to_user_id'];
            }

            if (isset($changes['status']) && $changes['status'] !== $originalStatus->value) {
                $nextStatus = SupportTicketStatus::from($changes['status']);
                $this->assertTransitionAllowed($originalStatus, $nextStatus);
                $ticket->status = $nextStatus;

                if ($nextStatus !== SupportTicketStatus::Open && $ticket->first_responded_at === null) {
                    $ticket->first_responded_at = $now;
                }

                if ($nextStatus === SupportTicketStatus::Resolved) {
                    $ticket->resolved_at = $now;
                    $ticket->closed_at = null;
                } elseif ($nextStatus === SupportTicketStatus::Closed) {
                    $ticket->resolved_at ??= $now;
                    $ticket->closed_at = $now;
                } elseif (in_array($nextStatus, [SupportTicketStatus::Open, SupportTicketStatus::InProgress, SupportTicketStatus::WaitingOnCustomer], true)) {
                    $ticket->resolved_at = null;
                    $ticket->closed_at = null;
                }
            }

            $ticket->last_activity_at = $now;
            $ticket->save();

            if ($ticket->status !== $originalStatus) {
                $this->recordActivity(
                    $ticket,
                    $actor,
                    SupportTicketActivityType::StatusChanged,
                    fromValue: $originalStatus->value,
                    toValue: $ticket->status->value,
                );
            }

            if ($ticket->priority !== $originalPriority) {
                $this->recordActivity(
                    $ticket,
                    $actor,
                    SupportTicketActivityType::PriorityChanged,
                    fromValue: $originalPriority->value,
                    toValue: $ticket->priority->value,
                );
            }

            if ($ticket->assigned_to_user_id !== $originalAssigneeId) {
                $this->recordActivity(
                    $ticket,
                    $actor,
                    SupportTicketActivityType::AssignmentChanged,
                    fromValue: $originalAssigneeId === null ? null : (string) $originalAssigneeId,
                    toValue: $ticket->assigned_to_user_id === null ? null : (string) $ticket->assigned_to_user_id,
                );
            }

            return $ticket->refresh();
        });
    }

    public function comment(SupportTicket $ticket, User $actor, string $body, bool $isInternal): SupportTicketActivity
    {
        return DB::transaction(function () use ($ticket, $actor, $body, $isInternal): SupportTicketActivity {
            $now = now();

            $ticket->forceFill([
                'first_responded_at' => $ticket->first_responded_at ?? $now,
                'last_activity_at' => $now,
            ])->save();

            return $this->recordActivity(
                $ticket,
                $actor,
                SupportTicketActivityType::Comment,
                body: trim($body),
                isInternal: $isInternal,
            );
        });
    }

    public function recordCreated(SupportTicket $ticket, User $actor): SupportTicketActivity
    {
        return $this->recordActivity($ticket, $actor, SupportTicketActivityType::Created);
    }

    private function assertTransitionAllowed(SupportTicketStatus $from, SupportTicketStatus $to): void
    {
        $allowed = match ($from) {
            SupportTicketStatus::Open => [SupportTicketStatus::InProgress, SupportTicketStatus::Closed],
            SupportTicketStatus::InProgress => [SupportTicketStatus::Open, SupportTicketStatus::WaitingOnCustomer, SupportTicketStatus::Resolved],
            SupportTicketStatus::WaitingOnCustomer => [SupportTicketStatus::InProgress, SupportTicketStatus::Resolved],
            SupportTicketStatus::Resolved => [SupportTicketStatus::InProgress, SupportTicketStatus::Closed],
            SupportTicketStatus::Closed => [SupportTicketStatus::InProgress],
        };

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede cambiar el ticket de {$from->value} a {$to->value}."],
            ]);
        }
    }

    private function recordActivity(
        SupportTicket $ticket,
        User $actor,
        SupportTicketActivityType $type,
        ?string $body = null,
        ?string $fromValue = null,
        ?string $toValue = null,
        bool $isInternal = true,
    ): SupportTicketActivity {
        return $ticket->activities()->create([
            'actor_user_id' => $actor->getKey(),
            'type' => $type,
            'body' => $body,
            'from_value' => $fromValue,
            'to_value' => $toValue,
            'is_internal' => $isInternal,
        ]);
    }
}
