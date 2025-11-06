<?php
// workers/price_worker.php

set_time_limit(0); 

require_once __DIR__ . '/../config/api_bootstrap.php';

echo "Price worker started...\n";

try {
    // --- Connect to Redis (no changes here) ---
    $redis = new Predis\Client([
        'scheme' => 'redis',
        'host'   => $_ENV['REDIS_HOST'],
        'port'   => $_ENV['REDIS_PORT'],
        'password' => $_ENV['REDIS_PASSWORD'],
    ]);
    $redis->ping();
    echo "Successfully connected to Redis.\n";
} catch (Exception $e) {
    echo "FATAL: Could not connect to Redis. " . $e->getMessage() . "\n";
    $log->error("Price worker FATAL: Could not connect to Redis.", ['error' => $e->getMessage()]);
    exit(1);
}

// --- The Infinite Loop ---
while (true) {
    
    // --- NEW: Fetch Active Trading Pairs from the Database ---
    try {
        $query = "SELECT symbol FROM trading_assets WHERE is_active = TRUE";
        $result = $conn->query($query);
        if (!$result) {
            throw new Exception($conn->error);
        }
        // Fetch all symbols into a simple array
        $trading_pairs = $result->fetch_all(MYSQLI_ASSOC);
        $trading_pairs = array_column($trading_pairs, 'symbol');
        
        if (empty($trading_pairs)) {
            echo "Warning: No active trading pairs found in the database.\n";
            sleep(30); // Sleep for 30 seconds if no pairs are active
            continue;
        }

    } catch (Exception $e) {
        echo "Error fetching trading pairs from DB: " . $e->getMessage() . "\n";
        $log->error("Price worker failed to fetch trading pairs.", ['error' => $e->getMessage()]);
        sleep(10); // Wait 10 seconds before retrying
        continue;
    }
    // --- END of NEW LOGIC ---

    // --- Fetch Prices from Binance (no changes here) ---
    $url = "https://api.binance.com/api/v3/ticker/price";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "cURL Error: " . curl_error($ch) . "\n";
        $log->error("Price worker cURL error", ['error' => curl_error($ch)]);
        curl_close($ch);
        sleep(5);
        continue;
    }
    curl_close($ch);
    
    $all_prices = json_decode($response, true);

    if (!is_array($all_prices)) {
        echo "Binance API did not return valid JSON.\n";
        $log->warning("Price worker did not receive valid JSON from Binance.", ['response' => $response]);
        sleep(5);
        continue;
    }

    // --- Update Redis Cache (no changes here) ---
    $prices_to_cache = [];
    foreach ($all_prices as $item) {
        if (in_array($item['symbol'], $trading_pairs)) {
            $key = "price:" . $item['symbol'];
            $price = $item['price'];
            $prices_to_cache[$key] = $price;
        }
    }

    if (!empty($prices_to_cache)) {
        $redis->mset($prices_to_cache);
        echo "Updated " . count($prices_to_cache) . " prices at " . date('Y-m-d H:i:s') . "\n";
    }

    // Wait for 1 second before the next iteration
    sleep(1);
}