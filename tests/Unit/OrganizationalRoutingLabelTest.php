<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\OrganizationalUnit;
use App\Services\Mail\OrganizationalRoutingLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationalRoutingLabelTest extends TestCase
{
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
