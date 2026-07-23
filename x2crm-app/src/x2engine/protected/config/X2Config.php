<?php
// Stock X2Engine normally has this file overwritten with real, hardcoded
// values by the install wizard (protected/data/config.sql stage). Since
// running that wizard against an already-populated database would be
// destructive (see scripts/migrate-from-prod.sh), this reads connection
// details from the same environment variables docker-compose.yml already
// passes into this container instead — so this file never needs a real
// password baked into it (and can't leak one into version control), and
// a fresh deployment gets correctly configured automatically without the
// installer ever needing to run.
$appName = 'X2Engine';
$email = 'a@b.com';
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$dbname = getenv('DB_NAME') ?: 'x2engine';
$version = '8.5';
$buildDate = '1548456424';
$updaterVersion = '6.9.1';
$language='en';
?>