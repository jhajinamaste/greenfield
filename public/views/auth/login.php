<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f2a43">
    <title>Greenfield ERP Login</title>
    <link rel="stylesheet" href="/greenfield/public/assets/css/app.css">
</head>
<body class="auth-page">
<div class="auth-shell">
    <div class="brand-panel">
        <h1>Greenfield ERP</h1>
        <p>Enterprise billing and ERP foundation.</p>
    </div>
    <form id="login-form" class="auth-card" method="post" action="/greenfield/public/login">
        <h2>Sign In</h2>
        <input type="hidden" name="_csrf" value="<?= \App\Core\Security\InputSanitizer::esc($csrf) ?>">
        <label>Organization Code</label>
        <input type="text" name="organization_code" required maxlength="30" autocomplete="organization">
        <small class="hint">Organization code identifies your tenant workspace and isolates data access.</small>

        <label>Username</label>
        <input type="text" name="username" required autocomplete="username">

        <label>Password</label>
        <input type="password" name="password" required autocomplete="current-password">

        <button type="submit">Login</button>
        <p id="login-error" class="error"></p>
    </form>
</div>
<script src="/greenfield/public/assets/js/app.js" type="module"></script>
</body>
</html>
