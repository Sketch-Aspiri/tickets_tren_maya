<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use PHPUnit\Framework\TestCase;

class TicketEnumsTest extends TestCase
{
    public function test_final_states_are_completed_and_cancelled_only(): void
    {
        $final = array_filter(TicketStatus::cases(), fn (TicketStatus $s) => $s->isFinal());

        $this->assertEqualsCanonicalizing([TicketStatus::Completed, TicketStatus::Cancelled], array_values($final));
        $this->assertSame(['completed', 'cancelled'], TicketStatus::finalValues());
        $this->assertSame([TicketStatus::Pending, TicketStatus::InProgress, TicketStatus::InReview], TicketStatus::open());
    }

    public function test_overdue_is_not_a_status(): void
    {
        $this->assertNull(TicketStatus::tryFrom('overdue'));
        $this->assertNull(TicketStatus::tryFrom('vencido'));
    }

    public function test_no_state_can_transition_to_itself_and_only_final_states_can_reopen(): void
    {
        foreach (TicketStatus::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status));
            $this->assertSame($status->isFinal(), $status->canTransitionTo(TicketStatus::Pending));
        }
    }

    public function test_employees_flow_never_reaches_completed_without_review(): void
    {
        $this->assertFalse(TicketStatus::Pending->canTransitionTo(TicketStatus::Completed));
        $this->assertFalse(TicketStatus::InProgress->canTransitionTo(TicketStatus::Completed));
        $this->assertTrue(TicketStatus::InReview->canTransitionTo(TicketStatus::Completed));
    }

    public function test_comment_is_required_on_reject_and_reopen_only(): void
    {
        $this->assertTrue(TicketStatus::InReview->requiresCommentWhenMovingTo(TicketStatus::InProgress));
        $this->assertTrue(TicketStatus::Completed->requiresCommentWhenMovingTo(TicketStatus::Pending));
        $this->assertTrue(TicketStatus::Cancelled->requiresCommentWhenMovingTo(TicketStatus::Pending));
        $this->assertFalse(TicketStatus::Pending->requiresCommentWhenMovingTo(TicketStatus::InProgress));
        $this->assertFalse(TicketStatus::InReview->requiresCommentWhenMovingTo(TicketStatus::Completed));
        $this->assertFalse(TicketStatus::InProgress->requiresCommentWhenMovingTo(TicketStatus::Cancelled));
    }

    public function test_action_label_distinguishes_reject_from_start(): void
    {
        $this->assertSame('reject', TicketStatus::InProgress->actionLabelKeyFrom(TicketStatus::InReview));
        $this->assertSame('in_progress', TicketStatus::InProgress->actionLabelKeyFrom(TicketStatus::Pending));
        $this->assertSame('pending', TicketStatus::Pending->actionLabelKeyFrom(TicketStatus::Completed));
    }

    public function test_priority_weights_are_strictly_increasing(): void
    {
        $weights = array_map(fn (Priority $p) => $p->weight(), Priority::cases());

        $sorted = $weights;
        sort($sorted);

        $this->assertSame($sorted, $weights);
        $this->assertCount(4, array_unique($weights));
        $this->assertSame(['low', 'medium', 'high', 'urgent'], Priority::values());
    }

    public function test_assignment_roles_and_sources(): void
    {
        $this->assertSame(['responsable', 'colaborador'], AssignmentRole::values());
        $this->assertSame('web', TicketSource::Web->value);
        $this->assertSame('email', TicketSource::Email->value);
    }
}
