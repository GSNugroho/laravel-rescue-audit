@php
    $m = $data['meta'];
    $score = $data['score'];
    $sev = $data['summary'];
    $scoreColor = $score >= 75 ? '#15803d' : ($score >= 50 ? '#b45309' : '#b91c1c');
    $sevColors = ['critical' => '#b91c1c', 'high' => '#c2410c', 'medium' => '#1d4ed8', 'low' => '#6b7280'];
    $sevLabels = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rescue Audit — {{ $m['app'] }}</title>
<style>
  :root { --bg:#f8fafc; --card:#fff; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .wrap { max-width: 960px; margin: 0 auto; padding: 32px 20px 64px; }
  header { display:flex; flex-wrap:wrap; gap:24px; align-items:center; justify-content:space-between; margin-bottom:28px; }
  h1 { font-size: 24px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:13px; }
  .score { display:flex; align-items:center; gap:16px; }
  .ring { width:92px; height:92px; border-radius:50%; display:grid; place-items:center; font-size:30px; font-weight:700; color:#fff; background: conic-gradient({{ $scoreColor }} {{ $score * 3.6 }}deg, #e2e8f0 0); position:relative; }
  .ring span { position:absolute; inset:8px; border-radius:50%; background:var(--card); display:grid; place-items:center; color:{{ $scoreColor }}; }
  .kpis { display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:12px; margin-bottom:28px; }
  .kpi { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:12px 14px; }
  .kpi b { display:block; font-size:22px; }
  .kpi small { color:var(--muted); }
  .checks { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:28px; }
  .chip { font-size:12px; padding:4px 10px; border-radius:999px; border:1px solid var(--line); background:var(--card); }
  .chip.ok { border-color:#bbf7d0; background:#f0fdf4; color:#166534; }
  .chip.bad { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
  .finding { background:var(--card); border:1px solid var(--line); border-left:5px solid var(--sc); border-radius:10px; padding:16px 18px; margin-bottom:14px; }
  .finding h2 { font-size:16px; margin:0 0 6px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .badge { font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#fff; background:var(--sc); padding:2px 8px; border-radius:6px; }
  .meta { color:var(--muted); font-size:12px; }
  .finding p { margin:8px 0; }
  .items { margin:8px 0 0; padding:0; list-style:none; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12.5px; }
  .items li { padding:3px 8px; border-radius:4px; background:#f1f5f9; margin:3px 0; overflow-wrap:anywhere; }
  details summary { cursor:pointer; color:var(--muted); font-size:12px; margin-top:6px; }
  footer { margin-top:40px; color:var(--muted); font-size:12px; border-top:1px solid var(--line); padding-top:14px; }
  .empty { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:18px; border-radius:10px; }
  @media print { body { background:#fff; } .finding { break-inside: avoid; } }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <h1>Rescue Audit — {{ $m['app'] }}</h1>
      <div class="sub">Laravel {{ $m['laravel'] }} · PHP {{ $m['php'] }} · env <code>{{ $m['env'] }}</code> · generated {{ \Carbon\Carbon::parse($m['generated_at'])->format('d M Y H:i') }}</div>
    </div>
    <div class="score">
      <div class="ring"><span>{{ $score }}</span></div>
      <div>
        <div style="font-size:18px;font-weight:700">Grade {{ $data['grade'] }}</div>
        <div class="sub">Health &amp; security score, 0–100</div>
        <div class="sub">Estimated fix time: <b>{{ $data['total_hours'] }} h</b></div>
      </div>
    </div>
  </header>

  <div class="kpis">
    @foreach ($sevLabels as $key => $label)
      <div class="kpi"><b style="color:{{ $sevColors[$key] }}">{{ $sev[$key] }}</b><small>{{ $label }}</small></div>
    @endforeach
    <div class="kpi"><b>{{ count($data['checks']) }}</b><small>Checks run</small></div>
  </div>

  <div class="checks">
    @foreach ($data['checks'] as $c)
      <span class="chip {{ $c['findings'] ? 'bad' : 'ok' }}" title="{{ $c['description'] }}">{{ $c['findings'] ? '!' : '✓' }} {{ $c['name'] }}</span>
    @endforeach
  </div>

  @forelse ($data['findings'] as $f)
    <section class="finding" style="--sc: {{ $sevColors[$f['severity']] }}">
      <h2><span class="badge">{{ $f['severity'] }}</span> {{ $f['title'] }}</h2>
      <div class="meta">check: {{ $f['check'] }} · estimated fix: {{ $f['hours'] }} h</div>
      <p>{{ $f['detail'] }}</p>
      @if ($f['items'])
        @if (count($f['items']) > 6)
          <ul class="items">
            @foreach (array_slice($f['items'], 0, 6) as $item)<li>{{ $item }}</li>@endforeach
          </ul>
          <details>
            <summary>Show {{ count($f['items']) - 6 }} more</summary>
            <ul class="items">
              @foreach (array_slice($f['items'], 6) as $item)<li>{{ $item }}</li>@endforeach
            </ul>
          </details>
        @else
          <ul class="items">
            @foreach ($f['items'] as $item)<li>{{ $item }}</li>@endforeach
          </ul>
        @endif
      @endif
    </section>
  @empty
    <div class="empty">No findings. The checks that ran found nothing to report.</div>
  @endforelse

  <footer>
    Generated by <b>laravel-rescue-audit</b>. Scores are heuristic: a static scan cannot prove an app is secure, but every item above is worth a look.
    Fix-time estimates assume a developer already familiar with Laravel.
  </footer>
</div>
</body>
</html>
