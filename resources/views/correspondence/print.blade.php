<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $record['register_number'] }} — Correspondence Record</title>
    <link rel="stylesheet" href="{{ asset('fonts/ubuntu/ubuntu.css') }}">
    <style>
        :root { color-scheme:light; --ink:#24221e; --muted:#686359; --line:#d9d3c6; --gold:#856b20; --soft:#faf7ee; --red:#982d2b; }
        * { box-sizing:border-box; }
        body { margin:0; background:#f2f0eb; color:var(--ink); font:400 1rem/1.5 'Ubuntu',sans-serif; }
        .toolbar { position:sticky; top:0; z-index:2; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:12px 24px; background:#fff; border-bottom:1px solid var(--line); }
        .toolbar span { color:var(--muted); font-size:12px; }
        .toolbar button { border:1px solid var(--ink); padding:10px 18px; background:var(--ink); color:white; cursor:pointer; font:inherit; font-weight:700; }
        .toolbar button:focus-visible { outline:3px solid var(--gold); outline-offset:3px; }
        .sheet { width:210mm; margin:20px auto; padding:14mm 15mm 18mm; background:#fff; border:1px solid var(--line); }
        .flag-rule { display:grid; grid-template-columns:repeat(3,1fr); height:4px; margin-bottom:20px; }
        .flag-rule i:nth-child(1) { background:#222; } .flag-rule i:nth-child(2) { background:#d5ae30; } .flag-rule i:nth-child(3) { background:var(--red); }
        .masthead { display:grid; grid-template-columns:60px 1fr; gap:18px; align-items:center; padding-bottom:17px; border-bottom:1px solid var(--line); }
        .masthead img { width:56px; height:auto; }
        .country { margin:0; color:var(--muted); font-size:10px; text-transform:uppercase; letter-spacing:.1em; }
        .masthead h1 { margin:3px 0; font-size:2.25rem; font-weight:700; line-height:1.2; text-transform:uppercase; }
        .masthead p:last-child { margin:0; font-size:12px; }
        .document-title { padding:20px 0 16px; border-bottom:1px solid var(--line); }
        .reference { display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; font-size:11px; }
        .document-title h2 { margin:10px 0; font-size:1.5rem; font-weight:500; line-height:1.3; overflow-wrap:anywhere; }
        .status { display:inline-block; border-left:3px solid var(--gold); padding:2px 8px; font-size:11px; font-weight:700; background:var(--soft); }
        section { margin-top:22px; }
        section h3 { margin:0 0 10px; padding-bottom:7px; border-bottom:1px solid var(--gold); font-size:12px; letter-spacing:.05em; text-transform:uppercase; break-after:avoid; }
        .section-number { margin-right:8px; color:var(--gold); }
        .origin-grid { display:grid; grid-template-columns:1fr 1fr; border:1px solid var(--line); }
        .origin-cell { padding:12px; border-bottom:1px solid var(--line); min-width:0; }
        .origin-cell:nth-child(odd) { border-right:1px solid var(--line); }
        .origin-cell:nth-last-child(-n+2) { border-bottom:0; }
        .origin-cell small,.field dt { display:block; color:var(--muted); font-size:10px; text-transform:uppercase; letter-spacing:.04em; }
        .origin-cell strong { display:block; margin-top:4px; font-size:13px; overflow-wrap:anywhere; }
        .origin-cell:first-child { border-left:3px solid var(--ink); }
        .origin-cell:last-child { border-left:3px solid var(--gold); background:var(--soft); }
        .route-note { margin:10px 0 0; color:var(--muted); font-size:11px; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 24px; }
        .field { margin:0; min-width:0; } .field dd { margin:3px 0 0; overflow-wrap:anywhere; }
        .summary { white-space:pre-wrap; padding:11px 13px; border-left:3px solid var(--gold); background:var(--soft); overflow-wrap:anywhere; }
        table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:11px; }
        th,td { padding:8px; border:1px solid var(--line); text-align:left; vertical-align:top; overflow-wrap:anywhere; }
        th { background:var(--soft); color:var(--muted); font-size:9px; text-transform:uppercase; letter-spacing:.03em; }
        thead { display:table-header-group; } tr { break-inside:avoid; } td small { display:block; color:var(--muted); }
        .timeline { list-style:none; margin:14px 0 0; padding:0; }
        .event { display:grid; grid-template-columns:35px 1fr; gap:12px; padding-bottom:16px; position:relative; }
        .event::before { content:""; position:absolute; top:28px; bottom:0; left:16px; border-left:1px solid var(--line); }
        .event:last-child::before { display:none; }
        .event-number { position:relative; z-index:1; height:28px; border:1px solid var(--gold); background:white; text-align:center; line-height:26px; font-size:11px; font-weight:bold; }
        .event-card { min-width:0; border:1px solid var(--line); padding:12px; }
        .event-head { display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap; padding-bottom:8px; border-bottom:1px solid var(--line); break-after:avoid; }
        .event-head h4 { margin:0; font-size:12px; } .event-head time { color:var(--muted); font-size:10px; }
        .event-route { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin:9px 0; }
        .event-route small,.event-label { display:block; color:var(--muted); font-size:9px; text-transform:uppercase; letter-spacing:.04em; }
        .event-route strong { font-size:11px; font-weight:600; overflow-wrap:anywhere; }
        .event-actor { margin:8px 0; font-size:11px; } .event-actor span { color:var(--muted); }
        .event-note { margin:9px 0 0; white-space:pre-wrap; overflow-wrap:anywhere; font-size:1rem; }
        .event-outcome { margin:9px 0 0; padding:7px 9px; background:var(--soft); border-left:2px solid var(--gold); font-size:10px; }
        .event-meta { margin:6px 0 0; color:var(--muted); font-size:10px; overflow-wrap:anywhere; }
        .event-alert .event-number { border-color:var(--red); color:var(--red); } .event-alert .event-outcome { border-color:var(--red); }
        .event-attachments { margin:8px 0 0; padding-left:16px; font-size:10px; }
        .empty { color:var(--muted); font-style:italic; }
        .certification { margin-top:22px; padding-top:10px; border-top:1px solid var(--line); color:var(--muted); font-size:10px; }
        .page-footer { display:none; }
        .country, .route-note, .origin-cell small, .field dt, .event-label, .event-route small, .event-meta, .event-head time, .toolbar span, td small {
            font-size:0.875rem; font-weight:300; letter-spacing:0.05em; line-height:1.5;
        }
        @page { size:A4; margin:14mm 14mm 18mm; }
        @media screen and (max-width:820px) { .sheet { width:calc(100% - 24px); margin:12px; padding:24px; } }
        @media screen and (max-width:520px) { .origin-grid,.grid,.event-route { grid-template-columns:1fr; } .origin-cell:nth-child(odd) { border-right:0; } .origin-cell:nth-last-child(2) { border-bottom:1px solid var(--line); } .toolbar span { display:none; } }
        @media print {
            body { background:#fff; font-size:12pt; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .toolbar { display:none; } .sheet { width:auto; margin:0; padding:0; border:0; }
            .event { break-inside:avoid; } .event-note { orphans:3; widows:3; } .origin-grid,.masthead { break-inside:avoid; }
            .page-footer { display:flex; position:fixed; right:0; bottom:-11mm; left:0; justify-content:space-between; border-top:1px solid var(--line); padding-top:3mm; color:var(--muted); font-size:8pt; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><span>Correspondence flow · A4 print record</span><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
    <main class="sheet">
        <div class="flag-rule" aria-hidden="true"><i></i><i></i><i></i></div>
        <header class="masthead"><img src="{{ asset('images/moes-crest.jpg') }}" alt="Republic of Uganda coat of arms"><div><p class="country">Republic of Uganda</p><h1>{{ config('ats.ministry_full_name') }}</h1><p>Official Correspondence Record</p></div></header>
        <div class="document-title">
            <div class="reference"><strong>{{ $record['register_number'] }}</strong><span>{{ ucfirst($mail->direction) }} correspondence</span></div>
            <h2>{{ $record['subject'] }}</h2><span class="status">Current status: {{ $record['correspondence_status'] }}</span>
            @if($record['correspondence_reference'])<p class="route-note">Letter reference: {{ $record['correspondence_reference'] }}</p>@endif
        </div>
        <section aria-labelledby="origin-heading">
            <h3 id="origin-heading"><span class="section-number">01</span>Origin and current handling</h3>
            <div class="origin-grid">
                <div class="origin-cell"><small>Original source</small><strong>{{ $record['provenance']['original_source'] ?: 'Not recorded' }}</strong></div>
                <div class="origin-cell"><small>Originally addressed to</small><strong>{{ $record['provenance']['original_addressee'] ?: 'Not recorded' }}</strong></div>
                <div class="origin-cell"><small>First received by</small><strong>{{ $record['provenance']['received_by'] ?: 'Not recorded' }}</strong></div>
                <div class="origin-cell"><small>Current handling location(s)</small><strong>{{ implode('; ', $record['provenance']['current_locations']) ?: 'No active handling destination recorded' }}</strong>@if(!empty($record['provenance']['current_handlers']))<p class="event-meta"><b>Responsible officers:</b> {{ implode('; ', $record['provenance']['current_handlers']) }}</p>@endif</div>
            </div>
            <p class="route-note"><b>Received through:</b> {{ implode(' → ', $record['provenance']['received_through']) ?: 'Not recorded' }}. The numbered history below records individual movements and actions.</p>
        </section>
        <section aria-labelledby="details-heading">
            <h3 id="details-heading"><span class="section-number">02</span>Registration details</h3>
            <div class="grid">
                <dl class="field"><dt>{{ $mail->direction === 'outgoing' ? 'Date sent' : 'Date received' }}</dt><dd>{{ $record['mail_date_label'] ?: 'Not recorded' }}</dd></dl>
                <dl class="field"><dt>Letter date</dt><dd>{{ $record['letter_date_label'] ?: 'Not recorded' }}</dd></dl>
                <dl class="field"><dt>Recorded by</dt><dd>{{ $record['captured_by'] }} · {{ $record['captured_at_label'] }}</dd></dl>
                <dl class="field"><dt>Priority / confidentiality</dt><dd>{{ $record['priority'] }} / {{ $record['confidentiality'] }}</dd></dl>
            </div>
            @if($record['details'])<p class="summary">{{ $record['details'] }}</p>@endif
        </section>
        <section aria-labelledby="recipients-heading">
            <h3 id="recipients-heading"><span class="section-number">03</span>Active recipients and responsibilities</h3>
            <table><thead><tr><th style="width:10%">Type</th><th style="width:44%">Recipient / officer title</th><th style="width:28%">Purpose</th><th style="width:18%">Due date</th></tr></thead><tbody>
                @forelse(array_merge($record['primary_recipients'], $record['cc_recipients']) as $recipient)
                    <tr><td>{{ in_array($recipient, $record['cc_recipients'], true) ? 'CC' : 'To' }}</td><td><strong>{{ $recipient['name'] }}</strong>@if($recipient['title'])<small>{{ $recipient['title'] }}</small>@endif</td><td>{{ str($recipient['purpose'] ?? 'information')->replace('_', ' ')->ucfirst() }}</td><td>{{ $recipient['due_date_label'] ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="empty">No active recipients recorded. Previous recipients remain in the history below.</td></tr>
                @endforelse
            </tbody></table>
        </section>
        <section aria-labelledby="history-heading">
            <h3 id="history-heading"><span class="section-number">04</span>Mail flow and action history</h3>
            <p class="route-note">Chronological order · Each number identifies a recorded event. Dates reflect the event time where available; later recording times are shown separately.</p>
            <ol class="timeline">
                @forelse($record['movement_timeline'] as $event)
                    @php
                        $type = str($event['type'])->lower()->replace(' ', '_')->toString();
                        $labels = ['registered' => 'Mail received / registered', 'original_addressee' => 'Original addressee recorded', 'forwarded' => 'Forwarded', 'copied' => 'Copied for information', 'received' => 'Receipt recorded', 'assigned' => 'Responsibility assigned', 'annotation' => 'Instruction / annotation', 'annotated' => 'Instruction / annotation', 'note' => 'Note recorded', 'recipient_removed' => 'Recipient withdrawn', 'submitted_for_review' => 'Submitted for review', 'review_approve' => 'Review approved', 'review_return' => 'Returned for correction', 'review_reject' => 'Review rejected', 'review_request_information' => 'Further information requested', 'progress_updated' => 'Progress updated', 'status_change' => 'Status changed', 'filed' => 'Filed', 'reopened' => 'Reopened'];
                        $actionLabel = $labels[$type] ?? str($event['type'])->replace('_', ' ')->ucfirst()->toString();
                        if ($type === 'returned_to_ps') $actionLabel = 'Returned to the Office of the Permanent Secretary';
                        if ($type === 'registered' && $mail->direction === 'outgoing' && $mail->source_mail_record_id === null) $actionLabel = 'Outgoing mail registered';
                        if (!empty($event['automatic_receipt'])) $actionLabel = 'Delivery recorded by system';
                        $actorLabel = match ($type) { 'forwarded' => 'Forwarded by', 'copied' => 'Copied by', 'assigned' => 'Assigned by', 'registered', 'original_addressee' => 'Recorded by', 'received' => !empty($event['automatic_receipt']) ? 'Recorded by' : 'Received by', 'recipient_removed' => 'Withdrawn by', default => 'Action by' };
                        $alert = in_array($type, ['recipient_removed', 'unassigned', 'withdrawn', 'review_reject', 'review_return', 'rejected'], true);
                    @endphp
                    <li class="event {{ $alert ? 'event-alert' : '' }}" data-event-id="{{ $event['id'] }}">
                        <span class="event-number" aria-label="Event {{ $loop->iteration }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        <article class="event-card">
                            <div class="event-head"><h4>{{ $actionLabel }}</h4><time @if($event['at']) datetime="{{ $event['at'] }}" @endif>{{ $event['at_label'] ?: 'Date not recorded' }}</time></div>
                            @if($event['from'] || $event['to'])<div class="event-route">
                                @if($event['from'])<div><small>From / originating office</small><strong>{{ $event['from'] }}</strong></div>@endif
                                @if($event['to'])<div><small>To / receiving office or officer</small><strong>{{ $event['to'] }}</strong></div>@endif
                            </div>@endif
                            @if(!empty($event['recipient_name']))<p class="event-meta"><b>Named recipient:</b> {{ $event['recipient_name'] }}@if(!empty($event['recipient_title'])) · {{ $event['recipient_title'] }}@endif</p>
                            @elseif(!empty($event['recipient_title']))<p class="event-meta"><b>Officer title at assignment:</b> {{ $event['recipient_title'] }}@if(!empty($event['recipient_office'])) · {{ $event['recipient_office'] }}@endif</p>@endif
                            <p class="event-actor"><b>{{ $actorLabel }}:</b> {{ $event['by'] ?: 'Officer not recorded' }}@if(!empty($event['actor_title']))<span> · {{ $event['actor_title'] }}</span>@endif</p>
                            @if(!empty($event['reference']))<p class="event-meta"><b>Assignment:</b> {{ $event['reference'] }}</p>@endif
                            @if($event['instructions'])<div class="event-note"><span class="event-label">Instruction / action recorded</span>{{ $event['instructions'] }}</div>@endif
                            @if(!empty($event['status_from']) || !empty($event['status_to']))<p class="event-outcome"><b>Status:</b> @if(!empty($event['status_from'])){{ str($event['status_from'])->replace('_', ' ')->ucfirst() }} → @endif{{ str($event['status_to'] ?? 'Not recorded')->replace('_', ' ')->ucfirst() }}@if(isset($event['progress'])) · Progress {{ $event['progress'] }}%@endif</p>
                            @elseif(isset($event['progress']))<p class="event-outcome"><b>Progress recorded:</b> {{ $event['progress'] }}%</p>@endif
                            @if(!empty($event['responsibility_status']))<p class="event-meta"><b>Responsibility status at printing:</b> {{ str($event['responsibility_status'])->replace('_', ' ')->ucfirst() }}{{ !empty($event['is_current']) ? ' · Current holder' : '' }}</p>@endif
                            @if(!empty($event['purpose']) || !empty($event['due_at_label']))<p class="event-meta">@if(!empty($event['purpose']))<b>Purpose:</b> {{ str($event['purpose'])->replace('_', ' ')->ucfirst() }}@endif @if(!empty($event['due_at_label'])) · <b>Due:</b> {{ $event['due_at_label'] }}@endif</p>@endif
                            @if(!empty($event['recorded_at_label']) && $event['recorded_at_label'] !== $event['at_label'])<p class="event-meta"><b>Recorded in ATS:</b> {{ $event['recorded_at_label'] }}</p>@endif
                            @if(!empty($event['attachments']))<ul class="event-attachments">@foreach($event['attachments'] as $attachment)<li><b>Supporting file:</b> {{ $attachment['filename'] }}</li>@endforeach</ul>@endif
                        </article>
                    </li>
                @empty
                    <li class="empty">No movement or action events have been recorded.</li>
                @endforelse
            </ol>
        </section>
        <section aria-labelledby="attachments-heading">
            <h3 id="attachments-heading"><span class="section-number">05</span>Document attachments</h3>
            <table><thead><tr><th style="width:45%">File</th><th style="width:15%">Size</th><th style="width:30%">Uploaded by</th><th style="width:10%">Version</th></tr></thead><tbody>
                @forelse($record['attachments'] as $attachment)
                    <tr><td>{{ $attachment['filename'] }}<small>{{ $attachment['mime_type'] }}</small></td><td>{{ $attachment['size_label'] }}</td><td>{{ $attachment['uploaded_by'] }}</td><td>{{ $attachment['version_number'] ?? 1 }}</td></tr>
                @empty
                    <tr><td colspan="4" class="empty">No attachments recorded.</td></tr>
                @endforelse
            </tbody></table>
        </section>
        <p class="certification">Generated from the official correspondence register. Printed by {{ $printedBy->full_name }} ({{ $printedBy->title ?: $printedBy->roleName() }}) on {{ $printedAt->format('d/m/Y H:i') }}. This record contains the history available to the printing officer.</p>
        <footer class="page-footer"><span>{{ $record['register_number'] }} · {{ config('ats.ministry_short_name') }}</span><span>Official correspondence record</span></footer>
    </main>
</body>
</html>
