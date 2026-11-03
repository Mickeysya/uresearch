{{-- A one-page stand-in for a supporting document on DEMO data only. See
     Database\Seeders\Support\DemoDocuments. Self-contained for Dompdf. --}}
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <title>{{ $docType }} (demo)</title>
    <style>
        @page { margin: 2.5cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; color: #222; }
        .banner { border: 2pt solid #b32d22; color: #b32d22; text-align: center; font-weight: bold; padding: 10pt; font-size: 14pt; letter-spacing: 1pt; }
        h1 { font-size: 16pt; margin: 24pt 0 12pt; }
        table { border-collapse: collapse; width: 100%; }
        td { padding: 5pt 6pt; border-bottom: 0.5pt solid #ccc; vertical-align: top; }
        td.label { width: 32%; font-weight: bold; }
        .note { margin-top: 24pt; font-size: 9.5pt; color: #555; }
    </style>
</head>
<body>
    <div class="banner">DEMO DATA: NOT A REAL DOCUMENT</div>

    <h1>{{ $docType }}</h1>

    <table>
        <tr><td class="label">Student</td><td>{{ $student?->name ?? '—' }}</td></tr>
        <tr><td class="label">Matric No.</td><td>{{ $student?->matric_no ?? '—' }}</td></tr>
        <tr><td class="label">Application</td><td>{{ $application->module()->label() }}, {{ $application->reference() }}</td></tr>
        <tr><td class="label">Generated</td><td>{{ now()->format('j F Y, g:i a') }}</td></tr>
    </table>

    <p class="note">
        This placeholder was attached by a UResearch 2.0 demo seeder so that the application has a
        supporting document that opens. It stands in for the real "{{ $docType }}" a student would upload,
        and carries no information of its own.
    </p>
</body>
</html>
