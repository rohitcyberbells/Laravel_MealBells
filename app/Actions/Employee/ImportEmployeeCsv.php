<?php

namespace App\Actions\Employee;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class ImportEmployeeCsv
{
    /**
     * Import validated employee rows for a company.
     *
     * Existing employees are updated only for fields
     * actually provided by the CSV.
     *
     * @return array{
     *     imported: int,
     *     updated: int,
     *     unchanged: int,
     *     errors: array
     * }
     */
    public function execute(Company $company, array $rows): array
    {
        $importedCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;
        $errors = [];

        if (empty($rows)) {
            return [
                'imported' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'errors' => [
                    [
                        'row' => null,
                        'field' => null,
                        'message' => 'No valid employee rows found.',
                    ],
                ],
            ];
        }

        /*
         * Normalize employee codes before querying.
         */
        $codes = collect($rows)
            ->pluck('employee_code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->unique()
            ->values();

        /*
         * Load existing employees in one query.
         */
        $existingEmployees = Employee::where('company_id', $company->id)
            ->whereIn('employee_code', $codes)
            ->get()
            ->keyBy(
                fn (Employee $employee) => strtoupper(
                    trim($employee->employee_code)
                )
            );

        DB::transaction(function () use (
            $company,
            $rows,
            $existingEmployees,
            &$importedCount,
            &$updatedCount,
            &$unchangedCount,
            &$errors
        ) {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                try {
                    $employeeCode = strtoupper(
                        trim((string) $row['employee_code'])
                    );

                    /*
                     * Existing employee
                     */
                    $employee = $existingEmployees->get($employeeCode);

                    if ($employee) {
                        $data = [
                            'name' => $row['name'],
                        ];

                        /*
                         * Only update fields that actually
                         * existed in the CSV.
                         */
                        if (array_key_exists('email', $row)) {
                            $data['email'] = $row['email'];
                        }

                        if (array_key_exists('attendance_source', $row)) {
                            $data['attendance_source'] = $row['attendance_source'];
                        }

                        if (array_key_exists('is_meal_eligible', $row)) {
                            $data['is_meal_eligible'] = $row['is_meal_eligible'];
                        }

                        if (array_key_exists('status', $row)) {
                            $data['status'] = $row['status'];
                        }

                        $employee->fill($data);

                        if ($employee->isDirty()) {
                            $employee->save();
                            $updatedCount++;
                        } else {
                            $unchangedCount++;
                        }

                        continue;
                    }

                    /*
                     * New employee defaults.
                     */
                    $employeeData = [
                        'company_id' => $company->id,
                        'employee_code' => $employeeCode,
                        'name' => $row['name'],

                        'email' => $row['email'] ?? null,

                        'attendance_source' => $row['attendance_source'] ?? 'manual',

                        'is_meal_eligible' => $row['is_meal_eligible'] ?? true,

                        'status' => $row['status'] ?? 'active',
                    ];

                    $employee = Employee::create($employeeData);

                    /*
                     * Keep map updated in case the same
                     * action instance is ever extended.
                     */
                    $existingEmployees->put($employeeCode, $employee);

                    $importedCount++;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => null,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        });

        return [
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'unchanged' => $unchangedCount,
            'errors' => $errors,
        ];
    }
}
