<?php
/**
 * Simple CLI tool to check Stripe API keys.
 *
 * Usage:
 *   php check-stripe-keys.php sk_test_xxx pk_test_xxx
 *   php check-stripe-keys.php sk_live_xxx
 *
 * You can also set keys via environment variables instead of args:
 *   STRIPE_SECRET_KEY=sk_test_xxx php check-stripe-keys.php
 *   STRIPE_PUBLISHABLE_KEY=pk_test_xxx php check-stripe-keys.php
 */

function out($label, $ok, $message) {
    $status = $ok ? "OK" : "FAIL";
    echo str_pad("[$status]", 8) . "$label: $message\n";
}

$args = $argv;
array_shift($args); // remove script name

$secretKey = null;
$publishableKey = null;

foreach ($args as $arg) {
    if (strpos($arg, 'sk_') === 0) {
        $secretKey = $arg;
    } elseif (strpos($arg, 'pk_') === 0) {
        $publishableKey = $arg;
    }
}

if (!$secretKey && getenv('STRIPE_SECRET_KEY')) {
    $secretKey = getenv('STRIPE_SECRET_KEY');
}
if (!$publishableKey && getenv('STRIPE_PUBLISHABLE_KEY')) {
    $publishableKey = getenv('STRIPE_PUBLISHABLE_KEY');
}

if (!$secretKey && !$publishableKey) {
    echo "No Stripe keys provided.\n\n";
    echo "Usage:\n";
    echo "  php check-stripe-keys.php sk_test_xxx pk_test_xxx\n";
    echo "  STRIPE_SECRET_KEY=sk_test_xxx php check-stripe-keys.php\n";
    exit(1);
}

echo "Checking Stripe keys...\n\n";

// --- Publishable key: format check only (can't be verified via API) ---
if ($publishableKey) {
    $isTest = strpos($publishableKey, 'pk_test_') === 0;
    $isLive = strpos($publishableKey, 'pk_live_') === 0;
    if ($isTest || $isLive) {
        out('Publishable key', true, 'valid format (' . ($isTest ? 'TEST' : 'LIVE') . ' mode)');
    } else {
        out('Publishable key', false, 'does not start with pk_test_ or pk_live_');
    }
}

// --- Secret key: format check + live API call ---
if ($secretKey) {
    $isTest = strpos($secretKey, 'sk_test_') === 0;
    $isLive = strpos($secretKey, 'sk_live_') === 0;

    if (!$isTest && !$isLive) {
        out('Secret key', false, 'does not start with sk_test_ or sk_live_');
    } else {
        $ch = curl_init('https://api.stripe.com/v1/balance');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secretKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            out('Secret key', false, 'request failed: ' . $curlError);
        } elseif ($httpCode === 200) {
            $mode = $isTest ? 'TEST' : 'LIVE';
            out('Secret key', true, "valid and active ($mode mode)");
        } elseif ($httpCode === 401) {
            out('Secret key', false, 'rejected by Stripe (invalid or revoked key)');
        } else {
            $data = json_decode($response, true);
            $errMsg = $data['error']['message'] ?? "unexpected HTTP $httpCode response";
            out('Secret key', false, $errMsg);
        }
    }
}

echo "\nDone.\n";
