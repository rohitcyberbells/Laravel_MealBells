<?php

namespace App\Actions\Employee;

use Illuminate\Http\UploadedFile;

class ValidateEmployeeCsv
{
    /**
     * Read an uploaded CSV into rows keyed by its header.
     *
     * Kept separate from execute() so the action still takes plain rows, which
     * is what the import confirm step posts back.
     *
     * @return array<int, array<string, string>>
     */
    public function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return [];
        }

        $headers = fgetcsv($handle);

        if (! $headers) {
            fclose($handle);

            return [];
        }

        // Excel writes a BOM onto the first header, which would otherwise make
        // 'employee_code' unmatchable and fail every row.
        $headers[0] = str_replace("\xEF\xBB\xBF", '', (string) ($headers[0] ?? ''));
        $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $headers);

        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if (empty(array_filter($data, fn ($value) => trim((string) $value) !== ''))) {
                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = $data[$index] ?? '';
                }
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Validate employee CSV rows without changing the database.
     *
     * @return array{
     *     valid_rows: array,
     *     errors: array
     * }
     */
    public function execute(array $rows): array
    {
        $validRows = [];
        $errors = [];
        $seenEmployeeCodes = [];

        foreach ($rows as $index => $row) {
            // +2 because CSV row 1 is the header.
            $rowNumber = $index + 2;

            $employeeCode = strtoupper(
                trim((string) ($row['employee_code'] ?? ''))
            );

            $name = trim(
                (string) ($row['name'] ?? '')
            );

            /*
             * Required fields
             */
            if ($employeeCode === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_code',
                    'message' => 'Employee code is required.',
                ];

                continue;
            }

            if ($name === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'name',
                    'message' => 'Name is required.',
                ];

                continue;
            }

            /*
             * Duplicate employee code inside CSV
             */
            if (isset($seenEmployeeCodes[$employeeCode])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_code',
                    'message' => "Duplicate employee code '{$employeeCode}' found in CSV.",
                ];

                continue;
            }

            $seenEmployeeCodes[$employeeCode] = true;

            /*
             * Build normalized row.
             *
             * Important:
             * We only add optional fields if the CSV
             * actually contains those columns.
             */
            $normalizedRow = [
                'employee_code' => $employeeCode,
                'name' => $name,
            ];

            /*
             * Email
             */
            if (array_key_exists('email', $row)) {
                $email = trim((string) $row['email']);

                if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'email',
                        'message' => "Invalid email '{$email}'.",
                    ];

                    continue;
                }

                $normalizedRow['email'] = $email !== '' ? $email : null;
            }

            /*
             * Attendance source
             */
            if (array_key_exists('attendance_source', $row)) {
                $attendanceSource = strtolower(
                    trim((string) $row['attendance_source'])
                );

                $allowedSources = config('mealbells.attendance_sources', ['manual', 'integrated', 'none']);
                if (! in_array(
                    $attendanceSource,
                    $allowedSources,
                    true
                )) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'attendance_source',
                        'message' => "Invalid attendance source '{$attendanceSource}'.",
                    ];

                    continue;
                }

                $normalizedRow['attendance_source'] = $attendanceSource;
            }

            /*
             * Meal eligibility
             */
            if (array_key_exists('is_meal_eligible', $row)) {
                $value = trim((string) $row['is_meal_eligible']);

                if ($value === '') {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'is_meal_eligible',
                        'message' => 'Meal eligibility cannot be empty when the column is provided.',
                    ];

                    continue;
                }

                $booleanValue = filter_var(
                    $value,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );

                if ($booleanValue === null) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'is_meal_eligible',
                        'message' => "Invalid meal eligibility value '{$value}'. Use true/false or 1/0.",
                    ];

                    continue;
                }

                $normalizedRow['is_meal_eligible'] = $booleanValue;
            }

            /*
             * Status
             */
            if (array_key_exists('status', $row)) {
                $status = strtolower(
                    trim((string) $row['status'])
                );

                if (! in_array($status, ['active', 'inactive'], true)) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'status',
                        'message' => "Invalid status '{$status}'.",
                    ];

                    continue;
                }

                $normalizedRow['status'] = $status;
            }

            $validRows[] = $normalizedRow;
        }

        return [
            'valid_rows' => $validRows,
            'errors' => $errors,
        ];
    }
}
