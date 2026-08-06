<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $record['register_number'] }} — Correspondence Record</title>
    <style>
        :root { color-scheme: light; --ink:#17221c; --muted:#59665f; --line:#ccd5cf; --brand:#075c37; --soft:#f1f5f2; }
        * { box-sizing: border-box; }
        body { margin:0; background:#eef1ef; color:var(--ink); font:13px/1.5 Arial, Helvetica, sans-serif; }
        .toolbar { position:sticky; top:0; z-index:2; display:flex; justify-content:flex-end; gap:10px; padding:12px 24px; background:#fff; border-bottom:1px solid var(--line); }
        .toolbar button { border:0; border-radius:6px; padding:10px 18px; cursor:pointer; font-weight:700; background:var(--brand); color:#fff; }
        .sheet { width:210mm; min-height:297mm; margin:18px auto; padding:17mm 16mm 20mm; background:#fff; box-shadow:0 6px 30px rgba(23,34,28,.12); }
        header { display:grid; grid-template-columns:72px 1fr 72px; align-items:center; padding-bottom:14px; border-bottom:3px double var(--brand); text-align:center; }
        header img { width:62px; height:auto; }
        header .country { margin:0; font-size:11px; letter-spacing:.12em; text-transform:uppercase; }
        header h1 { margin:3px 0; color:var(--brand); font-size:18px; text-transform:uppercase; }
        header h2 { margin:0; font-size:13px; font-weight:700; }
        .document-title { margin:18px 0 13px; text-align:center; }
        .document-title h3 { margin:0; font-size:15px; letter-spacing:.06em; text-transform:uppercase; }
        .document-title p { margin:3px 0 0; color:var(--muted); }
        .status { display:inline-block; margin-top:6px; padding:3px 9px; border:1px solid #8bb29d; border-radius:999px; color:var(--brand); font-size:10px; font-weight:700; text-transform:uppercase; }
        section { margin-top:17px; break-inside:avoid; }
        section h4 { margin:0 0 8px; padding-bottom:5px; border-bottom:1px solid var(--line); color:var(--brand); font-size:11px; letter-spacing:.08em; text-transform:uppercase; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:8px 24px; }
        .field { display:grid; grid-template-columns:115px 1fr; gap:8px; padding:3px 0; }
        .field b { color:var(--muted); font-size:10px; letter-spacing:.03em; text-transform:uppercase; }
        .summary { white-space:pre-wrap; padding:10px 12px; border-left:3px solid var(--brand); background:var(--soft); }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:7px 8px; border:1px solid var(--line); text-align:left; vertical-align:top; }
        th { background:var(--soft); color:var(--muted); font-size:10px; text-transform:uppercase; }
        .timeline { list-style:none; margin:0; padding:0; }
        .timeline li { position:relative; margin-left:7px; padding:0 0 14px 21px; border-left:1px solid var(--line); break-inside:avoid; }
        .timeline li::before { content:""; position:absolute; left:-4px; top:5px; width:7px; height:7px; border-radius:50%; background:var(--brand); }
        .event-head { display:flex; justify-content:space-between; gap:20px; font-weight:700; }
        .event-meta { color:var(--muted); font-size:11px; }
        .event-note { margin-top:4px; white-space:pre-wrap; }
        .empty { color:var(--muted); font-style:italic; }
        .certification { margin-top:22px; padding-top:10px; border-top:1px solid var(--line); color:var(--muted); font-size:10px; }
        .page-footer { display:none; }
        @page { size:A4; margin:13mm 12mm 16mm; }
        @media print {
            body { background:#fff; font-size:10pt; }
            .toolbar { display:none; }
            .sheet { width:auto; min-height:0; margin:0; padding:0 0 11mm; box-shadow:none; }
            a { color:inherit; text-decoration:none; }
            .page-footer { display:flex; position:fixed; right:0; bottom:-9mm; left:0; justify-content:space-between; padding-top:3mm; border-top:1px solid var(--line); color:var(--muted); font-size:8pt; }
            .page-number::after { content:"Page " counter(page); }
        }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Print correspondence</button></div>
    <main class="sheet">
        <header>
            <img src="{{ asset('images/moes-crest.jpg') }}" alt="Republic of Uganda coat of arms">
            <div>
                <p class="country">Republic of Uganda</p>
                <h1>{{ config('ats.ministry_full_name') }}</h1>
                <h2>Official Correspondence Record</h2>
            </div>
            <div></div>
        </header>

        <div class="document-title">
            <h3>{{ $record['subject'] }}</h3>
            <p>{{ $record['register_number'] }}@if($record['correspondence_reference']) · {{ $record['correspondence_reference'] }}@endif</p>
            <span class="status">{{ $record['correspondence_status'] }}</span>
        </div>

        <section>
            <h4>Correspondence details</h4>
            <div class="grid">
                <div class="field"><b>Original sender</b><span>{{ $record['sender_name'] }}@if($record['sender_organisation']) — {{ $record['sender_organisation'] }}@endif</span></div>
                <div class="field"><b>Originating office</b><span>{{ $record['office_name'] }}</span></div>
                <div class="field"><b>Date received</b><span>{{ $record['mail_date_label'] }}</span></div>
                <div class="field"><b>Letter date</b><span>{{ $record['letter_date_label'] }}</span></div>
                <div class="field"><b>Receiving office</b><span>{{ $record['recipient_name'] ?: '—' }}</span></div>
                <div class="field"><b>Recorded by</b><span>{{ $record['captured_by'] }} · {{ $record['captured_at_label'] }}</span></div>
                <div class="field"><b>Priority</b><span>{{ $record['priority'] }}</span></div>
                <div class="field"><b>Confidentiality</b><span>{{ $record['confidentiality'] }}</span></div>
            </div>
            @if($record['details'])<div class="summary">{{ $record['details'] }}</div>@endif
        </section>

        <section>
            <h4>Recipients and forwarding</h4>
            <table>
                <thead><tr><th>Type</th><th>Recipient</th><th>Requirement</th><th>Due date</th></tr></thead>
                <tbody>
                @forelse($record['primary_recipients'] as $recipient)
                    <tr><td>To</td><td>{{ $recipient['name'] }}@if($recipient['title'])<br><small>{{ $recipient['title'] }}</small>@endif</td><td>{{ str($recipient['purpose'])->replace('_', ' ')->title() }}</td><td>{{ $recipient['due_date_label'] }}</td></tr>
                @empty
                    <tr><td colspan="4" class="empty">No active primary recipients.</td></tr>
                @endforelse
                @foreach($record['cc_recipients'] as $recipient)
                    <tr><td>CC</td><td>{{ $recipient['name'] }}@if($recipient['title'])<br><small>{{ $recipient['title'] }}</small>@endif</td><td>For information only</td><td>—</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>

        <section>
            <h4>Attachments</h4>
            <table>
                <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Uploaded by</th><th>Version</th></tr></thead>
                <tbody>
                @forelse($record['attachments'] as $attachment)
                    <tr><td>{{ $attachment['filename'] }}</td><td>{{ $attachment['mime_type'] }}</td><td>{{ $attachment['size_label'] }}</td><td>{{ $attachment['uploaded_by'] }}</td><td>{{ $attachment['version_number'] ?? 1 }}</td></tr>
                @empty
                    <tr><td colspan="5" class="empty">No attachments recorded.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <section>
            <h4>Correspondence history</h4>
            <ol class="timeline">
                @forelse($record['activity_history'] as $event)
                    <li>
                        <div class="event-head"><span>{{ $event['author_name'] }}</span><span>{{ $event['recorded_at_label'] }}</span></div>
                        <div class="event-meta">{{ $event['author_title'] }} · {{ $event['author_office'] }}</div>
                        <div class="event-note">{{ $event['message'] }}</div>
                        @foreach($event['attachments'] as $attachment)
                            <div class="event-meta">Attachment: {{ $attachment['filename'] }}</div>
                        @endforeach
                    </li>
                @empty
                    <li class="empty">No correspondence messages have been recorded.</li>
                @endforelse
            </ol>
        </section>

        <p class="certification">Generated from the official correspondence register. Printed by {{ $printedBy->full_name }} ({{ $printedBy->title ?: $printedBy->roleName() }}) on {{ $printedAt->format('d/m/Y H:i') }}.</p>
        <footer class="page-footer"><span>{{ $record['register_number'] }} · {{ config('ats.ministry_short_name') }}</span><span class="page-number"></span></footer>
    </main>
</body>
</html>
