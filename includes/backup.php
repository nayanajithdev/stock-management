<?php

declare(strict_types=1);

const BACKUP_APP_NAME = 'stock-management';
const BACKUP_VERSION = 1;
const BACKUP_MAX_UPLOAD_BYTES = 80_000_000;

function backup_known_tables(): array
{
    return [
        'users',
        'user_permissions',
        'categories',
        'brands',
        'suppliers',
        'customers',
        'products',
        'product_serials',
        'stock_movements',
        'purchases',
        'purchase_items',
        'supplier_payments',
        'expenses',
        'sales',
        'sale_items',
        'customer_payments',
        'sales_returns',
        'sales_return_items',
        'warranty_claims',
        'settings',
        'activity_logs',
    ];
}

function backup_database_name(PDO $pdo): string
{
    return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
}

function backup_table_names(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_type = "BASE TABLE"'
    );

    $tables = array_values(array_filter(
        array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)),
        static fn (string $table): bool => in_array($table, backup_known_tables(), true)
    ));
    $knownOrder = array_flip(backup_known_tables());

    usort($tables, static function (string $left, string $right) use ($knownOrder): int {
        $leftRank = $knownOrder[$left] ?? 10_000;
        $rightRank = $knownOrder[$right] ?? 10_000;

        return $leftRank === $rightRank ? strcmp($left, $right) : $leftRank <=> $rightRank;
    });

    return $tables;
}

function backup_table_counts(PDO $pdo): array
{
    $counts = [];

    foreach (backup_table_names($pdo) as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . backup_identifier($table))->fetchColumn();
    }

    return $counts;
}

function backup_generate_sql(PDO $pdo, string $backupType = 'sql'): string
{
    $tables = backup_table_names($pdo);
    $lines = [
        '-- StockPilot Backup',
        '-- Backup-Version: ' . BACKUP_VERSION,
        '-- Backup-Type: ' . $backupType,
        '-- Created-At: ' . date(DATE_ATOM),
        '-- Database: ' . backup_database_name($pdo),
        '',
        'SET FOREIGN_KEY_CHECKS=0;',
        '',
    ];

    foreach (array_reverse($tables) as $table) {
        $lines[] = 'DROP TABLE IF EXISTS ' . backup_identifier($table) . ';';
    }

    $lines[] = '';

    foreach ($tables as $table) {
        $createStatement = $pdo->query('SHOW CREATE TABLE ' . backup_identifier($table));
        $createRow = $createStatement->fetch(PDO::FETCH_NUM);
        $createSql = is_array($createRow) ? (string) ($createRow[1] ?? '') : '';

        if ($createSql === '') {
            throw new RuntimeException('Could not read schema for table ' . $table . '.');
        }

        $lines[] = $createSql . ';';
        $lines[] = '';
        $lines = array_merge($lines, backup_generate_insert_lines($pdo, $table));
        $lines[] = '';
    }

    $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $lines[] = '';

    return implode("\n", $lines);
}

function backup_generate_insert_lines(PDO $pdo, string $table): array
{
    $columnStatement = $pdo->query('SHOW COLUMNS FROM ' . backup_identifier($table));
    $columns = array_map(static fn (array $row): string => (string) $row['Field'], $columnStatement->fetchAll());

    if ($columns === []) {
        return [];
    }

    $selectStatement = $pdo->query('SELECT * FROM ' . backup_identifier($table));
    $columnSql = implode(', ', array_map('backup_identifier', $columns));
    $lines = [];
    $batch = [];
    $batchSize = 50;

    while (($row = $selectStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $values = [];

        foreach ($columns as $column) {
            $values[] = backup_sql_value($pdo, $row[$column] ?? null);
        }

        $batch[] = '(' . implode(', ', $values) . ')';

        if (count($batch) >= $batchSize) {
            $lines[] = 'INSERT INTO ' . backup_identifier($table) . ' (' . $columnSql . ') VALUES';
            $lines[] = implode(",\n", $batch) . ';';
            $batch = [];
        }
    }

    if ($batch !== []) {
        $lines[] = 'INSERT INTO ' . backup_identifier($table) . ' (' . $columnSql . ') VALUES';
        $lines[] = implode(",\n", $batch) . ';';
    }

    return $lines;
}

function backup_sql_value(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    $quoted = $pdo->quote((string) $value);

    return $quoted === false ? "''" : $quoted;
}

function backup_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function backup_filename(string $extension): string
{
    return 'stock-management-backup-' . date('Ymd-His') . '.' . $extension;
}

function backup_create_full_zip(PDO $pdo): string
{
    if (! class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZIP extension is not available on this server.');
    }

    $sql = backup_generate_sql($pdo, 'full');
    $tempFile = tempnam(sys_get_temp_dir(), 'stock_backup_');

    if ($tempFile === false) {
        throw new RuntimeException('Could not create temporary backup file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
        @unlink($tempFile);
        throw new RuntimeException('Could not create ZIP backup.');
    }

    $zip->addFromString('database.sql', $sql);

    $files = [];
    $root = dirname(__DIR__);
    $uploadDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'shop';

    if (is_dir($uploadDir)) {
        foreach (new DirectoryIterator($uploadDir) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            if (! in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico'], true)) {
                continue;
            }

            $relativePath = 'uploads/shop/' . $file->getFilename();
            $absolutePath = $file->getPathname();

            $zip->addFile($absolutePath, $relativePath);
            $files[] = [
                'path' => $relativePath,
                'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $absolutePath),
            ];
        }
    }

    $manifest = [
        'app' => BACKUP_APP_NAME,
        'backup_version' => BACKUP_VERSION,
        'type' => 'full',
        'created_at' => date(DATE_ATOM),
        'database' => backup_database_name($pdo),
        'database_sha256' => hash('sha256', $sql),
        'files' => $files,
    ];

    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $zip->close();

    return $tempFile;
}

function backup_read_upload(): array
{
    if (! isset($_FILES['backup_file']) || ! is_array($_FILES['backup_file'])) {
        throw new RuntimeException('Choose a backup file to restore.');
    }

    $file = $_FILES['backup_file'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Backup upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > BACKUP_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Backup file is empty or too large.');
    }

    $name = (string) ($file['name'] ?? '');
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (! is_uploaded_file($tmpName)) {
        throw new RuntimeException('Backup upload is not valid.');
    }

    return [
        'name' => $name,
        'tmp_name' => $tmpName,
        'extension' => $extension,
    ];
}

function backup_verify_uploaded_backup(array $upload): array
{
    return match ($upload['extension']) {
        'sql' => backup_verify_sql_file((string) $upload['tmp_name']),
        'zip' => backup_verify_zip_file((string) $upload['tmp_name']),
        default => throw new RuntimeException('Backup must be a .sql or .zip file.'),
    };
}

function backup_verify_sql_file(string $path): array
{
    $sql = file_get_contents($path);

    if ($sql === false) {
        throw new RuntimeException('Could not read SQL backup.');
    }

    backup_verify_sql($sql);

    return [
        'type' => 'sql',
        'sql' => $sql,
        'zip_path' => null,
        'manifest' => null,
    ];
}

function backup_verify_zip_file(string $path): array
{
    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        throw new RuntimeException('ZIP backup could not be opened.');
    }

    $manifestJson = $zip->getFromName('manifest.json');
    $sql = $zip->getFromName('database.sql');

    if ($manifestJson === false || $sql === false) {
        $zip->close();
        throw new RuntimeException('Full backup is missing manifest.json or database.sql.');
    }

    $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);

    if (($manifest['app'] ?? '') !== BACKUP_APP_NAME || (int) ($manifest['backup_version'] ?? 0) !== BACKUP_VERSION) {
        $zip->close();
        throw new RuntimeException('Backup file is not from this system version.');
    }

    if (($manifest['type'] ?? '') !== 'full') {
        $zip->close();
        throw new RuntimeException('Backup file is not a full backup.');
    }

    if (! hash_equals((string) ($manifest['database_sha256'] ?? ''), hash('sha256', $sql))) {
        $zip->close();
        throw new RuntimeException('Backup verification failed. Database hash does not match.');
    }

    backup_verify_sql($sql);
    backup_verify_zip_entries($zip, $manifest);
    $zip->close();

    return [
        'type' => 'zip',
        'sql' => $sql,
        'zip_path' => $path,
        'manifest' => $manifest,
    ];
}

function backup_verify_zip_entries(ZipArchive $zip, array $manifest): void
{
    $manifestFiles = [];
    $seenFiles = [];

    foreach (($manifest['files'] ?? []) as $file) {
        if (! is_array($file) || ! isset($file['path'], $file['sha256'])) {
            throw new RuntimeException('Backup manifest file list is invalid.');
        }

        $manifestFiles[(string) $file['path']] = (string) $file['sha256'];
    }

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';

        if ($name === '' || str_ends_with($name, '/')) {
            continue;
        }

        if (in_array($name, ['manifest.json', 'database.sql'], true)) {
            continue;
        }

        if (! backup_is_safe_upload_path($name) || ! array_key_exists($name, $manifestFiles)) {
            throw new RuntimeException('Backup contains an unexpected file: ' . $name);
        }

        $content = $zip->getFromName($name);

        if ($content === false || ! hash_equals($manifestFiles[$name], hash('sha256', $content))) {
            throw new RuntimeException('Backup file verification failed: ' . $name);
        }

        $seenFiles[$name] = true;
    }

    $missingFiles = array_diff_key($manifestFiles, $seenFiles);

    if ($missingFiles !== []) {
        throw new RuntimeException('Backup is missing a file listed in the manifest: ' . implode(', ', array_keys($missingFiles)));
    }
}

function backup_is_safe_upload_path(string $path): bool
{
    if (str_contains($path, '\\') || str_contains($path, '..') || str_starts_with($path, '/')) {
        return false;
    }

    if (! str_starts_with($path, 'uploads/shop/')) {
        return false;
    }

    $filename = basename($path);

    if ($filename === '' || str_starts_with($filename, '.')) {
        return false;
    }

    return preg_match('/\.(png|jpe?g|webp|gif|ico)$/i', $filename) === 1;
}

function backup_verify_sql(string $sql): void
{
    if (! str_starts_with($sql, '-- StockPilot Backup')) {
        throw new RuntimeException('SQL backup was not created by this system.');
    }

    if (! str_contains($sql, '-- Backup-Version: ' . BACKUP_VERSION)) {
        throw new RuntimeException('SQL backup version is not supported.');
    }

    $requiredTables = backup_known_tables();
    $createdTables = [];

    foreach (backup_split_sql_statements($sql) as $statement) {
        $statement = backup_clean_sql_statement($statement);

        if ($statement === '') {
            continue;
        }

        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $statement) === 1) {
            continue;
        }

        if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`([A-Za-z0-9_]+)`$/i', $statement, $matches) === 1) {
            backup_assert_known_table($matches[1]);
            continue;
        }

        if (preg_match('/^CREATE\s+TABLE\s+`([A-Za-z0-9_]+)`\s*\(/is', $statement, $matches) === 1) {
            backup_assert_known_table($matches[1]);
            $createdTables[] = $matches[1];
            continue;
        }

        if (preg_match('/^INSERT\s+INTO\s+`([A-Za-z0-9_]+)`\s*\(/is', $statement, $matches) === 1) {
            backup_assert_known_table($matches[1]);
            continue;
        }

        throw new RuntimeException('Backup contains an unsupported SQL statement.');
    }

    $missingTables = array_diff($requiredTables, array_unique($createdTables));

    if ($missingTables !== []) {
        throw new RuntimeException('Backup is missing required table schema: ' . implode(', ', $missingTables));
    }
}

function backup_assert_known_table(string $table): void
{
    if (! in_array($table, backup_known_tables(), true)) {
        throw new RuntimeException('Backup contains an unknown table: ' . $table);
    }
}

function backup_clean_sql_statement(string $statement): string
{
    $statement = trim($statement);

    while (preg_match('/^\s*--[^\n]*(\n|$)/', $statement) === 1) {
        $statement = preg_replace('/^\s*--[^\n]*(\n|$)/', '', $statement, 1) ?? '';
        $statement = ltrim($statement);
    }

    return trim($statement);
}

function backup_split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        $buffer .= $char;

        if ($quote !== null) {
            if ($char === $quote) {
                if ($quote === "'" && ($sql[$index + 1] ?? '') === "'") {
                    $buffer .= $sql[++$index];
                    continue;
                }

                if (($sql[$index - 1] ?? '') !== '\\') {
                    $quote = null;
                }
            }

            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            continue;
        }

        if ($char === ';') {
            $statements[] = substr($buffer, 0, -1);
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') {
        $statements[] = $buffer;
    }

    return $statements;
}

function backup_restore_sql(PDO $pdo, string $sql): void
{
    backup_verify_sql($sql);

    foreach (backup_split_sql_statements($sql) as $statement) {
        $statement = backup_clean_sql_statement($statement);

        if ($statement === '') {
            continue;
        }

        $pdo->exec($statement);
    }
}

function backup_restore_zip_files(string $zipPath): void
{
    $zip = new ZipArchive();

    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Could not reopen ZIP backup for file restore.');
    }

    $root = realpath(dirname(__DIR__));

    if ($root === false) {
        $zip->close();
        throw new RuntimeException('Application path could not be resolved.');
    }

    $shopDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'shop';

    if (! is_dir($shopDir) && ! mkdir($shopDir, 0755, true)) {
        $zip->close();
        throw new RuntimeException('Could not prepare upload restore folder.');
    }

    foreach (new DirectoryIterator($shopDir) as $file) {
        if ($file->isFile() && preg_match('/\.(png|jpe?g|webp|gif|ico)$/i', $file->getFilename()) === 1) {
            @unlink($file->getPathname());
        }
    }

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';

        if (! backup_is_safe_upload_path($name)) {
            continue;
        }

        $content = $zip->getFromName($name);

        if ($content === false) {
            $zip->close();
            throw new RuntimeException('Could not restore upload file: ' . $name);
        }

        $destination = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $destinationDir = dirname($destination);

        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0755, true)) {
            $zip->close();
            throw new RuntimeException('Could not create upload restore folder.');
        }

        file_put_contents($destination, $content, LOCK_EX);
    }

    $zip->close();
}
