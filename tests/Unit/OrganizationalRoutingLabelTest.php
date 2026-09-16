<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\OrganizationalUnit;
use App\Services\Mail\OrganizationalRoutingLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationalRoutingLabelTest extends TestCase
{
    public function test_generic_head_office_uses_its_department_instead_of_an_ambiguous_label(): void
    {
        $department = new Department(['name' => 'Libraries, E-Learning and Information Technology', 'code' => 'LEIT']);
        $unit = new OrganizationalUnit(['name' => 'Office of the Commissioner', 'type' => 'unit']);
        $unit->setRelation('department', $department);
        $labels = app(OrganizationalRoutingLabel::class);
        $this->assertSame('Commissioner LEIT', $labels->headLabel($unit));
        $this->assertSame('Commissioner LEIT', $labels->headLabel($unit, 'Office of the Commissioner'));
        $unit->name = 'Office of the Assistant Commissioner';
        $this->assertSame('Assistant Commissioner LEIT', $labels->headLabel($unit));
        $department->code = 'NEW';
        $this->assertSame('Commissioner LEIT', $labels->headLabel($unit, 'Commissioner LEIT'));
        $this->assertSame('Historic handling office', $labels->headLabel($unit, 'Historic handling office'));
    }

    public function test_head_office_without_a_department_keeps_its_recorded_name(): void
    {
        $unit = new OrganizationalUnit(['name' => 'Office of the Permanent Secretary', 'type' => 'office']);
        $unit->setRelation('department', null);
        $unit->setRelation('parent', null);
        $this->assertSame('Office of the Permanent Secretary', app(OrganizationalRoutingLabel::class)->headLabel($unit));
    }

    #[DataProvider('executiveOfficeLabels')]
    public function test_it_uses_the_official_shorthand_for_executive_offices(
        string $storedCode,
        string $name,
        string $expected,
    ): void {
        $unit = new OrganizationalUnit([
            'type' => 'office',
            'code' => $storedCode,
            'name' => $name,
        ]);

        $this->assertSame($expected, app(OrganizationalRoutingLabel::class)->for($unit));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function executiveOfficeLabels(): iterable
    {
        yield 'Permanent Secretary' => ['OPS', 'Office of the Permanent Secretary', 'PS'];
        yield 'Minister' => ['OMES', 'Office of the Minister of Education and Sports', 'MES'];
        yield 'State Minister for Sports' => ['OSMS', 'Office of the Minister of State for Sports', 'MSE/S'];
        yield 'State Minister for Higher Education' => ['OSMHE', 'Office of the Minister of State for Higher Education', 'MSE/HE'];
        yield 'State Minister for Primary Education' => ['OSMPE', 'Office of the Minister of State for Primary Education', 'MSE/PE'];
    }

    public function test_it_derives_a_commissioner_title_from_the_canonical_department_code(): void
    {
        $department = new Department([
            'name' => 'Libraries, E-Learning and Information Technology',
            'code' => 'LEIT',
        ]);
        $unit = new OrganizationalUnit([
            'type' => 'department',
            'name' => $department->name,
            'code' => 'ORG-LEIT',
        ]);
        $unit->setRelation('department', $department);

        $this->assertSame('C/LEIT', app(OrganizationalRoutingLabel::class)->for($unit));
    }
}
