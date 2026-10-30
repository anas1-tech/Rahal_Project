<?php
// db_init.php - run once at container start (see Dockerfile).
// If the database is empty, converts the bundled MySQL dump (public/DB/r7al.sql) to PostgreSQL and loads it.
// Safe to run on every start: it does nothing when the tables already exist.

require_once __DIR__ . '/pg_compat.php';

$url = getenv('DATABASE_URL');
if (!$url) {
    fwrite(STDERR, "db_init: DATABASE_URL is not set, skipping.\n");
    exit(0);
}

try {
    $db = new PgCompatConnection($url);
    $exists = $db->query("SELECT to_regclass('public.tourist_attractions') AS t")->fetch_assoc();
    if (!empty($exists['t'])) {
        fwrite(STDERR, "db_init: tables already exist, nothing to do.\n");
        exit(0);
    }

    $dump = __DIR__ . '/../../DB/r7al.sql';
    $src = file_get_contents($dump);
    if ($src === false) {
        throw new RuntimeException('Dump not found: ' . $dump);
    }

    $ddl = [];
    $inserts = [];
    foreach (preg_split('/;\s*\n/', $src) as $s) {
        if (preg_match('/CREATE TABLE `(\w+)` \((.*)\) ENGINE=/s', $s, $m)) {
            $body = str_replace('`', '', $m[2]);
            $body = str_replace('int(11)', 'integer', $body);
            $body = preg_replace('/enum\([^)]*\)/', 'varchar(20)', $body);
            $body = str_replace('current_timestamp()', 'CURRENT_TIMESTAMP', $body);
            $body = preg_replace('/^\s*id integer NOT NULL/m', '  id integer PRIMARY KEY', $body, 1);
            $ddl[$m[1]] = "CREATE TABLE {$m[1]} ({$body});";
            continue;
        }
        $i = strpos($s, 'INSERT INTO');
        if ($i !== false) {
            $t = substr($s, $i);
            $j = strpos($t, ' VALUES');
            $head = str_replace('`', '', substr($t, 0, $j));
            $data = substr($t, $j);
            $data = str_replace(["\\'", '\\"', '\\r', '\\n'], ["''", '"', "\r", "\n"], $data);
            $inserts[] = $head . $data . ';';
        }
    }

    $tables = ['regions', 'cities', 'tourist_attractions', 'attraction_images', 'comments'];
    $sql = ["BEGIN;"];
    foreach ($tables as $t) {
        $sql[] = $ddl[$t];
    }
    $sql = array_merge($sql, $inserts, [
        "ALTER TABLE cities ADD CONSTRAINT cities_region_fk FOREIGN KEY (region_id) REFERENCES regions (id);",
        "ALTER TABLE tourist_attractions ADD CONSTRAINT ta_region_fk FOREIGN KEY (region_id) REFERENCES regions (id);",
        "ALTER TABLE tourist_attractions ADD CONSTRAINT ta_city_fk FOREIGN KEY (city_id) REFERENCES cities (id);",
        "ALTER TABLE attraction_images ADD CONSTRAINT ai_attraction_fk FOREIGN KEY (attraction_id) REFERENCES tourist_attractions (id);",
        "CREATE INDEX ta_region_idx ON tourist_attractions (region_id);",
        "CREATE INDEX ta_city_idx ON tourist_attractions (city_id);",
        "CREATE INDEX cities_region_idx ON cities (region_id);",
        "CREATE INDEX ai_attraction_idx ON attraction_images (attraction_id);",
    ]);
    foreach ($tables as $t) {
        $sql[] = "CREATE SEQUENCE {$t}_id_seq OWNED BY {$t}.id;";
        $sql[] = "SELECT setval('{$t}_id_seq', COALESCE((SELECT MAX(id) FROM {$t}), 0) + 1, false);";
        $sql[] = "ALTER TABLE {$t} ALTER COLUMN id SET DEFAULT nextval('{$t}_id_seq');";
    }
    $sql[] = "COMMIT;";

    $db->exec(implode("\n", $sql));
    fwrite(STDERR, "db_init: database loaded.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "db_init: failed: " . $e->getMessage() . "\n");
    exit(0); // don't block the web server from starting
}
