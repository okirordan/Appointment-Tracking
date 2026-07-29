<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Demo staff from the validated prototype dataset. Development-only:
     * production launch requires removing demo accounts (PRD §30).
     */
    public function run(): void
    {
        $deptIds = Department::pluck('id', 'code');

        $users = [
            ['full_name' => 'Amina Tumwesigye', 'title' => 'System Administrator', 'role' => 'sysadmin', 'dept' => null, 'username' => 'atumwesigye'],
            ['full_name' => 'Joseph Kaggwa', 'title' => 'Permanent Secretary', 'role' => 'ps', 'dept' => null, 'username' => 'jkaggwa'],
            ['full_name' => 'Miriam Achieng', 'title' => 'Registry Clerk', 'role' => 'clerk', 'dept' => null, 'username' => 'machieng'],
            ['full_name' => 'Grace Nakato', 'title' => 'Commissioner, Pre-Primary and Primary Education', 'role' => 'commissioner', 'dept' => 'PPPE', 'username' => 'gnakato'],
            ['full_name' => 'Robert Okello', 'title' => 'Commissioner, Secondary Education', 'role' => 'commissioner', 'dept' => 'SE', 'username' => 'rokello'],
            ['full_name' => 'Sarah Amongin', 'title' => 'Commissioner, Higher Education', 'role' => 'commissioner', 'dept' => 'HE', 'username' => 'samongin'],
            ['full_name' => 'Patricia Nambooze', 'title' => 'Commissioner, Finance and Administration', 'role' => 'commissioner', 'dept' => 'FA', 'username' => 'pnambooze'],
            ['full_name' => 'Esther Nabirye', 'title' => 'Secretary, Pre-Primary and Primary Education', 'role' => 'secretary', 'dept' => 'PPPE', 'username' => 'enabirye'],
            ['full_name' => 'Peter Ssemwogerere', 'title' => 'Secretary, Secondary Education', 'role' => 'secretary', 'dept' => 'SE', 'username' => 'pssemwogerere'],
            ['full_name' => 'Brenda Auma', 'title' => 'Education Officer', 'role' => 'officer', 'dept' => 'PPPE', 'username' => 'bauma'],
            ['full_name' => 'Daniel Kato', 'title' => 'Education Officer', 'role' => 'officer', 'dept' => 'SE', 'username' => 'dkato'],
            ['full_name' => 'Fiona Lubega', 'title' => 'Planning Officer', 'role' => 'officer', 'dept' => 'HE', 'username' => 'flubega'],
            ['full_name' => 'Moses Byaruhanga', 'title' => 'Sports Development Officer', 'role' => 'officer', 'dept' => 'PES', 'username' => 'mbyaruhanga'],
            ['full_name' => 'David Mugisha', 'title' => 'Commissioner, Physical Education and Sports', 'role' => 'commissioner', 'dept' => 'PES', 'username' => 'dmugisha'],
            ['full_name' => 'Christine Wanyana', 'title' => 'Finance Officer', 'role' => 'officer', 'dept' => 'FA', 'username' => 'cwanyana', 'active' => false],
        ];

        foreach ($users as $data) {
            User::firstOrCreate(['username' => $data['username']], [
                'full_name' => $data['full_name'],
                'title' => $data['title'],
                'role' => $data['role'],
                'department_id' => $data['dept'] === null ? null : $deptIds[$data['dept']],
                'active' => $data['active'] ?? true,
                'password' => 'Password@123',
                'password_changed_at' => now(),
            ]);
        }

        // Department heads per the prototype dataset.
        $heads = ['PPPE' => 'gnakato', 'SE' => 'rokello', 'HE' => 'samongin', 'PES' => 'dmugisha', 'FA' => 'pnambooze'];

        foreach ($heads as $code => $username) {
            $head = User::where('username', $username)->first();
            Department::where('code', $code)->update([
                'head_user_id' => $head->id,
                'head_name' => $head->full_name,
            ]);
        }
    }
}
