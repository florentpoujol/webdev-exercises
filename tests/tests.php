<?php


if (!isset($argv[1])) {
    $testFiles = [];
    function walkDir(string $dirStr)
    {
        global $testFiles;
        $dir = opendir($dirStr);
        while (($file = readdir($dir)) !== false) {
            if ($file !== "." && $file !== ".." && is_dir($file)) {
                walkDir("$dirStr/$file");
                continue;
            }
            if (preg_match("/test\.php$/i", $file) === 1) {
                $file = str_replace(__dir__ . "/", "", $dirStr . "/" . $file);
                $testFiles[] = $file;
            }
        }
        closedir($dir);
    }
    walkDir(__dir__);
    sort($testFiles); // for some reason they are not yet in alphabetical order ...

    foreach ($testFiles as $file) {
        // $currentTestFile = $file;
        // echo $currentTestFile . "\n";

        $result = shell_exec(PHP_BINARY . " " . __file__ . " $file");
        if (trim($result) !== "") {
            echo $result . "\n";
            exit;
        }
    }

    echo "OK, " . count($testFiles) . " test files run successfully !\n";

    return;
}

const IS_TEST = true;

// --------------------------------------------------
// emulate index.php

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false
];
$testDb = new \PDO("sqlite::memory:", null, null, $options);
$sql = file_get_contents(__dir__ . "/database.sql");
$testDb->exec($sql); // using query() only creates the first table...

// create a first three users
$passwordHash = password_hash("Az3rty", PASSWORD_DEFAULT);
$testDb->query(
    "INSERT INTO users(name, email, email_token, password_hash, password_token, role, creation_date) VALUES 
    ('admin', 'admin@email.com', '', '$passwordHash', '', 'admin', '1970-01-01'), 
    ('writer', 'writer@email.com', '', '$passwordHash', '', 'writer', '1970-01-02'), 
    ('commenter', 'com@email.com', '', '$passwordHash', '', 'commenter', '1970-01-03')"
);


$testConfig = json_decode(file_get_contents( __dir__ . "/config.json"), true);


$_SERVER["SERVER_PROTOCOL"] = "HTTP/1.1"; // needed/used by setHTTPHeader()
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REQUEST_URI"] = "/index.php";
$_SERVER["SCRIPT_NAME"] = realpath(__dir__ . "/../public/index.php");


// --------------------------------------------------


$currentTestFile = ""; // updated in tests.php (this file)
$currentTestName = ""; // updated in each individual tests files/sections

function outputFailedTest(string $text)
{
    global $currentTestFile, $currentTestName;
    echo "\033[41mTest '$currentTestName' failed in file '$currentTestFile':\033[m\n";
    echo $text . "\n";
    exit;
}

function loadSite(string $queryString = null, int $userId = null): string
{
    if ($queryString !== null) {
        $_SERVER["QUERY_STRING"] = $queryString;
    }
    if ($userId !== null) {
        $_SESSION["user_id"] = $userId;
    }

    global $testDb, $testConfig,
           $db, $config, $site, $query, $errors, $successes;
    // this last line is needed to make these variables exist in the global scope
    // so that functions (in app/functions.php) can get them via "global $db;" for instance
    // Otherwise, these variable which are defined in the scope of the index.php file
    // would only exist in the scope of this function (loadApp()), which isn't in this case the global scope

    ob_start();
    require __dir__ . "/../public/index.php";
    return ob_get_clean();
}

function queryTestDB(string $strQuery, $data = null)
{
    global $testDb;
    $query = $testDb->prepare($strQuery);

    if ($data === null) {
        $query->execute();
    } else {
        if (! is_array($data)) {
            $data = [$data];
        }
        $query->execute($data);
    }

    return $query;
}

function getUser(string $value, string $field = "name")
{
    $user = queryTestDB("SELECT * FROM users WHERE $field = ?", $value)->fetch();
    $user["id"] = (int)$user["id"];
    return $user;
}

function setCSRFToken(string $requestName = ""): string
{
    // @todo allow to have several csrf tokens per user
    $token = bin2hex( random_bytes(40 / 2) );
    $_SESSION[$requestName . "_csrf_token"] = $token;
    $_SESSION[$requestName . "_csrf_time"] = time();
    $_POST["csrf_token"] = $token;
    return $token;
}

session_start(); // session needs to start here instead of the front controller called from loadSite()
// mostly so that we can populate the $_SESSION superglobal

require_once __dir__ . "/asserts.php";

$currentTestFile = $argv[1];
$_SESSION = [];
$_POST = [];

$functions = require_once $currentTestFile;
if (!is_array($functions)) {
    $functions = [];
}

$functions = array_filter(
    get_defined_functions(true)["user"],
    function (string $funcName) {
        return substr($funcName, 0, 4) === "test";
    }
);

$funcToRun = $argv[2] ?? "";
if ($funcToRun !== "") {
    if (function_exists($funcToRun)) {
        $currentTestName = str_replace(["test_", "_"], ["", " "], $funcToRun);
        $funcToRun();
    }
} else {
    // run all funcs
    foreach ($functions as $funcToRun) {
        $result = shell_exec(PHP_BINARY . " " . __file__ . " $currentTestFile $funcToRun");
        if (trim($result) !== "") {
            echo $result . "\n";
            exit;
        }
    }
}
