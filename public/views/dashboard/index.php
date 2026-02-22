<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f2a43">
    <link rel="manifest" href="/greenfield/public/manifest.json">
    <title>Greenfield ERP Dashboard</title>
    <link rel="stylesheet" href="/greenfield/public/assets/css/app.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <h2>Greenfield ERP</h2>
        <a href="/greenfield/public/dashboard">Dashboard</a>
        <form method="post" action="/greenfield/public/logout">
            <input type="hidden" name="_csrf" value="<?= \App\Core\Security\InputSanitizer::esc($csrf) ?>">
            <button type="submit" class="link-like">Logout</button>
        </form>
    </aside>
    <main class="main">
        <header>
            <h1>Executive Overview</h1>
            <span>User #<?= (int) ($user['id'] ?? 0) ?></span>
        </header>
        <section class="status-row">
            <span class="status-pill"><?= \App\Core\Security\InputSanitizer::esc((string) ($summary['trial_balance_status'] ?? 'Balanced')) ?></span>
        </section>
        <section class="kpi-grid">
            <?php foreach ($summary['kpis'] as $kpi): ?>
                <article class="kpi-card">
                    <h3><?= \App\Core\Security\InputSanitizer::esc($kpi['title']) ?></h3>
                    <p><?= \App\Core\Security\InputSanitizer::esc($kpi['value']) ?></p>
                </article>
            <?php endforeach; ?>
        </section>
        <section class="chart-grid">
            <div class="chart-card"><h3>Revenue Trend</h3><canvas id="revenueChart"></canvas></div>
            <div class="chart-card"><h3>Cash Flow Forecast</h3><canvas id="cashFlowChart"></canvas></div>
            <div class="chart-card"><h3>AR Aging</h3><canvas id="arChart"></canvas></div>
            <div class="chart-card"><h3>Branch Comparison</h3><canvas id="branchChart"></canvas></div>
        </section>
    </main>
</div>
<script src="/greenfield/public/assets/js/app.js" type="module"></script>
</body>
</html>
