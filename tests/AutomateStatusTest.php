<?php

use App\AutomateStatus;
use PHPUnit\Framework\TestCase;

/**
 * AutomateStatus keeps per-request static state, so each test runs in its own
 * process to start from the pristine "never set" state.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AutomateStatusTest extends TestCase
{
    public function test_unset_status_is_a_server_error(): void
    {
        // A run that never recorded anything (stalled at an interactive step)
        // must not look like success.
        $this->assertSame('stalled', AutomateStatus::statusName());
        $this->assertSame(500, AutomateStatus::httpCode());
    }

    public function test_success_maps_to_200_and_carries_the_count(): void
    {
        AutomateStatus::success(12);
        $this->assertSame('ok', AutomateStatus::statusName());
        $this->assertSame(200, AutomateStatus::httpCode());
        $this->assertSame(12, AutomateStatus::payload()['transactions']);
    }

    public function test_zero_transactions_is_still_success(): void
    {
        AutomateStatus::success(0);
        $this->assertSame(200, AutomateStatus::httpCode());
        $this->assertSame(0, AutomateStatus::payload()['transactions']);
    }

    public function test_first_failure_wins(): void
    {
        AutomateStatus::fail('tan_required', 'TAN required', 'enter code');
        AutomateStatus::fail('importer_error', 'later error');
        $this->assertSame('tan_required', AutomateStatus::statusName());
        $this->assertSame('TAN required', AutomateStatus::payload()['error_header']);
    }

    public function test_success_does_not_override_a_prior_failure(): void
    {
        // A fatal error after a partial import must not be masked by a success.
        AutomateStatus::fail('importer_error', 'boom');
        AutomateStatus::success(5);
        $this->assertSame('importer_error', AutomateStatus::statusName());
        $this->assertSame(500, AutomateStatus::httpCode());
    }

    /**
     * @dataProvider statusCodeProvider
     */
    public function test_status_maps_to_http_code(string $status, int $expected): void
    {
        AutomateStatus::fail($status, 'header');
        $this->assertSame($expected, AutomateStatus::httpCode());
    }

    public function statusCodeProvider(): array
    {
        return [
            'tan_required'         => ['tan_required', 409],
            'tan_device_ambiguous' => ['tan_device_ambiguous', 409],
            'config_not_found'     => ['config_not_found', 404],
            'verification_failed'  => ['verification_failed', 422],
            'importer_error'       => ['importer_error', 500],
            'unknown status'       => ['something_new', 500],
        ];
    }

    public function test_payload_shape(): void
    {
        AutomateStatus::fail('config_not_found', 'Could not find the configuration', 'giro.json');
        $payload = AutomateStatus::payload();
        $this->assertSame(
            ['status', 'transactions', 'error_header', 'error_message'],
            array_keys($payload)
        );
        $this->assertSame('config_not_found', $payload['status']);
        $this->assertNull($payload['transactions']);
        $this->assertSame('giro.json', $payload['error_message']);
    }
}
