<?php
/*
channel => @mirzapanel
*/
require_once __DIR__ . '/env.php';
// .env is git-ignored and holds real secrets locally; .env.example documents
// the expected keys with placeholder values and is safe to commit.
loadEnv(__DIR__);

//-----------------------------database-------------------------------
$dbhost     = env('DB_HOST', 'localhost');
$dbname     = env('DB_NAME', 'gozaarsellbot'); //  Name Database
$usernamedb = env('DB_USERNAME', 'root'); // Username Database
$passworddb = env('DB_PASSWORD', ''); // Password Database
$connect = mysqli_connect($dbhost, $usernamedb, $passworddb, $dbname);
if ($connect->connect_error) {
    die("The connection to the database failed:" . $connect->connect_error);
}
mysqli_set_charset($connect, "utf8mb4");

//-----------------------------info-------------------------------

$APIKEY = env('BOT_API_KEY'); // Token Bot of Botfather
$adminnumber = env('ADMIN_NUMBER'); // Id Number Admin
$domainhosts = env('DOMAIN_HOST'); // Domain Host and Path of Bot without trailing /
$usernamebot = env('BOT_USERNAME'); // Username Bot without @

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int) $e->getCode());
}
