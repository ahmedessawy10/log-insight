<style>
        :root {
            --bg: #f6f7f9; --surface: #ffffff; --surface-2: #f0f2f5; --border: #e2e5ea;
            --text: #1b1f27; --muted: #5d6675; --accent: #2f5fd0; --accent-soft: #e6edfb;
            --good: #15803d; --bad: #b91c1c; --warn: #b45309;
            --lv-emergency: #7f1d1d; --lv-alert: #ea580c; --lv-critical: #be123c; --lv-error: #ef4444;
            --lv-warning: #d97706; --lv-notice: #0e7490; --lv-info: #2563eb; --lv-debug: #6b7280;
            --radius: 8px; --font: system-ui, -apple-system, "Segoe UI", Tahoma, "Noto Sans Arabic", sans-serif;
            --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --bg: #0f1218; --surface: #171b23; --surface-2: #1e232d; --border: #2a303b;
                --text: #e6e9ef; --muted: #98a2b3; --accent: #7aa2ff; --accent-soft: #1d2a4a;
                --good: #4ade80; --bad: #f87171; --warn: #fbbf24;
                --lv-emergency: #f87171; --lv-alert: #fb923c; --lv-critical: #fb7185; --lv-error: #f87171;
                --lv-warning: #fbbf24; --lv-notice: #22d3ee; --lv-info: #7aa2ff; --lv-debug: #9ca3af;
            }
        }
        :root[data-theme="dark"] {
            --bg: #0f1218; --surface: #171b23; --surface-2: #1e232d; --border: #2a303b;
            --text: #e6e9ef; --muted: #98a2b3; --accent: #7aa2ff; --accent-soft: #1d2a4a;
            --good: #4ade80; --bad: #f87171; --warn: #fbbf24;
            --lv-emergency: #f87171; --lv-alert: #fb923c; --lv-critical: #fb7185; --lv-error: #f87171;
            --lv-warning: #fbbf24; --lv-notice: #22d3ee; --lv-info: #7aa2ff; --lv-debug: #9ca3af;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 var(--font); }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .wrap { max-width: 1280px; margin-inline: auto; padding: 0 16px 48px; }
        .topbar { background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 20; }
        .topbar .wrap { display: flex; align-items: center; gap: 20px; padding-block: 10px; flex-wrap: wrap; }
        .brand { font-weight: 700; letter-spacing: .2px; }
        .nav { display: flex; gap: 4px; flex: 1; flex-wrap: wrap; }
        .nav a { padding: 6px 12px; border-radius: 6px; color: var(--muted); font-weight: 500; }
        .nav a:hover { background: var(--surface-2); text-decoration: none; }
        .nav a.active { background: var(--accent-soft); color: var(--accent); }
        .tools { display: flex; gap: 8px; align-items: center; }
        h1 { font-size: 20px; margin: 22px 0 14px; }
        h2 { font-size: 15px; margin: 0 0 12px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; }
        .grid { display: grid; gap: 12px; }
        .kpis { grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); margin-bottom: 12px; }
        .two { grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); margin-top: 12px; }
        .kpi .label { color: var(--muted); font-size: 12px; }
        .kpi .value { font-size: 28px; font-weight: 700; line-height: 1.2; margin-block: 2px; font-variant-numeric: tabular-nums; }
        .kpi .delta { font-size: 12px; color: var(--muted); }
        .up { color: var(--bad); } .down { color: var(--good); }
        .kpi.alert .value { color: var(--bad); }
        .kpi a { color: inherit; display: block; }
        .kpi a:hover { text-decoration: none; }
        .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .spread { justify-content: space-between; }
        input[type=text], input[type=password], input[type=search], input[type=date], input[type=datetime-local], select {
            font: inherit; color: var(--text); background: var(--surface); border: 1px solid var(--border);
            border-radius: 6px; padding: 7px 10px; min-width: 0;
        }
        input:focus, select:focus, button:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
        .grow { flex: 1; min-width: 200px; }
        button, .btn { font: inherit; cursor: pointer; border: 1px solid var(--border); background: var(--surface-2);
            color: var(--text); border-radius: 6px; padding: 6px 12px; }
        button:hover, .btn:hover { border-color: var(--accent); text-decoration: none; }
        button.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
        button.danger { color: var(--bad); }
        .chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px;
            border: 1px solid var(--border); background: var(--surface); cursor: pointer; }
        .chip.on { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;
            text-transform: uppercase; color: #fff; }
        .tag { display: inline-block; padding: 1px 8px; border-radius: 4px; font-size: 11px; background: var(--surface-2);
            border: 1px solid var(--border); color: var(--text); cursor: pointer; margin: 1px 2px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: start; font-size: 12px; color: var(--muted); font-weight: 600; padding: 8px 10px; border-bottom: 1px solid var(--border); }
        td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
        tr.clickable { cursor: pointer; }
        tr.clickable:hover td { background: var(--surface-2); }
        .msg { max-width: 640px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word; }
        .mono { font-family: var(--mono); font-size: 12px; }
        pre { font-family: var(--mono); font-size: 12px; background: var(--surface-2); border: 1px solid var(--border);
            border-radius: 6px; padding: 10px; overflow: auto; max-height: 360px; margin: 6px 0; white-space: pre-wrap; word-break: break-word; direction: ltr; text-align: left; }
        .muted { color: var(--muted); }
        .empty { text-align: center; color: var(--muted); padding: 32px 8px; }
        .notice { background: var(--accent-soft); border: 1px solid var(--accent); border-radius: 6px; padding: 8px 12px; margin-block: 8px; font-size: 13px; }
        .warn-box { background: color-mix(in srgb, var(--warn) 15%, transparent); border-color: var(--warn); }
        .filters { display: grid; gap: 10px; }
        .filters label { font-size: 12px; color: var(--muted); display: block; margin-bottom: 3px; }
        .filter-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
        /* chart */
        .chart { display: flex; align-items: flex-end; gap: 2px; height: 160px; padding-top: 8px; }
        .chart .col { flex: 1; display: flex; flex-direction: column-reverse; min-width: 3px; height: 100%; }
        .chart .seg { width: 100%; }
        .chart-axis { display: flex; justify-content: space-between; color: var(--muted); font-size: 11px; margin-top: 4px; direction: ltr; }
        .bar { height: 8px; border-radius: 4px; background: var(--surface-2); overflow: hidden; }
        .bar > span { display: block; height: 100%; }
        .drawer { position: fixed; inset-block: 0; inset-inline-end: 0; width: min(720px, 100%); background: var(--surface);
            border-inline-start: 1px solid var(--border); box-shadow: -8px 0 24px rgba(0,0,0,.18); z-index: 40; overflow: auto; padding: 18px; }
        .kv { display: grid; grid-template-columns: 120px 1fr; gap: 4px 12px; margin-block: 8px; }
        .kv dt { color: var(--muted); } .kv dd { margin: 0; word-break: break-word; }
        .pager { display: flex; gap: 6px; justify-content: center; margin-top: 14px; align-items: center; }
        .pager nav svg { width: 18px; height: 18px; }
        .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
        @media (max-width: 640px) { .kv { grid-template-columns: 1fr; } th:nth-child(4), td:nth-child(4) { display: none; } }
    </style>
