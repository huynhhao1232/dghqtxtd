<?php

namespace App\Http\Controllers;

use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ManagerStatisticsController extends Controller
{
    public function index(Request $request, ReportingService $reporting): View
    {
        [$scope, $period, $year] = $this->params($request, $reporting);
        [$rows, $columns, $periodLabel] = $this->rows($reporting, $scope, $period, $year);
        $today = Carbon::today();
        $academicYear = $reporting->academicYearStartYear($today);

        return view('manager.statistics', [
            'rows' => $rows, 'columns' => $columns, 'year' => $year, 'period' => $period,
            'scope' => $scope, 'periodLabel' => $periodLabel,
            'years' => range($today->year - 2, $today->year + 1),
            'academicYears' => range($academicYear - 2, $academicYear + 1),
        ]);
    }

    public function export(Request $request, ReportingService $reporting): BinaryFileResponse
    {
        [$scope, $period, $year] = $this->params($request, $reporting);
        [$rows, $columns, $periodLabel] = $this->rows($reporting, $scope, $period, $year);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(substr($scope === 'person' ? "Ca_nhan_{$year}" : "To_nhom_{$year}", 0, 31));
        $header = $scope === 'person' ? ['Họ tên', 'Phòng ban'] : ['Tổ/Nhóm', 'Trưởng tổ'];
        foreach ($columns as $column) {
            $header[] = "{$column} Đạt";
            $header[] = "{$column} Trừ";
        }
        array_push($header, 'Tổng Đạt', 'Tổng Trừ');
        $sheet->fromArray($header, null, 'A1');
        foreach ($rows as $i => $row) {
            $line = [$row['label'], $row['sublabel']];
            foreach ($row['cells'] as $cell) {
                $line[] = $cell['dat_count'];
                $line[] = $cell['penalty_total'];
            }
            $line[] = $row['dat_total'];
            $line[] = $row['penalty_total'];
            $sheet->fromArray($line, null, 'A'.($i + 2));
        }
        $last = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F766E');
        $sheet->getStyle("A1:{$last}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('C2');
        for ($index = 1; $index <= Coordinate::columnIndexFromString($last); $index++) {
            $column = Coordinate::stringFromColumnIndex($index);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $path = tempnam(sys_get_temp_dir(), 'edueval_');
        (new Xlsx($book))->save($path);

        return response()->download($path, "thong-ke-{$scope}-{$periodLabel}.xlsx")->deleteFileAfterSend();
    }

    private function params(Request $request, ReportingService $reporting): array
    {
        $scope = in_array($request->query('scope'), ['person', 'department'], true) ? $request->query('scope') : 'person';
        $period = in_array($request->query('period'), ['month', 'quarter', 'academic_year'], true) ? $request->query('period') : 'month';
        $default = $period === 'academic_year' ? $reporting->academicYearStartYear() : now()->year;
        $year = filter_var($request->query('year', $default), FILTER_VALIDATE_INT) ?: $default;

        return [$scope, $period, $year];
    }

    private function rows(ReportingService $reporting, string $scope, string $period, int $year): array
    {
        [$entities, $columns, $matrix] = $scope === 'department'
            ? $reporting->buildDepartmentMatrix($period, $year)
            : $reporting->buildPersonMatrix($period, $year);
        $rows = $entities->map(function ($entity) use ($scope, $columns, $matrix) {
            $cells = collect($columns)->map(fn ($column) => $matrix[$entity->id][$column])->all();

            return [
                'label' => $scope === 'department' ? $entity->name : $entity->full_name_vn,
                'sublabel' => $scope === 'department' ? ($entity->leader ? (string) $entity->leader : '—') : $entity->department_name,
                'cells' => $cells,
                'dat_total' => array_sum(array_column($cells, 'dat_count')),
                'penalty_total' => array_sum(array_column($cells, 'penalty_total')),
            ];
        })->all();

        return [$rows, $columns, $period === 'academic_year' ? $reporting->academicYearLabel($year) : (string) $year];
    }
}
