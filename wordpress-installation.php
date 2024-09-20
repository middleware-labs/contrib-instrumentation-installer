<?php

require __DIR__ . '/installer_internals.php';


function copyOrMoveDirectory($source, $destination, $mode = 'copy')
{
    if (!is_dir($source)) {
        colorLog("Source directory doesn't exist", "e");
        exit(-1);
    }

    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    $dir = opendir($source);
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            $srcFile = $source . '/' . $file;
            $destFile = $destination . '/' . $file;

            if (is_dir($srcFile)) {
                copyOrMoveDirectory($srcFile, $destFile, $mode);
            } else {
                if ($mode === 'copy') {
                    copy($srcFile, $destFile);
                } else {
                    rename($srcFile, $destFile);
                }
            }
        }
    }
    closedir($dir);

    if ($mode === 'move') {
        rmdir($source);
    }
}

function writeStringToFile($filename, $content)
{
    // Check if we can open the file for writing
    $file = fopen($filename, 'w');

    if ($file === false) {
        throw new Exception("Unable to open file: $filename");
    }

    // Write the content to the file
    $bytesWritten = fwrite($file, $content);

    if ($bytesWritten === false) {
        fclose($file);
        throw new Exception("Failed to write to file: $filename");
    }

    // Close the file
    fclose($file);

    return $bytesWritten;
}

function findPhpApacheConfigDir()
{
    // Get PHP version
    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    // Possible paths
    $possiblePaths = [
        "/etc/php/$phpVersion/apache2/conf.d",
        "/etc/php/$phpVersion/apache/conf.d",
        "/etc/php/apache2/conf.d",
        "/etc/php/apache/conf.d"
    ];

    foreach ($possiblePaths as $path) {
        if (is_dir($path)) {
            return $path;
        }
    }

    // If not found, try to use php -i
    $phpInfo = shell_exec('php -i');
    if ($phpInfo) {
        if (preg_match('/Scan this dir for additional .ini files => (.+)/', $phpInfo, $matches)) {
            $scanDir = trim($matches[1]);
            if (strpos($scanDir, 'apache') !== false) {
                return $scanDir;
            }
        }
    }

    return null;
}

function checkRequirements()
{
    check_php_version();
    if (!command_exists("composer")) {
        colorLog("composer is not installed", 'e');
        exit(-1);
    }
    if (PHP_OS_FAMILY === "Windows") {
        colorLog("windows is not supported yet", 'e');
        exit(-1);
        if (!command_exists("cl") && !command_exists("link")) {
            colorLog("c compiler is not installed or not available", 'e');
            exit(-1);
        }
    } else {
        if (!command_exists("gcc --version") && !command_exists("clang --version")) {
            colorLog("c compiler is not installed or not available", 'e');
            exit(-1);
        }
    }
    if (!command_exists("phpize")) {
        colorLog("php-sdk is not installed", 'e');
        exit(-1);
    }
    get_pickle();
}


// downloads and copies otel files to /var/www/otel
function setup()
{
    // install opentelemetry extension
    execute_command(make_pickle_install(
        "opentelemetry",
        " -n"
    ), " 2>&1");

    create_ini_file(get_ini_scan_dir());
    $configDir = findPhpApacheConfigDir();
    create_ini_file($configDir);

    $composerInstallCmd = 'composer init --name "middleware-labs/wp-auto-instrumentation" --require "open-telemetry/opentelemetry-auto-wordpress":^0.0.15 --require "open-telemetry/sdk":"^1.0" --require "open-telemetry/exporter-otlp":"^1.0" --require "php-http/guzzle7-adapter":"^1.0" --no-interaction';

    execute_command($composerInstallCmd, '');

    execute_command("composer install --no-interaction", "");

    // copy content inside vendor to /var/www/otel/
    copyOrMoveDirectory("vendor", "/var/www/otel", "move");

    // set ini directory
    set_ini();
}

function set_env_new()
{
    // put important and fixed envs first
    putenv("OTEL_PHP_AUTOLOAD_ENABLED=true");
    putenv("OTEL_TRACES_EXPORTER=otlp");
    putenv("OTEL_EXPORTER_OTLP_PROTOCOL=http/json");
    putenv("OTEL_PROPAGATORS=baggage,tracecontext");

    if (empty(getenv("OTEL_SERVICE_NAME"))) {
        $service_name = getenv("MW_AGENT_SERVICE");
        if (empty($service_name))
            $service_name = "service-" . getmypid();

        putenv("OTEL_SERVICE_NAME=" . $service_name);
    }

    if (empty(getenv("OTEL_EXPORTER_OTLP_ENDPOINT"))) {
        putenv("OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:9320");
    }
}

// set otel.php.in and add prepand autoload code to it.
function set_ini()
{
    $configDir = findPhpApacheConfigDir();
    if ($configDir) {
        colorLog("PHP Apache configuration directory: $configDir\n", "i");
    } else {
        colorLog("Could not determine PHP Apache configuration directory.\n", "e");
        exit(-1);
    }

    $content = "auto_prepend_file=/var/www/otel/autoload.php";

    writeStringToFile($configDir . "/mw.wordpress.ini", $content);

    $envS = `OTEL_PHP_AUTOLOAD_ENABLED=true\n
             OTEL_SERVICE_NAME=your-service-name\n 
             OTEL_TRACES_EXPORTER=otlp\n
             OTEL_EXPORTER_OTLP_PROTOCOL=http/json\n
             OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:9320\n
             OTEL_PROPAGATORS=baggage,tracecontext\n`;

    echo "Please set this enviroment variables in apache config: \n" . $envS;
}

checkRequirements();
setup();
// ask user to set env variables