<?php

namespace Tests\Unit;

use App\Models\Visit;
use Tests\TestCase;

class VisitTransitionContractTest extends TestCase
{
    public function test_server_transitions_match_the_shared_mobile_contract(): void
    {
        $contract = json_decode(
            file_get_contents(base_path('../contracts/visit_transitions.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(Visit::TRANSITIONS, $contract);
    }
}
