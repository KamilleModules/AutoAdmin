<?php


namespace Module\AutoAdmin\CrudGenerator;


use ArrayToString\ArrayToStringTool;
use Bat\FileSystemTool;
use CrudGeneratorTools\Skinny\Generator\SkinnyModelGeneratorInterface;

use CrudGeneratorTools\Skinny\SkinnyTypeUtil;
use CrudGeneratorTools\Util\ForeignKeyPreferredColumnUtil;
use Kamille\Ling\Z;
use Module\AutoAdmin\AutoAdminHelper;
use Module\AutoAdmin\CrudGenerator\Skinny\Generator\NullosSkinnyModelGenerator;
use Module\AutoAdmin\CrudGenerator\Skinny\Generator\NullosskinnyTypeUtil;
use Module\AutoAdmin\CrudGenerator\Util\NullosForeignKeyPreferredColumnUtil;
use QuickPdo\Util\QuickPdoInfoCacheUtil;


/**
 * This is the NullosCrudGenerator made by the AutoAdmin planet.
 */
class NullosCrudGenerator
{

    /**
     * @var $logFunction , the function used to log this class activity.
     * It receives two arguments: type and msg.
     * Type can be one of: error, debug, maybe other types in the future.
     */
    private $logFunction;

    /**
     * @var ForeignKeyPreferredColumnUtil $foreignKeyPreferredColumnUtil
     */
    private $foreignKeyPreferredColumnUtil;

    /**
     * @var SkinnyTypeUtil $skinnyTypeUtil
     */
    private $skinnyTypeUtil;

    /**
     * @var SkinnyModelGeneratorInterface $skinnyModelGenerator
     */
    private $skinnyModelGenerator;
    private $allowSkinnyModelGeneratorConfiguration;

    /**
     * @var QuickPdoInfoCacheUtil $quickPdoInfoCache
     */
    private $quickPdoInfoCache;


    /**
     * @var string, name of the module, used by NullosskinnyTypeUtil
     * to generate parameters for autocomplete and upload types.
     */
    private $module;
    private $hasNewTable;


    public function __construct()
    {
        $this->module = 'AutoAdmin';
        $this->hasNewTable = false;
    }

    public static function create()
    {
        return new static();
    }


    public function generate($database, array $options = [])
    {

        $options = array_replace([
            'hasNewTable' => true,
        ], $options);

        $this->hasNewTable = $options['hasNewTable'];

        $this->prepare($database);
        $this->generateMenu($database);
        $this->generateItems($database);

    }


    public function setModule($module)
    {
        $this->module = $module;
        return $this;
    }





    //--------------------------------------------
    //
    //--------------------------------------------

    public function setSkinnyTypeUtil(SkinnyTypeUtil $skinnyTypeUtil)
    {
        $this->skinnyTypeUtil = $skinnyTypeUtil;
        return $this;
    }

    public function setSkinnyModelGenerator(SkinnyModelGeneratorInterface $generator, $autoConfigure = true)
    {
        $this->skinnyModelGenerator = $generator;
        $this->allowSkinnyModelGeneratorConfiguration = $autoConfigure;
        return $this;
    }


    public function getSqlQuery($db, $table)
    {
        $prefixedColumns = $this->getPrefixedColumns($db, $table);
        $joins = $this->getJoinsList($db, $table);
        return [
            $prefixedColumns,
            $joins,
        ];
    }


    //--------------------------------------------
    //
    //--------------------------------------------
    protected function getPrefixedColumns($db, $table, array &$foreignKeys = [])
    {
        $prefixedColumns = [];
        $foreignKeysInfo = $this->quickPdoInfoCache->getForeignKeysInfo($table, $db);

        $columns = $this->quickPdoInfoCache->getColumnNames($table, $db);

        foreach ($columns as $col) {
            if (array_key_exists($col, $foreignKeysInfo)) {
                $info = $foreignKeysInfo[$col];
                $prefixedColumns[] = $info[1] . "." . $this->foreignKeyPreferredColumnUtil->getPreferredForeignKey($info[0], $info[1]);
                $foreignKeys[] = $table . "." . $col;
//                $prefixedColumns[] = $this->getForeignKeyPreferredColumn($info[0], $info[1], $info[2], $schema, $tableOnly, $col);
            } else {
                $prefixedColumns[] = $table . "." . $col;
            }
        }


        // ensuring that all ric fields are present (for foreign keys that we hide, but will be present)
        $ric = $this->getRic($db, $table, false);
        $ric = array_map(function ($v) use ($table) {
            return $table . "." . $v;
        }, $ric);
        $prefixedColumns = array_merge($ric, $prefixedColumns);
        $prefixedColumns = array_unique($prefixedColumns);
        return $prefixedColumns;
    }

    protected function getJoinsList($db, $table)
    {
        $inner = [];
        $left = [];
        $fTable = $db . '.' . $table;

        $col2Nullable = $this->quickPdoInfoCache->getColumnNullabilities($fTable);
        $fkInfo = $this->quickPdoInfoCache->getForeignKeysInfo($table, $db);

        foreach ($fkInfo as $column => $info) {
            $ftable = $info[0] . '.' . $info[1];
            $fcol = $info[2];
            $join = "join $ftable on $ftable.$fcol=$table.$column";
            if (array_key_exists($column, $col2Nullable) && true === $col2Nullable[$column]) {
                $left[] = "left $join";
            } else {
                $inner[] = "inner $join";
            }
        }

        return [
            $inner,
            $left,
        ];
    }


    protected function generateMenu($db)
    {
        $this->debug("generating menu for database for $db");
        $dir = AutoAdminHelper::getGeneratedSideBarMenuPath() . "/auto";
        $f = $dir . "/$db.php";


        $tables = $this->quickPdoInfoCache->getTables($db);
        $m = $this->module;
        $tableItems = [];

        foreach ($tables as $table) {
            $tableItems[] = [
                "label" => $table,
                "link" => "/crud?type=list&prc=$m.$db.$table",
                "items" => null,
                "icon" => '',
            ];
        }
        $items = [
            "icon" => "fa fa-database",
            "label" => $db,
            "items" => $tableItems,
        ];
        $string = ArrayToStringTool::toPhpArray($items);


        $string = str_replace("'/crud?type", "\$uriCrud . '?type", $string);

        $data = '<?php' . PHP_EOL . PHP_EOL;
        $data .= 'use Kamille\Ling\Z;' . PHP_EOL . PHP_EOL;
        $data .= '$uriCrud = Z::link("NullosAdmin_crud");' . PHP_EOL . PHP_EOL;
        $data .= '$items = ' . $string . ';' . PHP_EOL . PHP_EOL;
        $this->trace("generateMenu: creating file $f");
        FileSystemTool::mkfile($f, $data);
    }


    protected function generateItems($db)
    {

        $tables = $this->quickPdoInfoCache->getTables($db);
        foreach ($tables as $table) {


            $this->generateDataTableProfile($db, $table);
            $this->generatePrc($db, $table);

        }
    }


    private function generatePrc($db, $table)
    {
        $this->debug("generating prc for $db.$table");
        $tpl = __DIR__ . "/../assets/class-prc/ExamplePersistentRowCollection.tpl.php";
        $content = file_get_contents($tpl);
        $uses = [];


        $Table = ucfirst($table);

        list($prefixedColumns, $joins) = $this->getSqlQuery($db, $table);


        $prefixedColumns = array_map(function ($v) {
            return "$v as `$v`";
        }, $prefixedColumns);


        $fields = PHP_EOL . implode(',' . PHP_EOL, $prefixedColumns) . PHP_EOL;


        $q = PHP_EOL . "SELECT" . PHP_EOL;
        $q .= "%s" . PHP_EOL;
        $q .= "FROM $db.$table" . PHP_EOL;
        if (count($joins[0]) > 0) {
            $q .= implode(PHP_EOL, $joins[0]) . PHP_EOL;
        }
        if (count($joins[1]) > 0) {
            $q .= implode(PHP_EOL, $joins[1]) . PHP_EOL;
        }


        $ric = $this->getRic($db, $table);
        $sRic = ArrayToStringTool::toPhpArray($ric);


        $module = $this->module;
        $snippets = [];
        $this->generateFormModelValidator($db, $table, $snippets, $uses);
        $sValidator = '';
        if (count($snippets) > 0) {
            $sValidator .= '$validator';
            foreach ($snippets as $snippet) {
                $sValidator .= PHP_EOL . str_repeat("\t", 3) . $snippet;
            }
            $sValidator .= ';' . PHP_EOL;
        }


        $sModel = '';
        $snippets = [];

        $this->skinnyModelGenerator->generateFormModel($db, $table, $snippets, $uses);


        if (count($snippets) > 0) {
            $sModel .= '$model';
            foreach ($snippets as $snippet) {
                $sModel .= PHP_EOL . $snippet;
            }
            $sModel .= ';' . PHP_EOL;
        }


        $uses = array_unique($uses);
        $sUse = 'use ' . implode(';' . PHP_EOL . 'use ', $uses) . ';' . PHP_EOL;


        $aic = 'null';
        if (false !== ($autoInc = $this->quickPdoInfoCache->getAutoIncrementedField($table, $db))) {
            $aic = "'$autoInc'";
        }

        $Db = ucfirst($db);

        $namespace = 'namespace Prc\\' . $this->module . '\\' . $Db . '\Auto;' . PHP_EOL;
        $tags = [
            'ExamplePersistentRowCollection' => $Table . 'PersistentRowCollection',
            '{db}' => $db,
            '{table}' => $table,
            '{fields}' => $fields,
            '{query}' => $q,
            '$ric' => $sRic,
            '//-validator' => $sValidator,
            '//-model' => $sModel,
            '$aic' => $aic,
            '//-use' => $sUse,
            '//-namespace' => $namespace,
        ];

        $c = str_replace(array_keys($tags), array_values($tags), $content);
        $dst = Z::appDir() . "/class-prc/$module/$Db/Auto/$Table" . "PersistentRowCollection.php";
        $this->trace("generatePrc: creating file $dst");
        FileSystemTool::mkfile($dst, $c);
    }


    private function generateDataTableProfile($db, $table)
    {
        $this->debug("generating datatable profile for $db.$table");
        $tpl = __DIR__ . "/../assets/config/datatable-profiles/auto.tpl.php";

        $Table = ucfirst($table);


        $hidden = [];
        $headers = $this->getPrefixedColumns($db, $table, $hidden);

        $headers[] = 'action';


        $model = [
            'model' => [
                'headers' => $headers,
                'hidden' => $hidden,
                'ric' => $this->getRic($db, $table, true),
                'actionButtons' => [
                    'addItem' => [
                        'label' => "Add $Table",
                    ],
                ],
            ],
        ];


        $c = file_get_contents($tpl);
        $c = str_replace([
            '{Module}',
            '{table}',
            '$model',
            '{db}',
        ], [
            $this->module,
            $table,
            ArrayToStringTool::toPhpArray($model),
            $db,
        ], $c);


        $dst = Z::appDir() . "/config/datatable-profiles/" . $this->module . "/auto/$db/$table.php";
        $this->trace("generateDataTableProfile: creating file $dst");
        FileSystemTool::mkfile($dst, $c);
    }

    //--------------------------------------------
    //
    //--------------------------------------------
    private function prepare($db)
    {


        $this->quickPdoInfoCache = QuickPdoInfoCacheUtil::create()->cache(true);

        if (true === $this->hasNewTable) {
            $this->debug("recreating quickPdoInfoCache cache");
            $this->quickPdoInfoCache->prepareDb($db);
        }

        if (null === $this->foreignKeyPreferredColumnUtil) {
            $this->foreignKeyPreferredColumnUtil = NullosForeignKeyPreferredColumnUtil::create()
                ->setUseCache(true)
                ->setOnFileGeneratedCallback(function ($file) {
                    $this->debug("NullosForeignKeyPreferredColumnUtil: created preferredColumn cache at $file");
                });

            if (true === $this->hasNewTable) {
                $this->foreignKeyPreferredColumnUtil->prepareDb($db);
            }

        }

        if (null === $this->skinnyTypeUtil) {
            $this->skinnyTypeUtil = NullosSkinnyTypeUtil::create()
                ->setOnTypesGeneratedCb(function ($file) {
                    $this->debug("NullosSkinnyTypeUtil: created skinnyTypes cache at $file");
                })
                ->setQuickPdoInfoCache($this->quickPdoInfoCache)
                ->setForeignKeyPreferredColumnUtil($this->foreignKeyPreferredColumnUtil)
                ->setModule($this->module);

            if (true === $this->hasNewTable) {
                $this->skinnyTypeUtil->prepareDb($db);
            }
        }
        $configure = $this->allowSkinnyModelGeneratorConfiguration;
        if (null === $this->skinnyModelGenerator) {
            $this->skinnyModelGenerator = NullosSkinnyModelGenerator::create();
            $configure = true;
        }

        if (true === $configure) {
            $this->skinnyModelGenerator
                ->setQuickPdoInfoCache($this->quickPdoInfoCache)
                ->setForeignKeyPreferredColumnUtil($this->foreignKeyPreferredColumnUtil)
                ->setSkinnyTypeUtil($this->skinnyTypeUtil);
        }
    }


    //--------------------------------------------
    // LOG
    //--------------------------------------------
    public function setLogFunction(callable $logFunction)
    {
        $this->logFunction = $logFunction;
        return $this;
    }

    private function debug($msg)
    {
        $this->log('debug', $msg);
    }


    private function trace($msg)
    {
        $this->log('trace', $msg);
    }

    private function error($msg)
    {
        $this->log('error', $msg);
    }

    private function log($type, $msg)
    {
        if (null !== $this->logFunction) {
            call_user_func($this->logFunction, $type, $msg);
        }
    }

    //--------------------------------------------
    //
    //--------------------------------------------
    private function getRic($db, $table, $prefixWithTable = false)
    {
        $columnNames = $this->quickPdoInfoCache->getColumnNames($table, $db);
        $primaryKey = $this->quickPdoInfoCache->getPrimaryKey($table, $db);
        if (0 === count($primaryKey)) {
            $primaryKey = $columnNames;
        }
        if (true === $prefixWithTable) {
            $primaryKey = array_map(function ($v) use ($table) {
                return $table . "." . $v;
            }, $primaryKey);
        }
        return $primaryKey;
    }

    private function hasFeature($feature)
    {
        return array_key_exists($feature, $this->features);
    }

    private function generateFormModelValidator($db, $table, array &$snippets, array &$uses)
    {
        /**
         * By default, we will generate the following:
         * - required on every non nullable varchar
         */
        $datatypes = $this->quickPdoInfoCache->getColumnDataTypes($db . '.' . $table, false);
        $fks = $this->quickPdoInfoCache->getForeignKeysInfo($table, $db);
        $nullables = $this->quickPdoInfoCache->getColumnNullabilities($db . '.' . $table);


        foreach ($datatypes as $column => $datatype) {
            if ('varchar' === $datatype) {
                if (false === $nullables[$column]) {
                    $snippets[] = <<<EEE
->setTests("$column", "$column", [
                RequiredControlTest::create(),
            ])
EEE;

                    $uses[] = 'FormModel\Validation\ControlTest\WithFields\RequiredControlTest';
                }
            } elseif (
                /**
                 * Foreign keys?
                 */
                'int' === $datatype &&
                false === $nullables[$column] &&
                array_key_exists($column, $fks)
            ) {
                $snippets[] = <<<EEE
->setTests("$column", "$column", [
                RequiredControlTest::create(),
            ])
EEE;

                $uses[] = 'FormModel\Validation\ControlTest\WithFields\RequiredControlTest';
            }
        }

    }

}
