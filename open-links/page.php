<?php if (!defined('YOURLS_ABSPATH')) exit; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>App Links</title>
    <link rel="stylesheet" href="<?= hmm_open_escape(rtrim(YOURLS_SITE, '/') . '/css/style.css?v=' . (defined('YOURLS_VERSION') ? YOURLS_VERSION : '1')) ?>">
    <style nonce="<?= hmm_open_escape($nonce) ?>">
        .open-links #wrap,.open-links #footer {box-sizing:border-box;width:100%;max-width:950px;margin-left:auto;margin-right:auto}
        .open-links #footer p {box-sizing:border-box;width:100%}
        .open-links main {clear:both;padding:10px 0 20px}
        .open-links p {line-height:1.6}
        .open-links code {overflow-wrap:anywhere}
        .open-links a.button {display:inline-block;font: bold 10px Verdana,Arial;color:#595441;background:#fafafe;border:2px solid #2a85b3;border-radius:3px;padding:5px 10px;text-decoration:none}
        .open-links a.button:hover {background:#e3f3ff}
        .open-links section {margin-top:24px;padding-top:16px;border-top:1px solid #88c0eb}
        .open-links label {display:block;font-weight:bold;margin-bottom:6px}
        .open-links input.text {box-sizing:border-box;width:100%;max-width:560px;padding:6px;font-size:12px}
        .open-links input.button {padding:5px 10px;margin-top:8px}
        .open-links a:focus-visible,.open-links input:focus-visible {outline:2px solid #2a85b3;outline-offset:3px}
        .open-links .error {color:#a00}
        #copy-status {min-height:1.6em}
    </style>
</head>
<body class="open-links">
<div id="wrap">
    <main>
    <?php if ($target): ?>
        <p>Your browser may ask for permission. If nothing happens, use the button below.</p>
        <a class="button" id="launch" href="<?= hmm_open_escape($target) ?>">Open in app</a>
        <p><code id="destination"><?= hmm_open_escape($target) ?></code></p>
        <?php if ($shortUrl !== ''): ?>
        <section aria-label="Short link">
            <label for="short-url">Short link</label>
            <input class="text" id="short-url" readonly value="<?= hmm_open_escape($shortUrl) ?>" spellcheck="false">
            <br><input class="button" type="button" id="copy-short-url" value="Copy link">
            <p id="copy-status" role="status" aria-live="polite"></p>
        </section>
        <?php endif; ?>
    <?php else: ?>
        <h2>Link unavailable</h2>
        <p class="error" role="alert"><?= hmm_open_escape($error) ?></p>
    <?php endif; ?>
</main>
</div>
<footer id="footer"><p>App Links for YOURLS · <?= hmm_open_escape(parse_url(YOURLS_SITE, PHP_URL_HOST)) ?></p></footer>
<script nonce="<?= hmm_open_escape($nonce) ?>">
const copy = document.getElementById('copy-short-url');
if (copy) copy.addEventListener('click', async () => {
    const field = document.getElementById('short-url');
    try {
        await navigator.clipboard.writeText(field.value);
        document.getElementById('copy-status').textContent = 'Link copied.';
    } catch {
        field.focus(); field.select();
        document.getElementById('copy-status').textContent = 'Copy the selected link with Ctrl+C or ⌘C.';
    }
});
const launch = document.getElementById('launch');
if (launch) location.replace(launch.href);
</script>
</body></html>
