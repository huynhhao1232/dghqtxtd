<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Support\TaskStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $deptToan = Department::firstOrCreate(
            ['name' => 'Tổ Toán-Tin'],
            ['description' => 'Tổ chuyên môn Toán và Tin học', 'badge_color' => 'blue']
        );
        $deptVan = Department::firstOrCreate(
            ['name' => 'Tổ Văn'],
            ['description' => 'Tổ chuyên môn Ngữ văn', 'badge_color' => 'violet']
        );
        $deptBgh = Department::firstOrCreate(
            ['name' => 'Ban Giám hiệu'],
            ['description' => 'Ban lãnh đạo trung tâm', 'badge_color' => 'amber']
        );

        $manager = User::updateOrCreate(
            ['username' => 'lanhdao'],
            [
                'first_name' => 'Minh',
                'last_name' => 'Nguyễn',
                'email' => 'lanhdao@example.com',
                'password' => Hash::make('Demo@123'),
                'is_manager' => true,
                'is_staff' => true,
                'is_active' => true,
                'position' => 'Phó Hiệu trưởng',
            ]
        );
        $staff1 = User::updateOrCreate(
            ['username' => 'nhanvien1'],
            [
                'first_name' => 'An',
                'last_name' => 'Trần',
                'email' => 'nv1@example.com',
                'password' => Hash::make('Demo@123'),
                'is_manager' => false,
                'is_staff' => true,
                'is_active' => true,
                'position' => 'Giáo viên',
            ]
        );
        $staff2 = User::updateOrCreate(
            ['username' => 'nhanvien2'],
            [
                'first_name' => 'Bình',
                'last_name' => 'Lê',
                'email' => 'nv2@example.com',
                'password' => Hash::make('Demo@123'),
                'is_manager' => false,
                'is_staff' => true,
                'is_active' => true,
                'position' => 'Giáo viên',
            ]
        );
        $staff3 = User::updateOrCreate(
            ['username' => 'nhanvien3'],
            [
                'first_name' => 'Chi',
                'last_name' => 'Phạm',
                'email' => 'nv3@example.com',
                'password' => Hash::make('Demo@123'),
                'is_manager' => false,
                'is_staff' => true,
                'is_active' => true,
                'position' => 'Giáo viên',
            ]
        );
        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'first_name' => 'Admin',
                'last_name' => 'Hệ thống',
                'email' => 'admin@example.com',
                'password' => Hash::make('Admin@123'),
                'is_manager' => true,
                'is_staff' => true,
                'is_active' => true,
                'position' => 'Quản trị',
            ]
        );

        $deptBgh->update(['leader_id' => $manager->id]);
        $deptToan->update(['leader_id' => $staff1->id]);
        $deptVan->update(['leader_id' => $staff2->id]);

        $deptBgh->members()->syncWithoutDetaching([$manager->id, $admin->id]);
        $deptToan->members()->syncWithoutDetaching([$staff1->id, $staff3->id]);
        $deptVan->members()->syncWithoutDetaching([$staff2->id, $staff3->id]);

        if (Task::query()->exists()) {
            return;
        }

        $today = now()->toDateString();
        $task = Task::create([
            'title' => 'Soạn đề kiểm tra giữa kỳ',
            'description' => 'Soạn đề và đáp án, nộp file PDF.',
            'created_by_id' => $manager->id,
            'deadline' => now()->addDays(7)->toDateString(),
            'cycle' => Task::CYCLE_MONTH,
            'primary_department_id' => $deptToan->id,
        ]);
        $task->coordinatingDepartments()->attach($deptVan->id);
        TaskAssignment::create(['task_id' => $task->id, 'assignee_id' => $staff1->id]);
        TaskAssignment::create(['task_id' => $task->id, 'assignee_id' => $staff2->id]);

        $task2 = Task::create([
            'title' => 'Báo cáo chuyên môn tháng',
            'description' => 'Nộp báo cáo hoạt động chuyên môn.',
            'created_by_id' => $manager->id,
            'deadline' => now()->subDays(2)->toDateString(),
            'cycle' => Task::CYCLE_MONTH,
        ]);
        TaskAssignment::create([
            'task_id' => $task2->id,
            'assignee_id' => $staff1->id,
            'status' => TaskStatus::IN_PROGRESS,
        ]);
    }
}
