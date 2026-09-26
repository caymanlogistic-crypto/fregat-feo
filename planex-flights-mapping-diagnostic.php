<?php
/** PHP 8.0+. Place next to legacy flights.php/config.php. Read-only diagnostics. */
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$mappingDiagnosticKey = trim((string) (getenv('MAPPING_DIAGNOSTIC_KEY') ?: ''));
// If ERPv2 is not a sibling directory, set its absolute directory here.
$erpRoot = dirname(__DIR__) . '/erpv2';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !is_string($_POST['access_key'] ?? null)
    || $mappingDiagnosticKey === '' || !hash_equals($mappingDiagnosticKey, $_POST['access_key'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Сопоставление рейсов</title>'
        . '<h1>Диагностика клиента, менеджеров и исполнителей</h1>'
        . '<form method="post"><label>Ключ доступа <input type="password" name="access_key" required autocomplete="off"></label> '
        . '<button type="submit">Скачать отчёт</button></form></html>';
    exit;
}

function mappingDiagnosticConnect(array $cfg): PDO
{
    $pdo = new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port'] ?? 3306)
        .';dbname='.($cfg['database'] ?? $cfg['dbname']).';charset=utf8mb4',
        $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    return $pdo;
}

function mappingDiagnosticQuery(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['ok'=>true,'row_count'=>count($rows),'rows'=>$rows];
    } catch (PDOException $error) {
        return ['ok'=>false,'sqlstate'=>(string)$error->getCode(),'mysql_error_code'=>$error->errorInfo[1] ?? null];
    }
}

$report = ['version'=>2,'generated_at_utc'=>gmdate('c'),'scope'=>'flights.id > 225','read_only'=>true];
$handles = [];
$stage = 'legacy_config';
$bufferLevel = ob_get_level();
try {
    ob_start();
    require __DIR__.'/config.php';
    while (ob_get_level() > $bufferLevel) ob_end_clean();
    $stage = 'legacy_connection';
    $legacy = mappingDiagnosticConnect($dbConfig); $handles[] = $legacy;
    $stage = 'legacy_managers';
    $report['legacy_managers'] = mappingDiagnosticQuery($legacy,
        'SELECT f.assigned_manager_id AS legacy_manager_id,u.`Фамилия` AS surname,u.`Имя` AS first_name,
         COUNT(*) AS flight_count FROM flights f LEFT JOIN users u ON u.id=f.assigned_manager_id
         WHERE f.id > 225 GROUP BY f.assigned_manager_id,u.`Фамилия`,u.`Имя` ORDER BY f.assigned_manager_id');
    $report['legacy_flights'] = mappingDiagnosticQuery($legacy,
        'SELECT f.id,f.assigned_manager_id,u.`Фамилия` AS manager_surname,u.`Имя` AS manager_first_name,
         f.zayavki_ids,f.unload_type,f.planned_start_date,f.planned_start_date_from,f.planned_start_date_to
         FROM flights f LEFT JOIN users u ON u.id=f.assigned_manager_id WHERE f.id > 225 ORDER BY f.id');
    $report['legacy_destinations'] = mappingDiagnosticQuery($legacy,
        'SELECT id,route_type,source_warehouse_id,destination_warehouse_id FROM flights WHERE id > 225 ORDER BY id');

    $stage = 'erp_bootstrap';
    if (!is_file($erpRoot.'/bootstrap/app.php') || !is_file($erpRoot.'/.env')) {
        $report['erp'] = ['ok'=>false,'reason'=>'ERP_ROOT_NOT_FOUND',
            'hint'=>'Укажите в переменной $erpRoot этого скрипта абсолютную папку ERPv2 (с bootstrap/app.php и .env). Старые менеджеры уже включены в отчёт.'];
    } else {
        ob_start();
        // Bootstrap only loads environment/helpers/configuration. No HTTP front controller,
        // migration runner, user projection, application routes or legacy flights.php is executed.
        $erpConfig = require $erpRoot.'/bootstrap/app.php';
        require_once $erpRoot.'/app/Support/crypto_helper.php';
        require_once $erpRoot.'/app/Support/company_database.php';
        while (ob_get_level() > $bufferLevel) ob_end_clean();
        $stage = 'erp_central_connection';
        $central = mappingDiagnosticConnect($erpConfig['database']); $handles[] = $central;
        $companies = $central->query("SELECT id,name,db_identifier,db_host,db_port,db_username,db_password
            FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll();
        $report['erp'] = ['ok'=>true,'companies'=>[]];
        foreach ($companies as $company) {
            $stage = 'erp_company_read';
            $entry = ['company_id'=>(int)$company['id'],'company_name'=>(string)$company['name']];
            try {
                $tenant = mappingDiagnosticConnect(companyDatabaseConfig($erpConfig, $company)); $handles[] = $tenant;
                $entry['mg_clients'] = mappingDiagnosticQuery($tenant,
                    "SELECT id,name,inn,status FROM clients WHERE deleted_at IS NULL AND (name LIKE ? OR name LIKE ?) ORDER BY id", ['%МГ%','%MG%']);
                $entry['managers'] = mappingDiagnosticQuery($central,
                    "SELECT m.id AS membership_id,a.full_name,m.status AS membership_status,a.status AS account_status
                     FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id
                     JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
                     WHERE m.company_id=? AND m.status='active' AND a.status='active' ORDER BY a.full_name,m.id", [(int)$company['id']]);
                $entry['executors'] = mappingDiagnosticQuery($tenant,
                    "SELECT c.id AS crew_id,ct.name AS contractor_name,d.full_name,
                     vu.plate_number AS primary_plate,c.status
                     FROM crews c JOIN contractors ct ON ct.id=c.contractor_id
                     JOIN crew_drivers cd ON cd.crew_id=c.id JOIN drivers d ON d.id=cd.driver_id
                     JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id
                     JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id
                     LEFT JOIN vehicle_units vu ON vu.id=vs.primary_vehicle_unit_id
                     WHERE c.status='active' ORDER BY c.id,cd.position");
                $entry['existing_legacy_trips'] = mappingDiagnosticQuery($tenant,
                    "SELECT wt.business_id,wt.legacy_flight_id,wt.source_system,wt.status,
                     c.name AS client_name,u.full_name AS manager_name,wt.destination_type,wt.utilizer_id
                     FROM waste_trips wt LEFT JOIN clients c ON c.id=wt.client_id
                     LEFT JOIN users u ON u.id=wt.responsible_user_id
                     WHERE wt.source_system IN ('legacy_flights','legacy_prefill','legacy_sync')
                     AND wt.legacy_flight_id > 225 ORDER BY wt.business_id");
            } catch (Throwable $error) {
                $entry['error'] = ['stage'=>$stage,'type'=>get_class($error),'code'=>(string)$error->getCode()];
                $report['erp']['ok'] = false;
            }
            $report['erp']['companies'][] = $entry;
        }
    }
} catch (Throwable $error) {
    while (ob_get_level() > $bufferLevel) ob_end_clean();
    $report['error'] = ['stage'=>$stage,'type'=>get_class($error),'code'=>(string)$error->getCode()];
} finally {
    foreach ($handles as $handle) {
        if ($handle->inTransaction()) {
            try { $handle->rollBack(); } catch (Throwable) { $report['transaction_cleanup_error'] = true; }
        }
    }
}
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="planex-flights-mapping-diagnostic.json"');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
