@if ($items->isNotEmpty())
    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-100">
        @foreach ($items as $participation)
            <li class="flex items-center gap-3 bg-white px-4 py-3 hover:bg-gray-50/80">
                <img src="{{ $participation->user->avatar_url }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-800">
                        {{ $participation->user }}
                        @if ($participation->department)
                            <span class="text-xs font-normal text-gray-400">· {{ $participation->department->name }}</span>
                        @endif
                    </p>
                    <p class="text-xs text-gray-400">
                        @if ($participation->evaluation === \App\Support\EvaluationResult::DAT)
                            <span class="text-emerald-600">Đã đánh giá: Đạt</span>
                        @elseif ($participation->evaluation === \App\Support\EvaluationResult::CHO_LAM_LAI)
                            <span class="text-amber-600">Đã đánh giá: Làm lại</span>
                        @elseif ($participation->evaluation === \App\Support\EvaluationResult::TRE_BI_TRU_DIEM)
                            <span class="text-rose-600">Đã đánh giá: Làm lại &amp; trừ 1</span>
                        @else
                            Chưa đánh giá nội bộ
                        @endif
                    </p>
                </div>

                @if ($canManage && !in_array($assignment->status, [\App\Support\TaskStatus::COMPLETED, \App\Support\TaskStatus::PENDING], true))
                    <div class="flex shrink-0 items-center gap-1.5">
                        @if ($showDelegate)
                            <form method="post" action="{{ route('staff_task_set_delegate', $myAssignment->id) }}">
                                @csrf
                                @if ((int) $task->delegated_updater_id === (int) $participation->user_id)
                                    <input type="hidden" name="clear" value="1">
                                    <button class="rounded-lg bg-amber-100 px-2.5 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-200">
                                        <i class="fas fa-crown"></i> Đang ủy quyền
                                    </button>
                                @else
                                    <input type="hidden" name="user_id" value="{{ $participation->user_id }}">
                                    <button class="rounded-lg bg-gray-100 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-amber-50 hover:text-amber-700">
                                        <i class="fas fa-crown"></i> Ủy quyền
                                    </button>
                                @endif
                            </form>
                        @endif
                        <form method="post" action="{{ route('staff_task_remove_member', $myAssignment->id) }}" onsubmit="return confirm('Gỡ thành viên này?')">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $participation->user_id }}">
                            <button class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-rose-50 hover:text-rose-600" title="Gỡ thành viên">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </div>
                @elseif ((int) $task->delegated_updater_id === (int) $participation->user_id)
                    <span class="rounded-lg bg-amber-50 px-2 py-1 text-xs text-amber-700"><i class="fas fa-crown"></i> Ủy quyền</span>
                @endif
            </li>
        @endforeach
    </ul>
@else
    <div class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center">
        <p class="text-sm text-gray-500">Chưa có thành viên trong nhóm này.</p>
    </div>
@endif
