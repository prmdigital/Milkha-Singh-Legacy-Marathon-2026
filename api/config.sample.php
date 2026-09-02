<?php
/**
 * Configuration template.
 *
 * DO NOT put real credentials in this file — it is committed to git.
 *
 * Copy it to  /home/<your-hostinger-user>/marathon-config.php
 * i.e. ONE LEVEL ABOVE public_html, where the web server cannot serve it.
 * lib.php looks there first and only falls back to api/config.php for local
 * testing (that fallback is gitignored).
 */

return [
    // ---- Razorpay -------------------------------------------------------
    // Test keys start rzp_test_, live keys rzp_live_.
    // The key id is also used in the browser; the secret must NEVER be.
    'RAZORPAY_KEY_ID'     => 'rzp_test_xxxxxxxxxxxxxx',
    'RAZORPAY_KEY_SECRET' => 'xxxxxxxxxxxxxxxxxxxxxxxx',

    // Set after you create the webhook in the Razorpay dashboard (stage 3).
    'RAZORPAY_WEBHOOK_SECRET' => '',

    // ---- Database (Hostinger > Databases > MySQL) ------------------------
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'uXXXXXXXX_marathon',
    'DB_USER' => 'uXXXXXXXX_marathon',
    'DB_PASS' => 'xxxxxxxxxxxx',

    // ---- Uploads --------------------------------------------------------
    // Where ID-proof scans are written. MUST be outside public_html: these are
    // identity documents and anything under the web root is publicly
    // downloadable. Leave unset to use ../marathon-uploads/id-proofs.
    // 'UPLOAD_DIR' => '/home/uXXXXXXXX/marathon-uploads/id-proofs',

    // ---- Email (Hostinger > Emails) -------------------------------------
    'SMTP_HOST'      => 'smtp.hostinger.com',
    'SMTP_PORT'      => 465,
    'SMTP_USER'      => 'register@milkhasinghlegacymarathon.com',
    'SMTP_PASS'      => 'xxxxxxxxxxxx',
    'SMTP_FROM'      => 'register@milkhasinghlegacymarathon.com',
    'SMTP_FROM_NAME' => 'Milkha Singh Legacy Marathon',

    // Registration alerts land here.
    'ADMIN_EMAIL' => 'info@milkhasinghlegacymarathon.com',

    // ---- Admin panel (/admin) -------------------------------------------
    // Never store the password itself. Leave the hash empty, open
    // /admin/hash-tool.php once, paste what it gives you here, then DELETE
    // that file. The tool disables itself as soon as this is filled in.
    'ADMIN_USER'          => 'admin',
    'ADMIN_PASSWORD_HASH' => '',

    // ---- Site -----------------------------------------------------------
    // Only these origins may call the API. Keep it tight; never use '*'.
    'ALLOWED_ORIGINS' => [
        'https://milkhasinghlegacymarathon.com',
        'https://www.milkhasinghlegacymarathon.com',
    ],

    // Set true only while testing. Leaks error detail to the browser.
    'DEBUG' => false,
];
