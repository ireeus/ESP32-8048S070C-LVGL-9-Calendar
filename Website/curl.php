<?php
if (function_exists('curl_version')) {
    $curl_info = curl_version();
    echo "<h3>✅ cURL jest WŁĄCZONY.</h3>";
    echo "Wersja cURL: <strong>" . $curl_info['version'] . "</strong><br>";
    echo "Wsparcie dla SSL/TLS (wymagane przez PayPal): <strong>" . (($curl_info['features'] & CURL_VERSION_SSL) ? "Tak" : "Nie") . "</strong>";
} else {
    echo "<h3>❌ cURL jest WYŁĄCZONY na tym serwerze.</h3>";
}
?>