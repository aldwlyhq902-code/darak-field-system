<?php

namespace Tests\Unit;

use App\Support\BusinessReference;
use Tests\TestCase;

class BusinessReferenceTest extends TestCase
{
    public function test_references_do_not_depend_on_the_next_database_id(): void
    {
        $references = collect(range(1, 1000))
            ->map(fn () => BusinessReference::make('PO'));

        $this->assertCount(1000, $references->unique());
        $this->assertTrue($references->every(
            fn (string $reference) => preg_match('/^PO-\d{6}-[0-9A-HJKMNP-TV-Z]{10}$/', $reference) === 1
        ));
    }
}
