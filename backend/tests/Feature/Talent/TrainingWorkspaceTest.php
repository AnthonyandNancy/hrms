<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Talent\Models\TrainingEnrollment;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\TalentTestCase;

/**
 * Volume 3 task 15 — training workspace contract.
 *
 * The workspace needs program rows that carry the live capacity/mode facts the
 * list renders, capacity rejection that a form can map inline, and completion
 * that can never be recorded for a non-active enrolment.
 */
final class TrainingWorkspaceTest extends TalentTestCase
{
    /**
     * Training writes use the shared command/idempotency contract. Tests that
     * are not specifically about replay still need a valid unique key.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function trainingPost(string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers['Idempotency-Key'] ??= 'training-test-'.bin2hex(random_bytes(8));

        return $this->postJson($uri, $data, $headers);
    }

    public function test_program_projection_exposes_capacity_mode_and_live_enrollment_counts(): void
    {
        $course = $this->trainingPost('/v1/training/courses', [
            'code' => 'WS-COURSE-ONLINE',
            'title' => 'Synthetic online safety course',
            'delivery_mode' => 'ONLINE',
            'duration_minutes' => 90,
            'cost_cents' => 45600,
        ], ['Idempotency-Key' => 'ws-course-1'])->assertCreated();

        $program = $this->createProgram('WS-PROGRAM-1', 2);
        $programId = (int) $program->json('data.program_id');

        $session = $this->createSession($programId, 2, (int) $course->json('data.course_id'));
        $sessionId = (int) $session->json('data.session_id');

        $this->enroll($sessionId, 101)->assertCreated();

        $response = $this->getJson('/v1/training/programs?as_of=2026-11-25')
            ->assertOk()
            ->assertJsonPath('data.freshness', 'LIVE');

        /** @var list<array<string, mixed>> $items */
        $items = $response->json('data.items');
        $row = null;
        foreach ($items as $candidate) {
            if (($candidate['program_id'] ?? null) === $programId) {
                $row = $candidate;
                break;
            }
        }

        $this->assertIsArray($row);
        $this->assertSame('WS-PROGRAM-1', $row['code']);
        $this->assertSame('ACTIVE', $row['status']);
        $this->assertNull($row['category']);
        $this->assertSame('ONLINE', $row['delivery_mode']);
        $this->assertSame(2, $row['capacity']);
        $this->assertSame(1, $row['session_count']);
        $this->assertSame(1, $row['enrolled_count']);
        $this->assertSame(0, $row['completed_count']);
        $this->assertSame(1, $row['remaining_seats']);
        $this->assertSame(45600, $row['training_cost_cents']);
    }

    public function test_enrollment_beyond_capacity_is_rejected_with_a_stable_reason(): void
    {
        $program = $this->createProgram('WS-CAPACITY-1', 1);
        $session = $this->createSession((int) $program->json('data.program_id'), 1);
        $sessionId = (int) $session->json('data.session_id');

        $this->enroll($sessionId, 201)->assertCreated();

        $full = $this->enroll($sessionId, 202)
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRAINING_CAPACITY_REACHED')
            ->assertJsonPath('data.capacity', 1);

        $this->assertSame($sessionId, $full->json('data.session_id'));
        $this->assertSame(1, TrainingEnrollment::query()->where('session_id', $sessionId)->count());
    }

    public function test_completion_requires_an_existing_active_enrollment_and_records_the_completion_date(): void
    {
        $program = $this->createProgram('WS-COMPLETE-1', 5);
        $session = $this->createSession((int) $program->json('data.program_id'), 5);
        $sessionId = (int) $session->json('data.session_id');

        // No enrollment -> 404, never a silent completion.
        $this->trainingPost('/v1/training/enrollments/999999/complete', [
            'attended' => true,
            'completion_result' => 'PASS',
        ], ['Idempotency-Key' => 'ws-complete-unenrolled'])->assertNotFound();

        $enrollment = $this->enroll($sessionId, 301)->assertCreated();
        $enrollmentId = (int) $enrollment->json('data.enrollment_id');

        TrainingEnrollment::query()->whereKey($enrollmentId)->update(['status' => 'CANCELLED']);

        $this->trainingPost("/v1/training/enrollments/{$enrollmentId}/complete", [
            'attended' => true,
            'completion_result' => 'PASS',
        ], ['Idempotency-Key' => 'ws-complete-cancelled'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRAINING_ENROLLMENT_NOT_ACTIVE');

        TrainingEnrollment::query()->whereKey($enrollmentId)->update(['status' => 'ENROLLED']);

        $this->trainingPost("/v1/training/enrollments/{$enrollmentId}/complete", [
            'attended' => true,
            'completion_result' => 'PASS',
            'score' => 88,
            'completed_on' => '2026-12-05',
        ], ['Idempotency-Key' => 'ws-complete-active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.completion_result', 'PASS')
            ->assertJsonPath('data.score', 88);

        $completed = TrainingEnrollment::query()->whereKey($enrollmentId)->firstOrFail();
        $this->assertSame('COMPLETED', $completed->status);
        $this->assertSame('PASS', $completed->completion_result);
        $this->assertSame(
            '2026-12-05',
            CarbonImmutable::parse((string) $completed->completed_at)->setTimezone('Asia/Shanghai')->toDateString(),
        );
    }

    public function test_training_routes_require_authentication_and_the_documented_capabilities(): void
    {
        $program = $this->createProgram('WS-AUTH-1', 2);
        $session = $this->createSession((int) $program->json('data.program_id'), 2);
        $sessionId = (int) $session->json('data.session_id');
        $enrollmentId = (int) $this->enroll($sessionId, 401)->json('data.enrollment_id');

        $this->app->forgetInstance(Principal::class);
        $this->getJson('/v1/training/programs')->assertUnauthorized();

        $this->actAs(10);

        $this->authorizer->deniedCapabilities = ['training.program.read'];
        $this->getJson('/v1/training/programs')->assertForbidden();

        $this->authorizer->deniedCapabilities = ['training.program.manage'];
        $this->trainingPost('/v1/training/programs', [
            'code' => 'WS-AUTH-DENIED',
            'title' => 'Synthetic denied program',
        ], ['Idempotency-Key' => 'ws-auth-program-denied'])->assertForbidden();

        $this->authorizer->deniedCapabilities = ['training.enrollment.manage'];
        $this->trainingPost('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => 402,
        ], ['Idempotency-Key' => 'ws-auth-enrol-denied'])->assertForbidden();

        $this->authorizer->deniedCapabilities = ['training.completion.manage'];
        $this->trainingPost("/v1/training/enrollments/{$enrollmentId}/complete", [
            'completion_result' => 'PASS',
        ], ['Idempotency-Key' => 'ws-auth-complete-denied'])->assertForbidden();
    }

    /**
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function createProgram(string $code, int $capacity): TestResponse
    {
        return $this->trainingPost('/v1/training/programs', [
            'code' => $code,
            'title' => 'Synthetic workspace program '.$code,
            'default_capacity' => $capacity,
        ], ['Idempotency-Key' => 'ws-program-'.$code])->assertCreated();
    }

    /**
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function createSession(int $programId, int $capacity, ?int $courseId = null): TestResponse
    {
        return $this->trainingPost('/v1/training/sessions', [
            'program_id' => $programId,
            'course_id' => $courseId,
            'starts_on' => '2026-12-10',
            'ends_on' => '2026-12-10',
            'starts_at' => '2026-12-10T09:00:00Z',
            'ends_at' => '2026-12-10T17:00:00Z',
            'capacity' => $capacity,
            'location' => 'Synthetic training room',
        ], ['Idempotency-Key' => 'ws-session-'.$programId.'-'.$capacity.'-'.($courseId ?? 0)])->assertCreated();
    }

    /**
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function enroll(int $sessionId, int $employmentId): TestResponse
    {
        return $this->trainingPost('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => $employmentId,
        ], ['Idempotency-Key' => 'ws-enrol-'.$sessionId.'-'.$employmentId]);
    }
}
