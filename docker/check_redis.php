<?php
$config = include '/app/bootstrap/cache/config.php';
$redis = $config['database']['redis'] ?? [];

echo "=== Redis Config ===\n";
echo "default.password: " . var_export($redis['default']['password'] ?? 'NOT SET', true) . "\n";
echo "cache.password: " . var_export($redis['cache']['password'] ?? 'NOT SET', true) . "\n";
echo "pubsub.password: " . var_export($redis['pubsub']['password'] ?? 'NOT SET', true) . "\n";
echo "client: " . var_export($redis['client'] ?? 'NOT SET', true) . "\n";
echo "default.host: " . var_export($redis['default']['host'] ?? 'NOT SET', true) . "\n";
echo "\n=== Env Check ===\n";
echo "REDIS_PASSWORD env: " . var_export(getenv('REDIS_PASSWORD'), true) . "\n";
echo "DB_PASSWORD env: " . var_export(getenv('DB_PASSWORD'), true) . "\n";
