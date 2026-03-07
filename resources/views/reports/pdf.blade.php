<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; padding: 40px; }

    h1 { font-size: 20px; margin-bottom: 4px; }
    .meta { color: #666; font-size: 11px; margin-bottom: 32px; }

    .stats { display: flex; gap: 16px; margin-bottom: 32px; }
    .stat  { background: #f5f5f5; border-radius: 6px; padding: 12px 20px; flex: 1; }
    .stat-value { font-size: 22px; font-weight: bold; }
    .stat-label { font-size: 10px; color: #666; margin-top: 2px; }

    .topic { margin-bottom: 28px; border: 1px solid #e5e5e5; border-radius: 6px; overflow: hidden; }
    .topic-header { background: #f9f9f9; padding: 10px 16px; border-bottom: 1px solid #e5e5e5; }
    .topic-title  { font-size: 13px; font-weight: bold; }
    .topic-keywords { font-size: 10px; color: #888; margin-top: 3px; }
    .topic-count  { font-size: 10px; color: #555; margin-top: 2px; }

    table { width: 100%; border-collapse: collapse; }
    th    { background: #f0f0f0; text-align: left; padding: 7px 12px; font-size: 10px; color: #555; }
    td    { padding: 7px 12px; font-size: 11px; border-top: 1px solid #f0f0f0; vertical-align: top; }
    tr:hover td { background: #fafafa; }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; }
    .badge-anon  { background: #fef3c7; color: #92400e; }
    .badge-named { background: #dcfce7; color: #166534; }
  </style>
</head>
<body>

  <h1>{{ $title }}</h1>
  <p class="meta">
    Session: {{ $session['name'] }}
    &nbsp;·&nbsp;
    Generated {{ $session['generated_at'] }}
    @if($session['date_range']['start'] || $session['date_range']['end'])
      &nbsp;·&nbsp;
      {{ $session['date_range']['start'] ?? '—' }} → {{ $session['date_range']['end'] ?? '—' }}
    @endif
  </p>

  <div class="stats">
    <div class="stat">
      <div class="stat-value">{{ $session['total_topics'] }}</div>
      <div class="stat-label">Topics</div>
    </div>
    <div class="stat">
      <div class="stat-value">{{ $session['total_documents'] }}</div>
      <div class="stat-label">Suggestions</div>
    </div>
    <div class="stat">
      <div class="stat-value">{{ $session['outliers'] }}</div>
      <div class="stat-label">Outliers</div>
    </div>
  </div>

  @foreach($topics as $topic)
    <div class="topic">
      <div class="topic-header">
        <div class="topic-title">{{ $topic['label'] }}</div>
        <div class="topic-keywords">Keywords: {{ implode(', ', $topic['keywords']) }}</div>
        <div class="topic-count">{{ count($topic['suggestions']) }} suggestion(s)</div>
      </div>

      @if(count($topic['suggestions']) > 0)
        <table>
          <thead>
            <tr>
              <th style="width:60%">Suggestion</th>
              <th style="width:15%">Type</th>
              <th style="width:25%">Date</th>
            </tr>
          </thead>
          <tbody>
            @foreach($topic['suggestions'] as $s)
              <tr>
                <td>{{ $s['text'] }}</td>
                <td>
                  <span class="badge {{ $s['is_anonymous'] ? 'badge-anon' : 'badge-named' }}">
                    {{ $s['is_anonymous'] ? 'Anonymous' : 'Named' }}
                  </span>
                </td>
                <td>{{ $s['created_at'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  @endforeach

</body>
</html>
