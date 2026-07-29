<?php

namespace Database\Seeders;

use App\Enums\AssignmentLevel;
use App\Enums\TaskStatus;
use App\Models\EvidenceAttachment;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Demo assignments ported from the validated prototype dataset
 * (design-reference/ats-mock-data.js). Development/demo only.
 */
class TaskSeeder extends Seeder
{
    public function run(): void
    {
        if (Task::exists()) {
            return;
        }

        $users = User::pluck('id', 'username');
        $year = now()->year;

        // [ref-suffix, title, level, by, to, priority, dueOffsetDays, status, description]
        $rows = [
            ['PS', 14, 'National Curriculum Review Report', 'ps', 'jkaggwa', 'gnakato', 'urgent', -6, 'in_progress', 'Compile the mid-year curriculum review findings for Cabinet submission.'],
            ['PS', 15, 'UNEB Results Analysis Brief', 'ps', 'jkaggwa', 'rokello', 'high', -2, 'awaiting_review', 'Prepare a brief on UCE/UACE performance trends for the Minister.'],
            ['PS', 16, 'School Feeding Programme Rollout Plan', 'ps', 'jkaggwa', 'samongin', 'medium', 12, 'in_progress', 'Draft the phased rollout plan for the school feeding programme.'],
            ['PS', 17, 'Teacher Recruitment Quota Submission', 'ps', 'jkaggwa', 'machieng', 'high', -10, 'pending', 'Consolidate district recruitment quotas for FY2026/27.'],
            ['PS', 18, 'Regional Sports Facilities Audit', 'ps', 'jkaggwa', 'dmugisha', 'medium', 20, 'assigned', 'Audit condition of regional sports facilities ahead of budget planning.'],
            ['PS', 19, 'Budget Framework Paper Input', 'ps', 'jkaggwa', 'pnambooze', 'urgent', -1, 'in_progress', 'Provide Ministry input into the national Budget Framework Paper.'],
            ['PS', 20, 'Higher Education Scholarship List', 'ps', 'machieng', 'samongin', 'medium', 5, 'received', 'Finalise the government sponsorship scholarship list for approval.'],
            ['PS', 21, 'Cabinet Memo: Universal Secondary Education', 'ps', 'jkaggwa', 'rokello', 'high', 3, 'in_progress', 'Draft Cabinet memo on USE enrollment expansion.'],
            ['PS', 22, 'Inspectorate Annual Report', 'ps', 'jkaggwa', 'gnakato', 'low', 30, 'assigned', 'Compile the annual school inspectorate report.'],
            ['PS', 23, 'Parliamentary Response: Teacher Housing', 'ps', 'jkaggwa', 'machieng', 'urgent', -3, 'awaiting_review', 'Draft ministerial response to Parliamentary question on teacher housing.'],
            ['BSE', 41, 'Textbook Distribution Verification', 'department', 'gnakato', 'bauma', 'high', -4, 'in_progress', 'Verify textbook distribution records across Central region primary schools.'],
            ['BSE', 42, 'PLE Registration Data Cleanup', 'department', 'gnakato', 'enabirye', 'medium', 8, 'pending', 'Clean and validate PLE candidate registration data.'],
            ['BSE', 43, 'School Health Programme Evidence Pack', 'department', 'enabirye', 'bauma', 'low', 15, 'assigned', 'Assemble evidence pack for the school health programme review.'],
            ['SED', 30, 'USE Enrollment Verification — Wakiso', 'department', 'rokello', 'dkato', 'high', -1, 'awaiting_review', 'Verify USE enrollment figures submitted by Wakiso district.'],
            ['SED', 31, 'Secondary Science Kits Distribution', 'department', 'pssemwogerere', 'dkato', 'medium', 6, 'in_progress', 'Track distribution of science kits to secondary schools.'],
            ['SED', 32, 'UCE Practical Exam Logistics', 'department', 'rokello', 'pssemwogerere', 'urgent', -8, 'pending', 'Coordinate logistics for UCE practical examinations.'],
            ['HED', 18, 'University Grants Reconciliation', 'department', 'samongin', 'flubega', 'high', 4, 'in_progress', 'Reconcile capitation grants disbursed to public universities.'],
            ['HED', 19, 'Student Loan Scheme Applications Review', 'department', 'samongin', 'flubega', 'medium', -5, 'awaiting_review', 'Review pending student loan scheme applications for compliance.'],
            ['SPT', 11, 'National Schools Athletics Championship Plan', 'department', 'dmugisha', 'mbyaruhanga', 'medium', 18, 'in_progress', 'Finalise logistics plan for the national schools athletics championship.'],
            ['SPT', 12, 'Sports Equipment Procurement Request', 'department', 'dmugisha', 'mbyaruhanga', 'low', -15, 'pending', 'Prepare procurement request for regional sports equipment.'],
            ['FIN', 27, 'Q3 Departmental Expenditure Return', 'department', 'pnambooze', 'cwanyana', 'high', -2, 'in_progress', 'Prepare the Q3 departmental expenditure return for audit.'],
            ['FIN', 28, 'Fleet Maintenance Cost Review', 'department', 'pnambooze', 'cwanyana', 'low', 25, 'assigned', 'Review ministry fleet maintenance costs for the quarter.'],
            ['BSE', 39, 'Early Childhood Development Survey', 'department', 'gnakato', 'bauma', 'medium', -20, 'completed', 'Conduct the annual ECD access survey across pilot districts.'],
            ['PS', 11, 'Teacher Payroll Reconciliation', 'ps', 'jkaggwa', 'pnambooze', 'high', -25, 'completed', 'Reconcile teacher payroll records with the national IPPS system.'],
        ];

        foreach ($rows as $index => [$prefix, $seq, $title, $level, $by, $to, $priority, $dueOffset, $status, $description]) {
            $assignee = User::find($users[$to]);
            $assigner = User::find($users[$by]);
            $due = now()->addDays($dueOffset);
            $statusEnum = TaskStatus::from($status);

            $task = Task::create([
                'reference' => sprintf('%s-%d-%03d', $prefix, $year, $seq),
                'title' => $title,
                'description' => $description,
                'assignment_level' => $level === 'ps' ? AssignmentLevel::Ps->value : AssignmentLevel::Department->value,
                'assigned_by_user_id' => $assigner->id,
                'assigned_to_user_id' => $assignee->id,
                'assigned_to_name_snapshot' => $assignee->full_name,
                'department_id' => $assignee->department_id,
                'priority' => $priority,
                'due_date' => $due->toDateString(),
                'original_due_date' => $due->toDateString(),
                'workflow_status' => $statusEnum->value,
                'progress_percent' => $statusEnum->suggestedProgress(),
                'completed_at' => $statusEnum === TaskStatus::Completed ? $due->copy()->subDays(2) : null,
            ]);

            $this->seedHistory($task, $assigner, $assignee, $statusEnum, $due);

            if ($index % 4 === 0) {
                TaskHistory::create([
                    'task_id' => $task->id,
                    'action_type' => 'Annotated',
                    'note' => 'Please prioritise this — needed for the next executive briefing.',
                    'performed_by_user_id' => $assigner->id,
                    'performed_by_name_snapshot' => $assigner->full_name,
                    'performed_by_role' => $assigner->role->value,
                    'created_at' => $due->copy()->subDays(10),
                ]);
            }

            if (in_array($statusEnum, [TaskStatus::AwaitingReview, TaskStatus::Completed], true)) {
                $this->seedEvidence($task, $assignee);
            }

            // Keep list ordering stable relative to the story timeline.
            $task->timestamps = false;
            $task->forceFill([
                'created_at' => $due->copy()->subDays(18),
                'updated_at' => $due->copy()->subDays(random_int(0, 6)),
            ])->save();
        }
    }

    private function seedHistory(Task $task, User $assigner, User $assignee, TaskStatus $current, \Illuminate\Support\Carbon $due): void
    {
        $write = function (string $action, ?TaskStatus $status, ?int $progress, string $note, User $by, int $daysBeforeDue) use ($task, $due) {
            TaskHistory::create([
                'task_id' => $task->id,
                'action_type' => $action,
                'note' => $note,
                'status' => $status?->value,
                'progress_percent' => $progress,
                'performed_by_user_id' => $by->id,
                'performed_by_name_snapshot' => $by->full_name,
                'performed_by_role' => $by->role->value,
                'created_at' => $due->copy()->subDays($daysBeforeDue),
            ]);
        };

        $write('Created', TaskStatus::Assigned, 0, 'Task created and issued.', $assigner, 18);

        $ladder = [
            [TaskStatus::Received, 'Acknowledged receipt of the assignment.', 16],
            [TaskStatus::InProgress, 'Work has commenced; gathering inputs from field offices.', 13],
            [TaskStatus::Pending, 'Paused pending clarification from a partner department.', 10],
            [TaskStatus::AwaitingReview, 'Submitted for supervisor review with supporting evidence.', 7],
            [TaskStatus::Completed, 'Marked complete and accepted.', 2],
        ];

        foreach ($ladder as [$status, $note, $daysBeforeDue]) {
            $reached = match ($current) {
                TaskStatus::Assigned, TaskStatus::Created => false,
                TaskStatus::Received => $status === TaskStatus::Received,
                TaskStatus::InProgress => in_array($status, [TaskStatus::Received, TaskStatus::InProgress], true),
                TaskStatus::Pending => in_array($status, [TaskStatus::Received, TaskStatus::InProgress, TaskStatus::Pending], true),
                TaskStatus::AwaitingReview => $status !== TaskStatus::Completed,
                TaskStatus::Completed, TaskStatus::Archived => true,
            };

            if ($reached) {
                $write($status === TaskStatus::Completed ? 'Completed' : 'Progress Updated',
                    $status, $status->suggestedProgress(), $note, $assignee, $daysBeforeDue);
            }
        }
    }

    private function seedEvidence(Task $task, User $assignee): void
    {
        $filename = strtolower($task->reference).'-report.pdf';
        $key = $task->id.'/'.$filename;
        $content = "%PDF-1.4\n% ATS demo evidence for {$task->reference}\n%%EOF\n";

        Storage::disk('evidence')->put($key, $content);

        EvidenceAttachment::create([
            'task_id' => $task->id,
            'original_filename' => $filename,
            'storage_key' => $key,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'checksum' => hash('sha256', $content),
            'uploaded_by_user_id' => $assignee->id,
            'uploaded_at' => now()->subDays(3),
        ]);
    }
}
