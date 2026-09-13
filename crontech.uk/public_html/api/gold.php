<?php
// Fetch the gold price from BullionByPost using PHP cURL and DOM parsing
// Returns GBP per gram (convert from per ounce)
// Updated XPath for robustness

$url = 'https://www.bullionbypost.co.uk/gold-price/';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
$html = curl_exec($ch);

if (curl_error($ch)) {
    echo 'cURL Error: ' . curl_error($ch);
    exit;
}

curl_close($ch);

if (!$html) {
    echo 'Failed to fetch HTML';
    exit;
}

// Parse HTML
$dom = new DOMDocument();
libxml_use_internal_errors(true); // Suppress HTML parsing warnings
$dom->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);

// Updated query: Find table row where first td contains 'Gold Price' text, then get the GBP td (index 2, assuming header row)
$query = "//tr[td[contains(., 'Gold Price')]]/td[2]";
$nodes = $xpath->query($query);

if ($node = $nodes->item(0)) {
    $price_str = trim($node->nodeValue);
    
    // Clean the price string: remove £ and commas, convert to float
    $price_oz = (float) preg_replace('/[^\d.]/', '', $price_str);
    
    // Convert to per gram (1 troy ounce = 31.1034768 grams)
    $grams_per_oz = 31.1034768;
    $price_gram = $price_oz / $grams_per_oz;
    
    echo "Current Gold Price (GBP per gram): £" . number_format($price_gram, 2) . "\n";
} else {
    echo 'Price not found. The page structure may have changed.';
    // Optional debugging: echo "Nodes found: " . $nodes->length . "\n";
}
?>