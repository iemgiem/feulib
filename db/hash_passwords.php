<?php
/**
 * FEU LFMS — Password hash generator
 *
 * Run this when you want a fresh bcrypt hash for the seed file or for
 * resetting an account password manually in the database.
 *
 * USAGE (CLI, from project root):
 *   php db/hash_passwords.php password123
 *   php db/hash_passwords.php "another password"
 *
 * Copy the resulting hash into db/seed.sql (replacing @pw) or directly
 * into the accounts.password_hash column for a specific user.
 *
 * This script intentionally does not connect to the database. It is a
 * pure utility — input plaintext, output a bcrypt hash.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This utility is CLI-only.');
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php db/hash_passwords.php <plaintext-password>\n");
    exit(1);
}

$plaintext = $argv[1];

if (strlen($plaintext) < 8) {
    fwrite(STDERR, "Refusing to hash a password shorter than 8 characters.\n");
    exit(1);
}

$hash = password_hash($plaintext, PASSWORD_DEFAULT);

echo $hash . PHP_EOL;
