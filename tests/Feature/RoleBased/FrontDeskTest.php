<?php

namespace Tests\Feature\RoleBased;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;
use App\Models\DocumentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Front Desk Role Tests
 *
 * Covers: Dashboard, Document Request Handling, Stress Testing
 */
class FrontDeskTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers, MeasuresPerformance;

    private function createDocumentRequest(string $empNo, array $overrides = []): DocumentRequest
    {
        return DocumentRequest::create(array_merge([
            'EmpNo'         => $empNo,
            'document_type' => 'Certificate of Employment',
            'purpose'       => 'Bank application',
            'status'        => 'Requested',
            'requested_on'  => now(),
        ], $overrides));
    }

    // ──────────────────────────────────────────────
    // 1. Dashboard
    // ──────────────────────────────────────────────

    public function test_front_desk_dashboard_loads(): void
    {
        $fd = $this->createFrontDesk();

        $response = $this->actingAs($fd)->get(route('front-desk.index'));

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────
    // 2. Request Handling
    // ──────────────────────────────────────────────

    public function test_fetch_requests(): void
    {
        $fd = $this->createFrontDesk();

        $response = $this->actingAs($fd)->get(route('front-desk.requests'));

        $response->assertStatus(200);
    }

    public function test_accept_document_request(): void
    {
        $fd = $this->createFrontDesk();
        $emp = $this->createEmployee();

        $docReq = $this->createDocumentRequest($emp->EmpNo);

        $response = $this->actingAs($fd)->post(route('front-desk.accept'), [
            'request_id' => $docReq->id,
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Accept request failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_reject_document_request(): void
    {
        $fd = $this->createFrontDesk();
        $emp = $this->createEmployee();

        $docReq = $this->createDocumentRequest($emp->EmpNo, ['purpose' => 'Rejection test']);

        $response = $this->actingAs($fd)->post(route('front-desk.reject'), [
            'request_id' => $docReq->id,
            'remarks'    => 'Invalid request',
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Reject request failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_complete_document_request(): void
    {
        $fd = $this->createFrontDesk();
        $emp = $this->createEmployee();

        $docReq = $this->createDocumentRequest($emp->EmpNo, ['status' => 'Accepted']);

        $response = $this->actingAs($fd)->post(route('front-desk.complete'), [
            'request_id' => $docReq->id,
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Complete request failed: HTTP {$response->getStatusCode()}"
        );
    }

    // ──────────────────────────────────────────────
    // 3. Stress Test - Continuous Input
    // ──────────────────────────────────────────────

    public function test_stress_continuous_request_handling(): void
    {
        $fd = $this->createFrontDesk();
        $successes = 0;
        $startTime = microtime(true);

        $types = ['Certificate of Employment', 'Service Record', 'Pay Slip Copy'];

        for ($i = 0; $i < 100; $i++) {
            $emp = $this->createEmployee();

            $docReq = $this->createDocumentRequest($emp->EmpNo, [
                'document_type' => $types[$i % 3],
                'purpose'       => "Stress test #{$i}",
            ]);

            try {
                $response = $this->actingAs($fd)->post(route('front-desk.accept'), [
                    'request_id' => $docReq->id,
                ]);

                if ($response->isSuccessful() || $response->isRedirection()) {
                    $response2 = $this->actingAs($fd)->post(route('front-desk.complete'), [
                        'request_id' => $docReq->id,
                    ]);

                    if ($response2->isSuccessful() || $response2->isRedirection()) {
                        $successes++;
                    }
                }
            } catch (\Throwable $e) {
                // Continue
            }
        }

        $elapsed = (microtime(true) - $startTime) * 1000;
        $rate = ($successes / 100) * 100;

        $this->assertGreaterThanOrEqual(80, $rate,
            "Front desk stress test: {$rate}% success ({$successes}/100) in {$elapsed}ms");
    }

    // ──────────────────────────────────────────────
    // 4. Print & Status
    // ──────────────────────────────────────────────

    public function test_update_status(): void
    {
        $fd = $this->createFrontDesk();
        $emp = $this->createEmployee();

        $docReq = $this->createDocumentRequest($emp->EmpNo, ['status' => 'Accepted']);

        $response = $this->actingAs($fd)->post(route('front-desk.update-status'), [
            'request_id' => $docReq->id,
            'status'     => 'Completed',
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Status update failed: HTTP {$response->getStatusCode()}"
        );
    }
}
