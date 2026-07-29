<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            DepartmentSeeder::class,
            ApprovedMinistryStructureSeeder::class,
            UserSeeder::class,
            CimStaffSeeder::class,
            SecretaryOfficeAttachmentSeeder::class,
            RecipientAliasSeeder::class,
            MoesIncomingMailSeeder::class,
            MailManagerIncomingMailSeeder::class,
            MailManagerIncomingMhtmlSeeder::class,
            MailManagerOutgoingMailSeeder::class,
        ]);
    }
}
