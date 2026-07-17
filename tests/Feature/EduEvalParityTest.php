<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskParticipation;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskRemovedNotification;
use App\Services\ReportingService;
use App\Support\EvaluationResult;
use App\Support\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EduEvalParityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $leader;

    private User $coordLeader;

    private User $member;

    private Department $dept1;

    private Department $dept2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create(['username' => 'mgr']);
        $this->leader = User::factory()->create(['username' => 'leader']);
        $this->coordLeader = User::factory()->create(['username' => 'coord']);
        $this->member = User::factory()->create(['username' => 'member']);
        $this->dept1 = Department::create(['name' => 'Tổ A', 'leader_id' => $this->leader->id, 'badge_color' => 'blue']);
        $this->dept2 = Department::create(['name' => 'Tổ B', 'leader_id' => $this->coordLeader->id, 'badge_color' => 'violet']);
        $this->dept1->members()->attach([$this->leader->id, $this->member->id]);
        $this->dept2->members()->attach([$this->coordLeader->id]);
    }

    public function test_login_rbac_and_locked_account(): void
    {
        $this->post('/login', ['username' => 'mgr', 'password' => 'password'])
            ->assertRedirect(route('manager_dashboard'));

        $this->get('/staff/')->assertRedirect(route('manager_dashboard'));

        auth()->logout();
        $this->post('/login', ['username' => 'leader', 'password' => 'password'])
            ->assertRedirect(route('staff_dashboard'));
        $this->get('/manager/')->assertForbidden();

        auth()->logout();
        $this->leader->update(['is_active' => false]);
        $this->post('/login', ['username' => 'leader', 'password' => 'password'])
            ->assertSessionHasErrors('username');
    }

    public function test_three_evaluation_results_and_overwrite(): void
    {
        $task = Task::create([
            'title' => 'Việc 1',
            'created_by_id' => $this->manager->id,
            'deadline' => now()->addDays(5)->toDateString(),
            'cycle' => Task::CYCLE_MONTH,
        ]);
        $asg = TaskAssignment::create([
            'task_id' => $task->id,
            'assignee_id' => $this->member->id,
            'status' => TaskStatus::PENDING,
            'submitted_at' => now(),
        ]);

        $asg->applyReview(EvaluationResult::TRE_BI_TRU_DIEM, 'lần 1');
        $asg->save();
        $this->assertSame(TaskStatus::REDO, $asg->status);
        $this->assertSame(1, $asg->penalty_score);

        $asg->applyReview(EvaluationResult::DAT, 'lần 2');
        $asg->save();
        $this->assertSame(TaskStatus::COMPLETED, $asg->status);
        $this->assertSame(0, $asg->penalty_score);
        $this->assertSame(EvaluationResult::DAT, $asg->evaluation_result);
    }

    public function test_batch_assign_and_independent_review(): void
    {
        $response = $this->actingAs($this->manager)
            ->post(route('manager_create_task'), [
                'title' => 'Batch',
                'description' => 'x',
                'deadline' => now()->addDays(10)->toDateString(),
                'cycle' => Task::CYCLE_MONTH,
                'assign_mode' => 'batch_department',
                'batch_departments' => [$this->dept1->id, $this->dept2->id],
            ]);
        $response->assertSessionHasNoErrors();
        $task = Task::where('title', 'Batch')->firstOrFail();
        $response->assertRedirect(route('manager_task_detail', $task));
        $this->assertCount(2, $task->assignments);
        $a1 = $task->assignments()->where('assignee_department_id', $this->dept1->id)->first();
        $a2 = $task->assignments()->where('assignee_department_id', $this->dept2->id)->first();
        $a1->update(['status' => TaskStatus::PENDING, 'submitted_at' => now()]);
        $a2->update(['status' => TaskStatus::PENDING, 'submitted_at' => now()]);

        $this->actingAs($this->manager)
            ->postJson(route('manager_assignment_review', [$task, $a1]), [
                'evaluation_result' => EvaluationResult::DAT,
                'manager_comment' => 'OK',
            ])
            ->assertOk()
            ->assertJsonPath('assignment.id', $a1->id)
            ->assertJsonPath('assignment.status', TaskStatus::COMPLETED)
            ->assertJsonPath('assignment.evaluation_result', EvaluationResult::DAT)
            ->assertJsonPath('assignment.can_grade', false);

        $a1->refresh();
        $a2->refresh();
        $this->assertSame(TaskStatus::COMPLETED, $a1->status);
        $this->assertSame(TaskStatus::PENDING, $a2->status);
    }

    public function test_classic_canonical_review_and_personal_eval(): void
    {
        $task = Task::create([
            'title' => 'Classic',
            'created_by_id' => $this->manager->id,
            'deadline' => now()->addDays(7)->toDateString(),
            'cycle' => Task::CYCLE_MONTH,
            'primary_department_id' => $this->dept1->id,
        ]);
        $task->coordinatingDepartments()->attach($this->dept2->id);
        $canonical = TaskAssignment::create([
            'task_id' => $task->id,
            'assignee_id' => $this->leader->id,
            'status' => TaskStatus::PENDING,
            'submitted_at' => now(),
        ]);
        $coord = TaskAssignment::create([
            'task_id' => $task->id,
            'assignee_id' => $this->coordLeader->id,
            'status' => TaskStatus::PENDING,
            'submitted_at' => now(),
        ]);
        $part = TaskParticipation::create([
            'task_id' => $task->id,
            'user_id' => $this->member->id,
            'department_id' => $this->dept1->id,
            'role' => TaskParticipation::ROLE_LEAD,
        ]);

        $this->actingAs($this->manager)
            ->post(route('manager_assignment_review', [$task, $coord]), [
                'evaluation_result' => EvaluationResult::DAT,
            ])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->post(route('manager_assignment_review', [$task, $canonical]), [
                'evaluation_result' => EvaluationResult::DAT,
                'manager_comment' => 'Đạt',
            ])
            ->assertRedirect();

        $coord->refresh();
        $this->assertSame(TaskStatus::COMPLETED, $coord->status);
        $this->assertSame(EvaluationResult::DAT, $coord->evaluation_result);

        $this->actingAs($this->leader)
            ->post(route('staff_task_detail', $canonical->id), [
                'action' => 'internal_evaluate',
                'eval_'.$part->id => EvaluationResult::CHO_LAM_LAI,
            ])
            ->assertRedirect();

        $part->refresh();
        $this->assertSame(EvaluationResult::CHO_LAM_LAI, $part->evaluation);
        $this->assertSame(0, $part->penalty_score);
    }

    public function test_reporting_period_bounds_and_attribution(): void
    {
        $svc = app(ReportingService::class);
        [$mStart, $mEnd] = $svc->monthBounds(Carbon::parse('2026-01-31'));
        $this->assertSame('2026-01-01', $mStart->toDateString());
        $this->assertSame('2026-02-01', $mEnd->toDateString());

        $this->assertSame(2025, $svc->academicYearStartYear(Carbon::parse('2026-08-31')));
        $this->assertSame(2026, $svc->academicYearStartYear(Carbon::parse('2026-09-01')));
        [$aStart, $aEnd] = $svc->academicYearBounds(2025);
        $this->assertSame('2025-09-01', $aStart->toDateString());
        $this->assertSame('2026-09-01', $aEnd->toDateString());

        $personal = Task::create([
            'title' => 'Cá nhân',
            'created_by_id' => $this->manager->id,
            'deadline' => now()->toDateString(),
            'cycle' => Task::CYCLE_MONTH,
        ]);
        $pa = TaskAssignment::create([
            'task_id' => $personal->id,
            'assignee_id' => $this->member->id,
            'status' => TaskStatus::PENDING,
            'submitted_at' => now(),
        ]);
        $pa->applyReview(EvaluationResult::DAT);
        $pa->reviewed_at = now();
        $pa->save();

        $batch = Task::create([
            'title' => 'Batch',
            'created_by_id' => $this->manager->id,
            'deadline' => now()->toDateString(),
            'cycle' => Task::CYCLE_MONTH,
        ]);
        $ba = TaskAssignment::create([
            'task_id' => $batch->id,
            'assignee_department_id' => $this->dept1->id,
            'status' => TaskStatus::PENDING,
            'submitted_at' => now(),
        ]);
        $ba->applyReview(EvaluationResult::TRE_BI_TRU_DIEM);
        $ba->reviewed_at = now();
        $ba->save();

        $person = $svc->statsForPerson($this->member);
        $this->assertSame(1, $person['dat_count']);
        $leaderPerson = $svc->statsForPerson($this->leader);
        $this->assertSame(0, $leaderPerson['dat_count']);
        $dept = $svc->statsForDepartment($this->dept1);
        $this->assertSame(0, $dept['dat_count']);
        $this->assertSame(1, $dept['penalty_total']);
    }

    public function test_statistics_page_and_excel_export(): void
    {
        $this->actingAs($this->manager)
            ->get(route('manager_statistics', ['scope' => 'person', 'period' => 'month', 'year' => now()->year]))
            ->assertOk()
            ->assertSee('Thống kê định lượng');

        $this->actingAs($this->manager)
            ->get(route('manager_export_excel', ['scope' => 'person', 'period' => 'month', 'year' => now()->year]))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_manager_can_export_task_evaluation_excel(): void
    {
        $task = Task::create([
            'title' => 'Báo cáo tháng 7',
            'created_by_id' => $this->manager->id,
            'deadline' => now()->addDays(5)->toDateString(),
            'cycle' => Task::CYCLE_MONTH,
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'assignee_id' => $this->member->id,
            'status' => TaskStatus::PENDING,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->get(route('tasks.export_excel', $task))
            ->assertOk()
            ->assertHeader('content-disposition')
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_manager_can_load_and_update_staff_with_ajax(): void
    {
        $this->actingAs($this->manager)
            ->getJson(route('manager_staff_api_show', $this->member))
            ->assertOk()
            ->assertJsonPath('user.id', $this->member->id)
            ->assertJsonPath('user.username', 'member');

        $this->actingAs($this->manager)
            ->patchJson(route('manager_staff_api_update', $this->member), [
                'name' => 'Nguyễn Văn An',
                'username' => 'nguyenvanan',
                'role' => 'manager',
                'is_active' => false,
                'departments' => [$this->dept2->id],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Đã cập nhật thành công.')
            ->assertJsonPath('user.name', 'Nguyễn Văn An')
            ->assertJsonPath('user.role', 'manager')
            ->assertJsonPath('user.is_active', false)
            ->assertJsonPath('user.departments.0.id', $this->dept2->id);

        $this->assertDatabaseHas('users', [
            'id' => $this->member->id,
            'first_name' => 'Văn An',
            'last_name' => 'Nguyễn',
            'username' => 'nguyenvanan',
            'is_manager' => true,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('department_user', [
            'user_id' => $this->member->id,
            'department_id' => $this->dept2->id,
        ]);
        $this->assertDatabaseMissing('department_user', [
            'user_id' => $this->member->id,
            'department_id' => $this->dept1->id,
        ]);
    }

    public function test_manager_can_manage_department_members_with_ajax(): void
    {
        $this->actingAs($this->manager)
            ->getJson(route('department_members_api', $this->dept1))
            ->assertOk()
            ->assertJsonPath('department.id', $this->dept1->id)
            ->assertJsonCount(2, 'members');

        $this->actingAs($this->manager)
            ->getJson(route('department_member_search_api', [
                'q' => 'coord',
                'department_id' => $this->dept1->id,
            ]))
            ->assertOk()
            ->assertJsonPath('users.0.id', $this->coordLeader->id);

        $this->actingAs($this->manager)
            ->postJson(route('department_member_add_api', $this->dept1), [
                'user_id' => $this->coordLeader->id,
            ])
            ->assertOk()
            ->assertJsonPath('member.id', $this->coordLeader->id)
            ->assertJsonPath('member_count', 3);

        $this->actingAs($this->manager)
            ->deleteJson(route('department_member_destroy_api', [
                'department' => $this->dept1,
                'user' => $this->coordLeader,
            ]))
            ->assertOk()
            ->assertJsonPath('member_count', 2);

        $this->actingAs($this->manager)
            ->deleteJson(route('department_member_destroy_api', [
                'department' => $this->dept1,
                'user' => $this->leader,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Không thể xóa Trưởng tổ. Hãy chỉ định Trưởng tổ khác trước.');
    }

    public function test_manager_can_manage_task_assignees_with_ajax(): void
    {
        $individualTask = Task::create([
            'title' => 'Nhiệm vụ cá nhân',
            'created_by_id' => $this->manager->id,
            'deadline' => now()->addDays(5)->toDateString(),
            'cycle' => Task::CYCLE_MONTH,
        ]);
        TaskAssignment::create([
            'task_id' => $individualTask->id,
            'assignee_id' => $this->member->id,
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('task_assignees_search_api', [
                'task_id' => $individualTask->id,
                'q' => 'coord',
            ]))
            ->assertOk()
            ->assertJsonPath('results.0.id', $this->coordLeader->id)
            ->assertJsonPath('results.0.type', 'user');

        $this->actingAs($this->manager)
            ->postJson(route('task_assignees_store_api', $individualTask), [
                'type' => 'user',
                'id' => $this->coordLeader->id,
            ])
            ->assertCreated()
            ->assertJsonPath('assignee.id', $this->coordLeader->id);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->coordLeader->id,
            'type' => TaskAssignedNotification::class,
        ]);

        $this->actingAs($this->manager)
            ->deleteJson(route('task_assignees_destroy_api', $individualTask), [
                'type' => 'user',
                'id' => $this->member->id,
            ])
            ->assertOk();
        $this->assertDatabaseMissing('task_assignments', [
            'task_id' => $individualTask->id,
            'assignee_id' => $this->member->id,
        ]);
        $removedNotification = $this->member->notifications()
            ->where('type', TaskRemovedNotification::class)
            ->firstOrFail();
        $this->actingAs($this->member)
            ->getJson(route('notifications_api'))
            ->assertOk()
            ->assertJsonFragment(['id' => $removedNotification->id]);
        $this->actingAs($this->member)
            ->patchJson(route('notifications_mark_as_read_api', $removedNotification->id))
            ->assertOk();
        $this->assertNotNull($removedNotification->fresh()->read_at);

        $teamTask = Task::create([
            'title' => 'Nhiệm vụ liên tổ',
            'created_by_id' => $this->manager->id,
            'deadline' => now()->addDays(5)->toDateString(),
            'cycle' => Task::CYCLE_MONTH,
            'primary_department_id' => $this->dept1->id,
        ]);
        TaskAssignment::create([
            'task_id' => $teamTask->id,
            'assignee_id' => $this->leader->id,
        ]);

        $this->actingAs($this->manager)
            ->postJson(route('task_assignees_store_api', $teamTask), [
                'type' => 'dept',
                'id' => $this->dept2->id,
            ])
            ->assertCreated()
            ->assertJsonPath('assignee.type', 'dept');
        $this->assertDatabaseHas('task_coordinating_department', [
            'task_id' => $teamTask->id,
            'department_id' => $this->dept2->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->coordLeader->id,
            'type' => TaskAssignedNotification::class,
        ]);

        $this->actingAs($this->manager)
            ->deleteJson(route('task_assignees_destroy_api', $teamTask), [
                'type' => 'dept',
                'id' => $this->dept1->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee');

        $this->actingAs($this->manager)
            ->deleteJson(route('task_assignees_destroy_api', $teamTask), [
                'type' => 'dept',
                'id' => $this->dept2->id,
            ])
            ->assertOk();
        $this->assertDatabaseMissing('task_coordinating_department', [
            'task_id' => $teamTask->id,
            'department_id' => $this->dept2->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->coordLeader->id,
            'type' => TaskRemovedNotification::class,
        ]);
    }
}
