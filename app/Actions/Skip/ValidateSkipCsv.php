<?php

namespace App\Actions\Skip;

use App\Exceptions\MealRuleViolation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Skip;
use App\Services\MealCalendar;
use App\Services\MealGuard;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

class ValidateSkipCsv
{
    /**
     * Validate CSV rows for Leave / WFH skips without modifying database.
     *
     * @return array{
     *     valid_rows: array<int, array{employee_id: int, employee_code: string, date: string, source: string, reason: ?string}>,
     *     errors: array<int, array{row: int, field: string, message: string}>,
     *     summary: array{total_rows: int, will_create: int, already_skipped: int, blocked_cancelled: int, non_meal_days: int, rejected: int}
     * }
     */
    public function execute(UploadedFile $file, Company $company, string $type): array
    {
        $validRows = [];
        $errors = [];

        $summary = [
            'total_rows' => 0,
            'will_create' => 0,
            'already_skipped' => 0,
            'blocked_cancelled' => 0,
            'non_meal_days' => 0,
            'rejected' => 0,
        ];

        // Rule: WFH Auto Skip Check
        if ($type === 'wfh' && ! ($company->setting?->wfh_auto_skip ?? false)) {
            $errors[] = [
                'row' => 1,
                'field' => 'type',
                'message' => 'WFH auto-skip is disabled for your company in settings.',
            ];
            $summary['rejected']++;

            return [
                'valid_rows' => [],
                'errors' => $errors,
                'summary' => $summary,
            ];
        }

        $filePath = $file->getRealPath();
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $errors[] = [
                'row' => 1,
                'field' => 'file',
                'message' => 'Unable to read CSV file.',
            ];

            return [
                'valid_rows' => [],
                'errors' => $errors,
                'summary' => $summary,
            ];
        }

        // Read header & strip BOM
        // escape: '' is explicit because PHP 8.4 deprecates the default, and
        // disabling backslash escaping is the correct reading of CSV anyway.
        $headers = fgetcsv($handle, escape: '');
        if (! $headers) {
            fclose($handle);
            $errors[] = [
                'row' => 1,
                'field' => 'file',
                'message' => 'CSV file is empty.',
            ];

            return [
                'valid_rows' => [],
                'errors' => $errors,
                'summary' => $summary,
            ];
        }

        if (isset($headers[0])) {
            $headers[0] = str_replace("\xEF\xBB\xBF", '', $headers[0]);
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);
        $headerMap = array_flip($headers);

        $rowNumber = 1; // 1 is header, data starts at row 2

        while (($data = fgetcsv($handle, escape: '')) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($data, fn ($val) => trim((string) $val) !== ''))) {
                continue;
            }

            $summary['total_rows']++;

            $row = [];
            foreach ($headerMap as $colName => $colIdx) {
                $row[$colName] = $data[$colIdx] ?? '';
            }

            $employeeCode = strtoupper(trim((string) ($row['employee_code'] ?? '')));
            $fromDateRaw = trim((string) ($row['from_date'] ?? ''));
            $toDateRaw = trim((string) ($row['to_date'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            $reason = $reason !== '' ? $reason : null;

            // 1. Employee Code Check
            if ($employeeCode === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_code',
                    'message' => 'Employee code is required.',
                ];
                $summary['rejected']++;

                continue;
            }

            $employee = Employee::where('company_id', $company->id)
                ->where('employee_code', $employeeCode)
                ->first();

            if (! $employee) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_code',
                    'message' => "Employee with code '{$employeeCode}' not found.",
                ];
                $summary['rejected']++;

                continue;
            }

            if ($employee->status !== 'active') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_code',
                    'message' => "Employee '{$employeeCode}' is inactive.",
                ];
                $summary['rejected']++;

                continue;
            }

            if (! $employee->is_meal_eligible) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_code',
                    'message' => "Employee '{$employeeCode}' is not meal eligible.",
                ];
                $summary['rejected']++;

                continue;
            }

            // 2. From Date Validation
            if ($fromDateRaw === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'from_date',
                    'message' => 'from_date is required.',
                ];
                $summary['rejected']++;

                continue;
            }

            $fromDate = $this->parseDateString($fromDateRaw);
            if (! $fromDate) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'from_date',
                    'message' => "Invalid from_date format '{$fromDateRaw}'. Use YYYY-MM-DD or DD/MM/YYYY.",
                ];
                $summary['rejected']++;

                continue;
            }

            // 3. To Date Validation
            $toDate = $fromDate;
            if ($toDateRaw !== '') {
                $parsedToDate = $this->parseDateString($toDateRaw);
                if (! $parsedToDate) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'to_date',
                        'message' => "Invalid to_date format '{$toDateRaw}'. Use YYYY-MM-DD or DD/MM/YYYY.",
                    ];
                    $summary['rejected']++;

                    continue;
                }
                $toDate = $parsedToDate;
            }

            // 4. Date Range Logic
            if ($toDate < $fromDate) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'to_date',
                    'message' => 'to_date cannot be earlier than from_date.',
                ];
                $summary['rejected']++;

                continue;
            }

            $startCarbon = Carbon::parse($fromDate);
            $endCarbon = Carbon::parse($toDate);
            $daysCount = $startCarbon->diffInDays($endCarbon) + 1;

            if ($daysCount > 31) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'to_date',
                    'message' => 'Date range cannot exceed 31 days.',
                ];
                $summary['rejected']++;

                continue;
            }

            // Iterate each date in date range
            $currentCarbon = $startCarbon->copy();
            while ($currentCarbon->lte($endCarbon)) {
                $currentDateStr = $currentCarbon->toDateString();

                // Non-meal day check
                if (! MealCalendar::isMealDay($company, $currentDateStr)) {
                    $summary['non_meal_days']++;
                    $currentCarbon->addDay();

                    continue;
                }

                // Dry run MealGuard editability (past date, cutoff, locked, etc.)
                try {
                    MealGuard::assertEditable($company, $currentDateStr, ['meal_day', 'past', 'advance', 'locked', 'cutoff'], 'record skip');
                } catch (MealRuleViolation $e) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'date',
                        'message' => "Date {$currentDateStr}: {$e->getMessage()}",
                    ];
                    $summary['rejected']++;
                    $currentCarbon->addDay();

                    continue;
                }

                // Check DB for existing skip
                $existingSkip = Skip::where('company_id', $company->id)
                    ->where('employee_id', $employee->id)
                    ->where('date', $currentDateStr)
                    ->first();

                if ($existingSkip) {
                    if ($existingSkip->cancelled_at === null) {
                        $summary['already_skipped']++;
                    } else {
                        // Cancelled skip with auto source (leave, wfh)
                        $summary['blocked_cancelled']++;
                    }
                } else {
                    $summary['will_create']++;
                    $validRows[] = [
                        'employee_id' => $employee->id,
                        'employee_code' => $employee->employee_code,
                        'date' => $currentDateStr,
                        'source' => $type,
                        'reason' => $reason,
                    ];
                }

                $currentCarbon->addDay();
            }
        }

        fclose($handle);

        return [
            'valid_rows' => $validRows,
            'errors' => $errors,
            'summary' => $summary,
        ];
    }

    protected function parseDateString(string $dateStr): ?string
    {
        $dateStr = trim($dateStr);
        if ($dateStr === '') {
            return null;
        }

        // YYYY-MM-DD format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $dateStr)->toDateString();
            } catch (\Exception $e) {
                return null;
            }
        }

        // DD/MM/YYYY or DD-MM-YYYY format
        if (preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}$/', $dateStr)) {
            $separator = str_contains($dateStr, '/') ? '/' : '-';
            try {
                return Carbon::createFromFormat("d{$separator}m{$separator}Y", $dateStr)->toDateString();
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }
}
