<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->text('description')->nullable();
            $table->foreignId('leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('badge_color', 20)->default('blue');
            $table->timestamps();
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['department_id', 'user_id']);
        });

        Schema::create('group_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('created_by_id')->constrained('users')->cascadeOnDelete();
            $table->date('deadline');
            $table->string('cycle', 20)->default('month');
            $table->foreignId('primary_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('delegated_updater_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('is_subtask')->default(false);
            $table->timestamps();
        });

        Schema::create('task_coordinating_department', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->unique(['task_id', 'department_id']);
        });

        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('file');
            $table->string('original_name')->default('');
            $table->unsignedInteger('file_size')->default(0);
            $table->timestamps();
        });

        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->string('status', 20)->default('todo');
            $table->text('notes')->nullable();
            $table->string('proof_file')->nullable();
            $table->string('evaluation_result', 20)->nullable();
            $table->unsignedTinyInteger('penalty_score')->default(0);
            $table->text('manager_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'assignee_id']);
            $table->unique(['task_id', 'assignee_department_id']);
        });

        Schema::create('task_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('lead_member');
            $table->string('evaluation', 20)->default('none');
            $table->unsignedTinyInteger('penalty_score')->default(0);
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('coordinating_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('proof_file');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'department_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('message', 500);
            $table->boolean('is_read')->default(false);
            $table->foreignId('related_task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('coordinating_proofs');
        Schema::dropIfExists('task_participations');
        Schema::dropIfExists('task_assignments');
        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('task_coordinating_department');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('group_posts');
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');
    }
};
