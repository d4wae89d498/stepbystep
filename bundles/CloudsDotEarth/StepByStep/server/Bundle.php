<?php
/**
 * Created by PhpStorm.
 * User: marcfsr
 * Date: 28/06/2019
 * Time: 18:16
 */

namespace CloudsDotEarth\StepByStep;

use Symfony\Component\Dotenv\Dotenv;

class Bundle
{
    /**
     * @var Controller[]
     */
    public $controllers = [];
    /**
     * @var string[]
     */
    public $views = [];
    public $controllers_methods = [];
    /**
     * @var Bundle[]
     */
    public $twig = null;
    /**
     * @var Bundle
     */
    public $main_bundle = null;
    public $child_bundles = [];
    public $db_conn = null;
    /**
     * @var bool
     */
    public $is_main = true;
    public $root_path = "";
    public $relative_model_root = "/model";
    public $relative_template_root = "/template";
    public $relative_controller_root = "/controller";
    public $relative_web_root = "/asset";
    public $relative_server_root = "/server";

    public function __construct($root_path, $relative_controller_root = null, $relative_model_root = null, $relative_web_root = null, $relative_server_root = null, $main_bundle = null)
    {
        $this->root_path = $root_path;
        $this->is_main = $main_bundle === null;
        if ($this->is_main)
            $this->main_bundle = $this;
        else
            $this->main_bundle = $main_bundle;
        $GLOBALS["main_bundle"] = $this->main_bundle;
        echo "Loading bundle using root path : $root_path is main :  $this->is_main" . PHP_EOL;
        $this->relative_controller_root = $relative_controller_root ?? $this->relative_controller_root;
        $this->relative_model_root = $relative_model_root ?? $this->relative_model_root;
        $this->relative_web_root = $relative_web_root ?? $this->relative_web_root;
        $this->relative_server_root = $relative_server_root ?? $this->relative_server_root;
        $this->loadControllers();
        $this->loadViews();
        if ($this->is_main) {
            $this->init();
            $this->appendControllersMethods();
            $this->loadChildBundles();
            $this->loadTwig();
        }
        if ($this->is_main)  $this->run();
    }

    public static function get_pgsql() : \Swoole\Coroutine\PostgreSql {
        $vars = []; foreach (explode(" ", "DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD") as $v)  $vars[$v] = getenv($v);
        $vars = (object) $vars;
        $pg = new \Swoole\Coroutine\PostgreSql();
        $conn = $pg -> connect ("host=$vars->DB_HOST port=$vars->DB_PORT dbname=$vars->DB_NAME user=$vars->DB_USER password=$vars->DB_PASSWORD");
        if(!$conn) {
            throw new \Exception("Unable to connect to the database, please edit your .env settings");
        } else {
            echo "Connected!" . PHP_EOL;
            return $pg;
        }
    }

    public function generateModelsIfNeeded() {
        $model_generator = new ModelGenerator($this, "$this->root_path/model");
        $model_generator->secondStep();
    }

    public function loadViews() {
        $data = json_decode(file_get_contents($this->root_path . "/composer.json"));
        foreach (glob($this->root_path . $this->relative_template_root . "/{,*/,*/*/,*/*/*/}*.twig", GLOB_BRACE ) as $file) {
            $file_content = file_get_contents($file);
            $path = explode(".twig", explode($this->root_path . $this->relative_template_root, $file)[1])[0];
            $this->main_bundle->views[$data->name . $path] = $file_content;
        }
        var_dump( $this->main_bundle->views);
    }

    public function loadTwig() {
        $loader = new \Twig\Loader\ArrayLoader($this->views);
        $this->twig = new \Twig\Environment($loader, [
            'cache' => $this->main_bundle->root_path . "/cache",
        ]);
    }

    public function loadControllers() {
        echo "loading controllers ... " . PHP_EOL;
        foreach (glob($this->root_path . $this->relative_controller_root . "/{,*/,*/*/,*/*/*/}*.php", GLOB_BRACE ) as $file) {
            var_dump($file);
            $controller = Utils::getClassNameFromFile($file);
            array_push($this->main_bundle->controllers, new $controller());
        }
    }

    public function appendControllersMethods() {
        var_dump("appending controller methods ... ");
        foreach ($this->main_bundle->controllers as $e) {
            foreach ($e->methods as $f) {
                $this->main_bundle->controllers_methods[] = $f;
            }
        }
        usort(  $this->main_bundle->controllers_methods, function($a, $b) {
            return ($a["priority"] > $b["priority"] );
        });
    }

    public function loadChildBundles()
    {
        foreach (["/bundles", "/vendor"] as $v) {
            if (file_exists($this->root_path . $v))
                foreach (preg_grep('/^([^.])/',scandir($this->root_path . $v)) as $vendor_name)
                    if (is_dir($this->root_path . "$v/$vendor_name"))
                        foreach (preg_grep('/^([^.])/',scandir($this->root_path . "$v/$vendor_name")) as $package)
                            if (is_dir($this->root_path . "$v/$vendor_name/$package"))
                                foreach (preg_grep('/^([^.])/',scandir($this->root_path . "$v/$vendor_name/$package")) as $file)
                                    if ($file === "bundle.php")
                                        $this->child_bundles[] = require_once $this->root_path . "$v/$vendor_name/$package/$file";

        }
    }

    public function init() {
        $dotenv = new Dotenv();
        $dotenv->load("$this->root_path/.env");
        if ( isset($GLOBALS["argv"][1]) && strlen($GLOBALS["argv"][1]) > 0) {
            var_dump($GLOBALS["argv"][1]);

            $_ENV["DEFAULT_SERVER"] = $GLOBALS["argv"][1];
        }
    }

    public function run() {
        new Server($this, require_once "$this->root_path/server/" . $_ENV["DEFAULT_SERVER"] . ".php");
    }
}