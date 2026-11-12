<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>DAC compiled examiner nominations</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; }
        h1 { font-size: 13pt; margin: 0 0 2pt; }
        p { margin: 0 0 6pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #555; padding: 3pt; vertical-align: top; text-align: left; }
        th { background: #e8e8e8; }
        .flag { color: #9a3412; }
        .sign { margin-top: 18pt; }
    </style>
</head>
<body>
    <h1>Examiner nominations for the Departmental Academic Committee</h1>
    <p>Department: {{ $department }} &middot; Compiled by {{ $generatedBy->name }} on {{ now()->format('j F Y') }} &middot; {{ $nominations->count() }} nomination(s)</p>
    <table>
        <thead>
            <tr>
                <th>No.</th><th>Candidate</th><th>Programme</th><th>Thesis title</th><th>Supervisor</th>
                <th>Internal main</th><th>Internal backup</th><th>External main</th><th>External backup</th>
                <th>COI / clash flags</th><th>DAC decision</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($nominations->values() as $i => $n)
                @php($student = $n->application?->student)
                <tr>
                    <td>{{ $i + 1 }}<br>#{{ $n->application_id }}</td>
                    <td>{{ $student?->name }}<br>{{ $student?->matric_no }}</td>
                    <td>{{ $student?->programme }}</td>
                    <td>{{ $n->thesis_title }}</td>
                    <td>{{ $n->application?->submittedBy?->name ?? $student?->supervisor?->name }}</td>
                    @foreach ([$n->internalMain, $n->internalBackup, $n->externalMain, $n->externalBackup] as $e)
                        <td>{{ $e?->name ?? '—' }}@if ($e)<br><i>{{ $e->institution ?: $e->department }}</i>@endif</td>
                    @endforeach
                    <td>
                        @forelse (collect($flags[$n->application_id] ?? [])->flatten(1) as $flag)
                            <div class="flag">{{ ucfirst($flag['level']) }}: {{ $flag['message'] }}</div>
                        @empty
                            None
                        @endforelse
                    </td>
                    <td>&#9744; Endorsed<br>&#9744; Not endorsed</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="sign">DAC meeting date: ____________________ &nbsp;&nbsp; Reference: ____________________ &nbsp;&nbsp; Chair's signature: ____________________</p>
</body>
</html>
