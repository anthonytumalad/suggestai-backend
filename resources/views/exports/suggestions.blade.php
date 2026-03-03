<!DOCTYPE html>
<html>
<head>
  <style>
    body { font-family: sans-serif; font-size: 11px; color: #111; }
    h1   { font-size: 16px; margin-bottom: 4px; }
    p    { font-size: 11px; color: #666; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f3f4f6; text-align: left; padding: 6px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
    td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .anon { color: #9ca3af; font-style: italic; }
  </style>
</head>
<body>
  <h1>{{ $formTitle }} — Suggestions</h1>
  <p>
    @if($startDate || $endDate)
      {{ $startDate ?? '—' }} to {{ $endDate ?? '—' }}
    @else
      All time
    @endif
    &nbsp;·&nbsp; {{ $suggestions->count() }} records
  </p>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Student</th>
        <th>Suggestion</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($suggestions as $s)
        <tr>
          <td>{{ $s->id }}</td>
          <td>
            @if($s->is_anonymous)
              <span class="anon">Anonymous</span>
            @else
              {{ $s->student?->email ?? 'N/A' }}
            @endif
          </td>
          <td>{{ $s->suggestion }}</td>
          <td>{{ $s->created_at->format('Y-m-d H:i') }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
