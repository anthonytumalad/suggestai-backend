<!DOCTYPE html>
<html>
<head>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: sans-serif; font-size: 10px; }
    h1 { font-size: 14px; margin-bottom: 8px; }
    p  { font-size: 10px; color: #666; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th { background: #f3f4f6; padding: 5px 8px; font-size: 9px; text-align: left; }
    td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; word-wrap: break-word; }
    col.id   { width: 5%; }
    col.name { width: 20%; }
    col.sug  { width: 60%; }
    col.date { width: 15%; }
  </style>
</head>
<body>
  <h1>{{ $formTitle }}</h1>
  <p>
    {{ $suggestions->count() }} records
    @if($startDate || $endDate)
      · {{ $startDate ?? '—' }} to {{ $endDate ?? '—' }}
    @endif
  </p>

  <table>
    <colgroup>
      <col class="id">
      <col class="name">
      <col class="sug">
      <col class="date">
    </colgroup>
    <thead>
      <tr>
        <th>#</th>
        <th>Student</th>
        <th>Suggestion</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
      @foreach($suggestions as $s)
      <tr>
        <td>{{ $s->id }}</td>
        <td>{{ $s->is_anonymous ? 'Anonymous' : ($s->student?->email ?? 'N/A') }}</td>
        <td>{{ $s->suggestion }}</td>
        <td>{{ $s->created_at->format('Y-m-d') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
