<?php

namespace Tests\Feature;

use App\Models\AdditionalWorkApproval;
use App\Models\ClientPortalUser;
use App\Support\Quantity;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/**
 * 0.3.0 regression: at 390px the additional-work approval table squeezed its
 * columns until Arabic headers broke mid-word ("الإجمال ي", "السع ر"),
 * amounts wrapped ("460.0 / 0") and quantities read "1.000". The page body's
 * overflow-wrap:anywhere let every cell shrink to one character. Verified in
 * a browser at 390x844 on the demo data; here the markup and rules that
 * prevent it.
 */
class ClientPortalPhoneLayoutTest extends DarakTestCase
{
    private ClientPortalUser $portal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->portal = ClientPortalUser::create([
            'client_id' => $this->client->id,
            'name' => 'مدير الفرع',
            'email' => 'phone-layout@test.local',
            'password' => 'Strong-Client9!',
            'is_active' => true,
        ]);
    }

    public function test_additional_work_lines_stack_on_phones_with_whole_words_and_amounts(): void
    {
        $approval = AdditionalWorkApproval::create([
            'public_reference' => (string) Str::uuid(), 'visit_id' => $this->visit->id, 'client_id' => $this->client->id,
            'title' => 'استبدال موتور مروحة المكثف', 'description' => 'توقف الموتور بسبب احتراق الملفات.',
            'items' => [
                ['description' => 'موتور مروحة مكثف خارجي', 'qty' => 1, 'unit_price' => 460, 'line_total' => 460],
                ['description' => 'غاز فريون R404A (كجم)', 'qty' => 1.5, 'unit_price' => 120, 'line_total' => 180],
            ],
            'amount' => 640, 'vat_amount' => 96, 'total_amount' => 736,
            'status' => 'pending', 'sent_at' => now(), 'created_by' => $this->owner->id,
        ]);

        $html = $this->actingAs($this->portal, 'client')
            ->get(route('client.additional-work', $approval))
            ->assertOk()
            ->getContent();

        // A real table for wide screens and screen readers…
        $this->assertStringContainsString('<table class="lines" role="table">', $html);
        $this->assertStringContainsString('<th class="num" role="columnheader" scope="col">الإجمالي</th>', $html);
        // …whose lines become labelled cards on phones.
        $this->assertSame(6, substr_count($html, '<span class="cell-label" aria-hidden="true">'));
        $this->assertMatchesRegularExpression('/@media\(max-width:560px\)\{[^@]*\.lines,\.lines tbody,\.lines tfoot,\.lines tr,\.lines td\{display:block/', $html);
        $this->assertMatchesRegularExpression('/\.lines \.cell-label\{display:inline\}/', $html);

        // Quantities as written, amounts whole and on one line.
        $this->assertStringContainsString('<span class="num">1</span>', $html);
        $this->assertStringContainsString('<span class="num">1.5</span>', $html);
        $this->assertStringNotContainsString('1.000', $html);
        $this->assertStringContainsString('<span class="num">460.00 ر.س</span>', $html);
        $this->assertMatchesRegularExpression('/\.num\{white-space:nowrap;font-variant-numeric:tabular-nums\}/', $html);
        // Headings, labels and figures break between words only.
        $this->assertMatchesRegularExpression('/h1,h2,th,\.card>b,\.muted,\.num\{overflow-wrap:break-word;word-break:normal\}/', $html);
    }

    public function test_contract_and_quotation_codes_and_dates_stay_whole(): void
    {
        $html = $this->actingAs($this->portal, 'client')
            ->get(route('client.contract', $this->contract))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<h1>العقد <span class="num">'.e($this->contract->contract_no).'</span></h1>', $html);
        $this->assertStringContainsString('<b class="num">'.$this->contract->starts_on->format('Y-m-d').'</b>', $html);
    }

    public function test_only_a_lone_last_card_spans_the_phone_grid(): void
    {
        // A finished visit has two summary cards (no arrival estimate). Spanning
        // every last card put the second one full-width under a half-width first.
        $html = $this->actingAs($this->portal, 'client')
            ->get(route('client.visit', $this->visit))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('.grid .card:last-child:nth-child(odd){grid-column:1/-1}', $html);
        $this->assertStringNotContainsString('.grid .card:last-child{grid-column', $html);
    }

    public function test_quantities_drop_trailing_zeros_only(): void
    {
        $this->assertSame('1', Quantity::format('1.000'));
        $this->assertSame('1.5', Quantity::format(1.5));
        $this->assertSame('2.25', Quantity::format('2.250'));
        $this->assertSame('0.125', Quantity::format('0.125'));
        $this->assertSame('10', Quantity::format(10));
        $this->assertSame('100', Quantity::format('100.000'));
        $this->assertSame('1,250', Quantity::format(1250));
        $this->assertSame('—', Quantity::format(null));
    }
}
