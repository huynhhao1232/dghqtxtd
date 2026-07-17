<?php

namespace App\Exports;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Support\EvaluationResult;
use App\Support\TaskStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Xuất bảng nghiệm thu nhiệm vụ (tương đương FromCollection / WithHeadings /
 * WithMapping / ShouldAutoSize / WithStyles của Laravel Excel).
 */
class TaskEvaluationExport
{
    private int $rowIndex = 0;

    public function __construct(private readonly Task $task) {}

    public function collection(): Collection
    {
        $this->task->loadMissing([
            'assignments.assignee',
            'assignments.assigneeDepartment.leader',
        ]);

        $assignments = $this->task->assignments->sortBy('id')->values();

        if ($this->task->isTeamTask()) {
            $canonical = $this->task->getCanonicalAssignment();
            if ($canonical) {
                $assignments = collect([$canonical->loadMissing('assignee', 'assigneeDepartment.leader')]);
            }
        }

        return $assignments;
    }

    public function headings(): array
    {
        return [
            'STT',
            'Tên người/Tổ thực hiện',
            'Trạng thái',
            'Thời gian nộp',
            'Điểm số',
            'Nhận xét của Lãnh đạo',
        ];
    }

    public function map(TaskAssignment $assignment): array
    {
        $this->rowIndex++;

        return [
            $this->rowIndex,
            $assignment->target_display_name,
            TaskStatus::label($assignment->status),
            $assignment->submitted_at?->format('d/m/Y H:i') ?: '—',
            $this->scoreLabel($assignment),
            filled($assignment->manager_comment) ? $assignment->manager_comment : '—',
        ];
    }

    public function styles($sheet): void
    {
        $lastColumn = 'F';
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE5E7EB');
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);
    }

    public function download(?string $filename = null): BinaryFileResponse
    {
        $this->rowIndex = 0;
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Bang diem');
        $sheet->fromArray($this->headings(), null, 'A1');

        foreach ($this->collection() as $index => $assignment) {
            $sheet->fromArray($this->map($assignment), null, 'A'.($index + 2));
        }

        $this->styles($sheet);

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename ??= $this->defaultFilename();
        $path = tempnam(sys_get_temp_dir(), 'edueval_task_');
        (new Xlsx($book))->save($path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function defaultFilename(): string
    {
        $slug = Str::of($this->task->title)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->trim('_')
            ->limit(40, '')
            ->value() ?: 'Nhiem_vu';

        return 'Bang_diem_'.$slug.'_'.now()->format('Ymd_His').'.xlsx';
    }

    private function scoreLabel(TaskAssignment $assignment): string
    {
        if (! $assignment->evaluation_result) {
            return 'Chưa chấm';
        }

        $label = EvaluationResult::label($assignment->evaluation_result);
        if ((int) $assignment->penalty_score === 1) {
            $label .= ' (Trừ 1)';
        }

        return $label;
    }
}
