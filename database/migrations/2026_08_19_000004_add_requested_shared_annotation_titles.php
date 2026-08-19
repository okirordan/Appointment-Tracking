<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private const ENTRIES = [
        'PS/ES',
        'PS/ES – Secretary',
        'MES/Secretary',
        'MSE/P',
        'MES/HE',
        'MSE/HE – Secretary',
        'MSE/P – Secretary',
        'MSE/S',
        'MSE/S – Secretary',
        'US/FA',
        'US/FA – Secretary',
        'PAS/FA',
        'PAS/FA – Secretary',
        'SAS/FA',
        'AC/CIM',
        'AC/CIM – Secretary',
        'ICT Helpdesk-Embassy',
        'AC/Accounts',
        'PIS/CIM',
        'C/EPPA',
        'C/HRM',
        'AC/Internal Audit',
        'AC/Policy-Analysis',
        'Sec. General UNESCO',
        'PHRO 2',
        'Ag.PE (Budget)',
        'Accounts INPUT',
        'POS',
        'PDU -Secretary',
        'UTS/ Registry',
        'UNESCO Secretary',
        'AC/PDU',
        'P/HR 1',
        'AC/SME',
        'Stores',
        'AC/Accounts Secretary',
        'Technical Team PS',
        'PPO/PDU',
        'AC/P&B',
        'AO/TO',
        'C/PES',
        'P/STAT',
        'Senior Accountant',
        'Secretary-CEPPA',
        'PAS/FA-2',
        'Senior Economist',
        'Security Embassy',
        'Security Registry',
        'Project Accountant',
        'D/HTVET-Secretary',
        'D/BE-Secretary',
        'C/BTVET',
        'C/TIET',
        'C/GSS',
        'C/HET',
        'AC/ITE',
        'AC/GSS',
        'Registry TIET',
        'Gender Unit',
        'BE-Secretary',
        'PSI-Secretary',
        'SNE-Secretary',
        'GSS-Secretary',
        'TIET-Secretary',
        'ICT/CIM-Legacy',
        'BTVET-Secretary',
        'D/BE',
        'C/ASSA',
        'D/HTVET',
        'C/G&C',
        'C/TVET O&M',
        'C/BE',
        'C/SNE',
        'C/ITTRI',
        'AC/GSE',
        'AC/Scholarships',
        'Sec/GSE-USE',
        'Head TVET Secretariat',
        'Sec/TVET-O&M',
        'Sec/G&C',
        'AC/Admissions',
    ];

    /** @var array<string, string> */
    private const KNOWN_FULL_TITLES = [
        'chrm' => 'Commissioner Human Resource Management',
        'cbe' => 'Commissioner Basic Education',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ENTRIES as $entry) {
            $directoryLabel = $this->fullTitle($entry);
            $shorthand = $this->displayShorthand($directoryLabel);
            $normalizedShorthand = $this->normalize($shorthand);
            $fullTitle = self::KNOWN_FULL_TITLES[$normalizedShorthand] ?? $directoryLabel;
            $normalizedFullTitle = $this->normalize($fullTitle);
            $existing = DB::table('annotation_titles')
                ->where('normalized_shorthand', $normalizedShorthand)
                ->first();

            if ($existing !== null) {
                DB::table('annotation_titles')
                    ->where('id', $existing->id)
                    ->update([
                        'shorthand' => $shorthand,
                        'active' => true,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            $matchingTitle = DB::table('annotation_titles')
                ->where('normalized_full_title', $normalizedFullTitle)
                ->first();

            if ($matchingTitle !== null) {
                DB::table('annotation_titles')
                    ->where('id', $matchingTitle->id)
                    ->update([
                        'shorthand' => $shorthand,
                        'normalized_shorthand' => $normalizedShorthand,
                        'active' => true,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('annotation_titles')->insert([
                'shorthand' => $shorthand,
                'normalized_shorthand' => $normalizedShorthand,
                'full_title' => $fullTitle,
                'normalized_full_title' => $normalizedFullTitle,
                'active' => true,
                'created_by_user_id' => null,
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Shared directory entries may be referenced by correspondence. Retain them on rollback.
    }

    private function displayShorthand(string $value): string
    {
        return Str::upper(preg_replace('/\s*([\/&-])\s*/u', '$1', $value) ?? $value);
    }

    private function fullTitle(string $value): string
    {
        $standardDashes = str_replace(['–', '—'], '-', $value);

        return preg_replace('/\s+/u', ' ', trim($standardDashes)) ?? trim($standardDashes);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii(trim($value)))) ?? '';
    }
};
