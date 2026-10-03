<?php

namespace App\Domains\Auth\Repositories;

use App\Domains\Auth\Models\Curriculum;
use App\Domains\Auth\Models\Subject;
use App\Domains\Auth\Models\Teacher;
use App\Domains\Auth\Models\TeacherAvailability;
use App\Domains\Auth\Support\Weekday;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EloquentTeacherRepository implements TeacherRepositoryInterface
{
    public function find(int $id): ?Teacher
    {
        return Teacher::find($id);
    }

    public function findByPublicId(string $publicId): ?Teacher
    {
        return Teacher::where('public_id', $publicId)->first();
    }

    public function pendingVerification(): Collection
    {
        return Teacher::where('verification_status', 'pending')->get();
    }

    public function updateProfile(Teacher $teacher, array $data): Teacher
    {
        DB::transaction(function () use ($teacher, $data) {
            if (! empty($data['columns'])) {
                $teacher->update($data['columns']);
            }

            if (array_key_exists('subjects', $data) && $data['subjects'] !== null) {
                $teacher->subjects()->sync(
                    Subject::whereIn('code', $data['subjects'])->pluck('id')
                );
            }

            if (array_key_exists('curricula', $data) && $data['curricula'] !== null) {
                $teacher->curricula()->sync(
                    Curriculum::whereIn('code', $data['curricula'])->pluck('id')
                );
            }

            if (array_key_exists('availability', $data) && $data['availability'] !== null) {
                TeacherAvailability::where('teacher_id', $teacher->id)->delete();

                foreach ($data['availability'] as $slot) {
                    TeacherAvailability::create([
                        'teacher_id' => $teacher->id,
                        'day_of_week' => Weekday::toNumber($slot['day']),
                        'start_time' => $slot['starts_at'],
                        'end_time' => $slot['ends_at'],
                    ]);
                }
            }
        });

        return $teacher->fresh();
    }
}
