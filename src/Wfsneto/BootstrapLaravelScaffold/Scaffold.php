<?php namespace Wfsneto\BootstrapLaravelScaffold;

use Illuminate\Console\Command;
use Faker\Factory;
use Illuminate\Filesystem\FileNotFoundException;

class Scaffold
{
    /**
     * @var array
     */
    private $laravelClasses = array();

    /**
     * @var Model
     */
    private $model;

    /**
     * @var Migration
     */
    private $migration;

    /**
     * @var bool
     */
    private $isResource;

    /**
     * @var string
     */
    private $controllerType;

    /**
     * @var bool
     */
    private $fromFile;

    /**
     * @var FileCreator
     */
    private $fileCreator;

    /**
     * @var AssetDownloader
     */
    private $assetDownloader;

    /**
     * @var array
     */
    protected $configSettings;

    /**
     * @var \Illuminate\Console\Command
     */
    protected $command;

    /**
     * @var string
     */
    private $templatePathWithControllerType;

    /**
     * @var bool
     */
    private $columnAdded = false;

    /**
     * @var bool
     */
    private $onlyMigration = false;

    /**
     * @var bool
     */
    private $namespaceGlobal;

    /**
     * @var string
     */
    private $namespace;

    /**
     * @var array
     */
    private $lastTimeStamp = array();

    /**
     *  Stores the current collection of models
     *
     * @var array
     */
    private $models = array();

    public function __construct(Command $command)
    {
        $this->configSettings = $this->getConfigSettings();
        $this->command = $command;
        $this->fileCreator = new FileCreator($command);
        $this->assetDownloader = new AssetDownloader($command, $this->configSettings, $this->fileCreator);
    }

    /**
     * Load user's config settings
     *
     * @return array
     */
    private function getConfigSettings()
    {
        $package = "bootstrap-laravel-scaffold";

        $configSettings = array();

        $configSettings['pathTo'] = \Config::get("$package::paths");

        foreach($configSettings['pathTo'] as $pathName => $path)
        {
            if($path[strlen($path)-1] != "/")
            {
                if($pathName != "layout")
                    $path .= "/";

                $configSettings['pathTo'][$pathName] = $path;
            }
        }

        $configSettings['names'] = \Config::get("$package::names");

        $configSettings['appName'] = \Config::get("$package::appName");

        $configSettings['downloads'] = \Config::get("$package::downloads");

        $configSettings['views'] = \Config::get("$package::views");

        $configSettings['useRepository'] = \Config::get("$package::repository");

        $configSettings['useBaseRepository'] = \Config::get("$package::baseRepository");

        $configSettings['modelDefinitionsFile'] = \Config::get("$package::modelDefinitionsFile");

        return $configSettings;
    }

    /**
     *  Prompt for and save models from the command line
     */
    public function createModels()
    {
        $this->fromFile = false;
        $this->fileCreator->fromFile = false;
        $this->assetDownloader->fromFile = false;

        $this->setupLayoutFiles();

        $modelAndProperties = $this->askForModelAndFields();

        $moreTables = trim($modelAndProperties) == "q" ? false : true;

        while( $moreTables )
        {
            $this->saveModelAndProperties($modelAndProperties, array());

            $this->isResource = $this->command->confirm('Do you want resource (y) or restful (n) controllers? ');

            $this->createFiles();

            $this->command->info("Model ".$this->model->upper(). " and all associated files created successfully!");

            $this->addToModelDefinitions($modelAndProperties);

            $modelAndProperties = $this->command->ask('Add model with fields or "q" to quit: ');

            $moreTables = trim($modelAndProperties) == "q" ? false : true;
        }
    }

    /**
     *  Generate the layout and download js/css files
     */
    public function createLayout()
    {
        $this->assetDownloader->generateLayoutFiles();
    }

    /**
     *  Generate models from a file
     *
     * @param $fileName
     */
    public function createModelsFromFile($fileName)
    {
        $this->fileCreator->fromFile = true;
        $this->fromFile = true;
        $this->assetDownloader->fromFile = true;

        $this->setupLayoutFiles();

        $this->createLayout();

        $inputFile = file($fileName);

        $this->addAllModelsFromFile($inputFile);
    }

    /**
     *
     */
    public function setupLayoutFiles()
    {
        $this->laravelClasses = $this->getLaravelClassNames();

        $this->copyTemplateFiles();
    }

    /**
     *  Update any changes made to model definitions file
     */
    public function update()
    {
        $this->fileCreator->fromFile = true;
        $this->fromFile = true;
        $this->assetDownloader->fromFile = true;

        $this->setupLayoutFiles();

        $inputFile = file($this->configSettings['modelDefinitionsFile']);

        $this->addAllModelsFromFile($inputFile);
    }

    /**
     *  Add and save all models from specified file
     *
     * @param $inputFile
     */
    public function addAllModelsFromFile($inputFile)
    {
        $oldModelFile = array();

        // Get the cached model definitions file to compare against
        if(\File::exists($this->getModelCacheFile()))
        {
            $cachedFile = file($this->getModelCacheFile());
            $oldModelFile = $this->getCachedModels($cachedFile, false);
        }

        // Loop through the file and create all associated files
        foreach( $inputFile as $line_num => $modelAndProperties )
        {
            $modelAndProperties = trim($modelAndProperties);
            if(!empty($modelAndProperties))
            {
                if(preg_match("/^resource =/", $modelAndProperties))
                {
                    $this->isResource = trim(substr($modelAndProperties, strpos($modelAndProperties, "=")+1));
                    continue;
                }

                if(preg_match("/^namespace =/", $modelAndProperties))
                {
                    $this->namespaceGlobal = true;
                    $this->namespace = trim(substr($modelAndProperties, strpos($modelAndProperties, "=")+1));
                    $this->fileCreator->namespace = $this->namespace;
                    continue;
                }

                $this->saveModelAndProperties($modelAndProperties, $oldModelFile);

                $this->createFiles();
            }
        }

        // If any models existed in the cached file,
        // and not in the current file, drop that table
        foreach ($oldModelFile as $tableName => $modelData)
        {
            if(!array_key_exists($tableName, $this->models))
            {
                $migration = new Migration($this->configSettings['pathTo']['migrations'], $modelData['model'], $this->fileCreator);
                $migration->dropTable($this->lastTimeStamp);
            }
        }

        copy($this->configSettings['modelDefinitionsFile'], $this->getModelCacheFile());
    }

    /**
     *  Get all of the cached models from the specified file
     *
     * @param $inputFile
     * @param bool $createFiles
     * @return array
     */
    public function getCachedModels($inputFile, $createFiles = true)
    {
        $oldModelFile = array();

        foreach( $inputFile as $line_num => $modelAndProperties )
        {
            $modelAndProperties = trim($modelAndProperties);
            if(!empty($modelAndProperties))
            {
                if(preg_match("/^resource =/", $modelAndProperties))
                {
                    $this->isResource = trim(substr($modelAndProperties, strpos($modelAndProperties, "=")+1));
                    continue;
                }

                if(preg_match("/^namespace =/", $modelAndProperties))
                {
                    $this->namespaceGlobal = true;
                    $this->namespace = trim(substr($modelAndProperties, strpos($modelAndProperties, "=")+1));
                    $this->fileCreator->namespace = $this->namespace;
                    continue;
                }

                $this->saveModelAndProperties($modelAndProperties, array(), false);

                $oldModelFile[$this->model->getTableName()] = array();

                $oldModelFile[$this->model->getTableName()]['relationships'] = $this->model->getRelationships();
                $oldModelFile[$this->model->getTableName()]['properties'] = $this->model->getProperties();
                $oldModelFile[$this->model->getTableName()]['model'] = $this->model;


                if($createFiles)
                    $this->createFiles();
            }
        }

        return $oldModelFile;
    }

    /**
     *  Get the laravel class names to check for collisions
     *
     * @return array
     */
    private function getLaravelClassNames()
    {
        $classNames = array();

        $aliases = \Config::get('app.aliases');

        foreach ($aliases as $alias => $facade)
        {
            array_push($classNames, strtolower($alias));
        }

        return $classNames;
    }

    /**
     *  Save the model and its properties
     *
     * @param $modelAndProperties
     * @param $oldModelFile
     * @param bool $storeInArray
     */
    private function saveModelAndProperties($modelAndProperties, $oldModelFile, $storeInArray = true)
    {
        do {
            if(!$this->namespaceGlobal)
                $this->namespace = "";

            $this->model = new Model($this->command, $oldModelFile, $this->namespace);

            $this->model->generateModel($modelAndProperties);

            if($storeInArray)
                $this->models[$this->model->getTableName()] = $this->model;

            if(!$this->namespaceGlobal)
            {
                $this->fileCreator->namespace = $this->model->getNamespace();
                $this->namespace = $this->model->getNamespace();
            }

            $modelNameCollision = in_array($this->model->lower(), $this->laravelClasses);

        } while($modelNameCollision);

        $propertiesGenerated = $this->model->generateProperties();

        if(!$propertiesGenerated)
        {
            if($this->fromFile)
                exit;
            else
                $this->createModels();
        }
    }

    /**
     *  Add the current model to the model definitions file
     *
     * @param $modelAndProperties
     */
    private function addToModelDefinitions($modelAndProperties)
    {
        \File::append($this->getModelCacheFile(), "\n" . $modelAndProperties);
    }

    /**
     *  Gets the model cache file as it relates to the model definitions file
     *
     * @return string
     */
    private function getModelCacheFile()
    {
        $file = $this->configSettings['modelDefinitionsFile'];
        $modelFilename = substr(strrchr($file, "/"), 1);
        $ext = substr($modelFilename, strrpos($modelFilename, "."), strlen($modelFilename)-strrpos($modelFilename, "."));
        $name = substr($modelFilename, 0, strrpos($modelFilename, "."));
        $modelDefinitionsFile = substr($file, 0, strrpos($file, "/")+1) . "." . $name ."-cache". $ext;
        return $modelDefinitionsFile;
    }

    /**
     *  Creates all of the files
     */
    private function createFiles()
    {
        $this->createModel();

        $this->migration = new Migration($this->configSettings['pathTo']['migrations'], $this->model, $this->fileCreator);

        $tableCreated = $this->migration->createMigrations($this->lastTimeStamp);

        $this->runMigrations();

        if(!$this->onlyMigration && $tableCreated)
        {
            $this->controllerType = $this->getControllerType();

            $this->templatePathWithControllerType = $this->configSettings['pathTo']['templates'] . $this->controllerType ."/";

            if(!$this->model->exists)
            {
                if($this->configSettings['useRepository'])
                {
                    $this->createRepository();
                    $this->createRepositoryInterface();
                    $this->putRepositoryFolderInStartFiles();
                }

                $this->createController();

                $this->createViews();

                $this->updateRoutes();

                $this->createTests();

                $this->createSeeds();
            }
        }
    }

    /**
     * Creates the model file
     */
    private function createModel()
    {
        $fileName = $this->configSettings['pathTo']['models'] . $this->nameOf("modelName") . ".php";

        if(\File::exists($fileName))
        {
            $this->updateModel($fileName);
            $this->model->exists = true;
            return;
        }

        if($this->model->hasSoftdeletes()) {
            $fileContents = "use Illuminate\Database\Eloquent\SoftDeletingTrait;\n    ";
        } else {
            $fileContents = '';
        }

        $fileContents .= "protected \$table = '". $this->model->getTableName() ."';\n";

        if(!$this->model->hasTimestamps())
            $fileContents .= "    public \$timestamps = false;\n";

        $properties = "";
        foreach ($this->model->getProperties() as $property => $type) {
            $properties .= "'$property',";
        }

        $properties = rtrim($properties, ",");

        $fileContents .= "    protected \$fillable = array(".$properties.");\n";

        $fileContents = $this->addRelationships($fileContents);

        $template = $this->configSettings['useRepository'] ? "model.txt" : "model-no-repo.txt";

        $this->makeFileFromTemplate($fileName, $this->configSettings['pathTo']['templates'].$template, $fileContents);

        $this->addModelLinksToLayoutFile();
    }

    /**
     *  Updates an existing model file
     *
     * @param $fileName
     */
    private function updateModel($fileName)
    {
        $fileContents = \File::get($fileName);

        $fileContents = trim($this->addRelationships($fileContents, false));

        $fileContents = trim($this->removeRelationships($fileContents)) . "\n}\n";

        \File::put($fileName, $fileContents);
    }

    /**
     *  Adds model links to the layout file
     */
    private function addModelLinksToLayoutFile()
    {
        $layoutFile = $this->configSettings['pathTo']['layout'];
        if(\File::exists($layoutFile))
        {
            $layout = \File::get($layoutFile);

            $layout = str_replace("<!--[linkToModels]-->", "<a href=\"{{ url('".$this->nameOf("viewFolder")."') }}\" class=\"list-group-item\">".$this->model->upper()."</a>\n<!--[linkToModels]-->", $layout);

            \File::put($layoutFile, $layout);
        }
    }

    /**
     *  Add relationships to the model
     *
     * @param $fileContents
     * @param $newModel
     * @return string
     */
    private function addRelationships($fileContents, $newModel = true)
    {
        if(!$newModel)
            $fileContents = substr($fileContents, 0, strrpos($fileContents, "}"));

        foreach ($this->model->getRelationships() as $relation)
        {
            $relatedModel = $relation->model;

            if(strpos($fileContents, $relation->getName()) !== false && !$newModel)
                continue;

            $functionContent = "        return \$this->" . $relation->getType() . "('" . $relatedModel->nameWithNamespace() . "');\n";
            $fileContents .= $this->fileCreator->createFunction($relation->getName(), $functionContent);

            $relatedModelFile = $this->configSettings['pathTo']['models'] . $relatedModel->upper() . '.php';

            if (!\File::exists($relatedModelFile))
            {
                if ($this->fromFile)
                    continue;
                else
                {
                    $editRelatedModel = $this->command->confirm("Model " . $relatedModel->upper() . " doesn't exist yet. Would you like to create it now [y/n]? ", true);

                    if ($editRelatedModel)
                        $this->fileCreator->createClass($relatedModelFile, "", array('name' => "\\Eloquent"));
                    else
                        continue;
                }
            }

            $content = \File::get($relatedModelFile);
            if (preg_match("/function " . $this->model->lower() . "/", $content) !== 1 && preg_match("/function " . $this->model->plural() . "/", $content) !== 1)
            {
                $index = 0;
                $reverseRelations = $relation->reverseRelations();

                if (count($reverseRelations) > 1)
                    $index = $this->command->ask($relatedModel->upper() . " (0=" . $reverseRelations[0] . " OR 1=" . $reverseRelations[1] . ") " . $this->model->upper() . "? ");

                $reverseRelationType = $reverseRelations[$index];
                $reverseRelationName = $relation->getReverseName($this->model, $reverseRelationType);

                $content = substr($content, 0, strrpos($content, "}"));
                $functionContent = "        return \$this->" . $reverseRelationType . "('" . $this->model->nameWithNamespace() . "');\n";
                $content .= $this->fileCreator->createFunction($reverseRelationName, $functionContent) . "}\n";

                \File::put($relatedModelFile, $content);
            }
        }
        return $fileContents;
    }

    /**
     *  Remove relationships from the model
     *
     * @param $fileContents
     * @return string
     */
    private function removeRelationships($fileContents)
    {
        foreach ($this->model->getRelationshipsToRemove() as $relation)
        {
            $name = $relation->getName();

            if(strpos($fileContents, $name) !== false)
            {
                $fileContents = preg_replace("/public\s+function\s+$name\s*\(.*?\).*?\{.*?\}/s", "", $fileContents);
            }
        }
        return $fileContents;
    }

    /**
     *  Get controller type, either resource or restful
     *
     * @return string
     */
    private function getControllerType()
    {
        return $this->isResource ? "resource" : "restful";
    }

    /**
     *  Gets the name from the configuration file
     *
     * @param string $type
     * @return string
     */
    private function nameOf($type)
    {
        return $this->replaceModels($this->configSettings['names'][$type]);
    }

    /**
     *  Prompt user for model and properties and return result
     *
     * @return string
     */
    private function askForModelAndFields()
    {
        $modelAndFields = $this->command->ask('Add model with its relations and fields or type "q" to quit (type info for examples) ');

        if($modelAndFields == "info")
        {
            $this->showInformation();

            $modelAndFields = $this->command->ask('Now your turn: ');
        }

        return $modelAndFields;
    }

    /**
     *  Copy template files from package folder to specified user folder
     */
    private function copyTemplateFiles()
    {
        if(!\File::isDirectory($this->configSettings['pathTo']['templates']))
            $this->fileCreator->copyDirectory("vendor/wfsneto/bootstrap-laravel-scaffold/src/Wfsneto/BootstrapLaravelScaffold/templates/", $this->configSettings['pathTo']['templates']);
    }

    /**
     *  Show the examples of the syntax to be used to add models
     */
    private function showInformation()
    {
        $this->command->info('MyNamespace\Book title:string year:integer');
        $this->command->info('With relation: Book belongsTo Author title:string published:integer');
        $this->command->info('Multiple relations: University hasMany Course, Department name:string city:string state:string homepage:string )');
        $this->command->info('Or group like properties: University hasMany Department string( name city state homepage )');
    }

    /**
     *  Prompt user to run the migrations
     */
    private function runMigrations()
    {
        if(!$this->fromFile)
        {
            $editMigrations = $this->command->confirm('Would you like to edit your migrations file before running it [y/n]? ', true);

            if ($editMigrations)
            {
                $this->command->info('Remember to run "php artisan migrate" after editing your migration file');
                $this->command->info('And "php artisan db:seed" after editing your seed file');
            }
            else
            {
                while (true)
                {
                    try
                    {
                        $this->command->call('migrate');
                        $this->command->call('db:seed');
                        break;
                    }
                    catch (\Exception $e)
                    {
                        $this->command->info('Error: ' . $e->getMessage());
                        $this->command->error('This table already exists and/or you have duplicate migration files.');
                        $this->command->confirm('Fix the error and enter "yes" ', true);
                    }
                }
            }
        }
    }

    /**
     *  Generate the seeds file
     */
    private function createSeeds()
    {
        $faker = Factory::create();

        $databaseSeeder = $this->configSettings['pathTo']['seeds'] . 'DatabaseSeeder.php';
        $databaseSeederContents = \File::get($databaseSeeder);
        if(preg_match("/faker/", $databaseSeederContents) !== 1)
        {
            $contentBefore = substr($databaseSeederContents, 0, strpos($databaseSeederContents, "{"));
            $contentAfter = substr($databaseSeederContents, strpos($databaseSeederContents, "{")+1);

            $databaseSeederContents = $contentBefore;
            $databaseSeederContents .= "{\n    protected \$faker;\n\n";
            $functionContents = "        if(empty(\$this->faker)) {\n";
            $functionContents .= "            \$this->faker = Faker\\Factory::create();\n        }\n\n";
            $functionContents .= "        return \$this->faker;\n";

            $databaseSeederContents .= $this->fileCreator->createFunction("getFaker", $functionContents);

            $databaseSeederContents .= $contentAfter;

            \File::put($databaseSeeder, $databaseSeederContents);
        }

        $functionContent = "        \$faker = \$this->getFaker();\n\n";
        $functionContent .= "        for(\$i = 1; \$i <= 10; \$i++) {\n";

        $functionContent .= "            \$".$this->model->lower()." = array(\n";

        foreach($this->model->getProperties() as $property => $type)
        {

            if($property == "password")
                $functionContent .= "                '$property' => \\Hash::make('password'),\n";
            else
            {
                $fakerProperty = "";
                try
                {
                    $fakerProperty2 = $faker->getFormatter($property);
                    $fakerProperty = $property;
                }
                catch (\InvalidArgumentException $e) { }

                if(empty($fakerProperty))
                {
                    try
                    {
                        $fakerProperty2 = $faker->getFormatter($type);
                        $fakerProperty = $type;
                    }
                    catch (\InvalidArgumentException $e) { }
                }

                if(empty($fakerProperty))
                {
                    $fakerType = "";
                    switch($type)
                    {
                        case "integer":
                        case "biginteger":
                        case "smallinteger":
                        case "tinyinteger":
                            $fakerType = "randomDigitNotNull";
                            break;
                        case "string":
                            $fakerType = "word";
                            break;
                        case "float":
                        case "double":
                            $fakerType = "randomFloat";
                            break;
                        case "mediumtext":
                        case "longtext":
                        case "binary":
                            $fakerType = "text";
                            break;
                    }

                    $fakerType = $fakerType ? "\$faker->".$fakerType : "0";
                }
                else
                    $fakerType = "\$faker->".$fakerProperty;

                $functionContent .= "                '$property' => $fakerType,\n";

            }
        }

        foreach($this->migration->getForeignKeys() as $key)
            $functionContent .= "                '$key' => \$i,\n";

        $functionContent .= "            );\n";

        $namespace = $this->namespace ? "\\" . $this->namespace . "\\" : "";

        $functionContent .= "            ". $namespace . $this->model->upper()."::create(\$".$this->model->lower().");\n";
        $functionContent .= "        }\n";

        $fileContents = $this->fileCreator->createFunction("run", $functionContent);

        $fileName = $this->configSettings['pathTo']['seeds'] . $this->model->upperPlural() . "TableSeeder.php";

        $this->fileCreator->createClass($fileName, $fileContents, array('name' => 'DatabaseSeeder'), array(), array(), "class", false, false);

        $tableSeederClassName = $this->model->upperPlural() . 'TableSeeder';

        $content = \File::get($databaseSeeder);

        if(preg_match("/$tableSeederClassName/", $content) !== 1)
        {
            $content = preg_replace("/(run\(\).+?)}/us", "$1    \$this->call('{$tableSeederClassName}');\n    }", $content);
            \File::put($databaseSeeder, $content);
        }
    }

    /**
     *  Create the repository interface
     *
     * @return array
     */
    private function createRepositoryInterface()
    {
        $this->fileCreator->createDirectory($this->configSettings['pathTo']['repositoryInterfaces']);

        $baseRepository = $this->configSettings['pathTo']['repositoryInterfaces'] . $this->nameOf("baseRepositoryInterface") . ".php";

        $useBaseRepository = $this->configSettings['useBaseRepository'];

        $repoTemplate = $this->configSettings['pathTo']['templates']."repository-interface";

        if($useBaseRepository)
        {
            if(!file_exists($baseRepository))
                $this->makeFileFromTemplate($baseRepository, $this->configSettings['pathTo']['templates']."base-repository-interface.txt");
            $repoTemplate .= "-with-base";
        }

        $repoTemplate .= ".txt";

        $fileName = $this->configSettings['pathTo']['repositoryInterfaces'] . $this->nameOf("repositoryInterface") . ".php";

        $this->makeFileFromTemplate($fileName, $repoTemplate);
    }

    /**
     *  Create the repository
     *
     * @return array
     */
    private function createRepository()
    {
        $this->fileCreator->createDirectory($this->configSettings['pathTo']['repositories']);

        $fileName = $this->configSettings['pathTo']['repositories'] . $this->nameOf("repository") . '.php';

        $this->makeFileFromTemplate($fileName, $this->configSettings['pathTo']['templates']."eloquent-repository.txt");
    }

    /**
     *  Add repository folder so that it autoloads
     *
     * @return mixed
     */
    private function putRepositoryFolderInStartFiles()
    {
        $repositories = substr($this->configSettings['pathTo']['repositories'], 0, strlen($this->configSettings['pathTo']['repositories'])-1);

        $startRepo = $repositories;

        if(strpos($repositories, "app") !== false)
            $startRepo = "app_path().'".substr($repositories, strpos($repositories, "/"), strlen($repositories) - strpos($repositories, "/"))."'";

        $content = \File::get('app/start/global.php');

        if (preg_match("/repositories/", $content) !== 1)
            $content = preg_replace("/app_path\(\).'\/controllers',/", "app_path().'/controllers',\n    $startRepo,", $content);

        \File::put('app/start/global.php', $content);

        $content = \File::get('composer.json');

        if (preg_match("/repositories/", $content) !== 1)
            $content = preg_replace("/\"app\/controllers\",/", "\"app/controllers\",\n            \"$repositories\",", $content);

        \File::put('composer.json', $content);
    }

    /**
     *  Create controller
     *
     * @return array
     */
    private function createController()
    {
        $fileName = $this->configSettings['pathTo']['controllers'] . $this->nameOf("controller"). ".php";

        $this->makeFileFromTemplate($fileName, $this->templatePathWithControllerType."controller.txt");
    }

    /**
     *  Create tests
     *
     * @return array
     */
    private function createTests()
    {
        $this->fileCreator->createDirectory($this->configSettings['pathTo']['tests']. 'controller');

        $fileName = $this->configSettings['pathTo']['tests']."controller/" . $this->nameOf("test") .".php";

        $this->makeFileFromTemplate($fileName, $this->templatePathWithControllerType."test.txt");
    }

    /**
     *  Update routes file with new controller
     *
     * @return string
     */
    private function updateRoutes()
    {
        $routeFile = $this->configSettings['pathTo']['routes']."routes.php";

        $namespace = $this->namespace ? $this->namespace . "\\" : "";

        $fileContents = "";

        if($this->configSettings['useRepository'])
            $fileContents = "\nApp::bind('" . $namespace . $this->nameOf("repositoryInterface")."','" . $namespace . $this->nameOf("repository") ."');\n";

        $routeType = $this->isResource ? "resource" : "controller";

        $fileContents .= "Route::" . $routeType . "('" . $this->nameOf("viewFolder") . "', '" . $namespace. $this->nameOf("controller") ."');\n";

        $content = \File::get($routeFile);
        if (preg_match("/" . $this->model->lower() . "/", $content) !== 1)
            \File::append($routeFile, $fileContents);
    }

    /**
     *  Create views as specified in the configuration file
     */
    private function createViews()
    {
        $dir = $this->configSettings['pathTo']['views'] . $this->nameOf('viewFolder') . "/";
        if (!\File::isDirectory($dir))
            \File::makeDirectory($dir);

        $pathToViews = $this->configSettings['pathTo']['templates'].$this->controllerType."/";

        foreach($this->configSettings['views'] as $view)
        {
            $fileName = $dir . "$view.blade.php";

            try
            {
                $this->makeFileFromTemplate($fileName, $pathToViews."$view.txt");
            }
            catch(FileNotFoundException $e)
            {
                $this->command->error("Template file ".$pathToViews . $view.".txt does not exist! You need to create it to generate that file!");
            }
        }
    }

    /**
     *  Generate a file based off of a template
     *
     * @param $fileName
     * @param $template
     * @param string $content
     */
    public function makeFileFromTemplate($fileName, $template, $content = "")
    {
        try
        {
            $fileContents = \File::get($template);
        }
        catch(FileNotFoundException $e)
        {
            $shortTemplate = substr($template, strpos($template, $this->configSettings["pathTo"]["templates"]) + strlen($this->configSettings["pathTo"]["templates"]),strlen($template)-strlen($this->configSettings["pathTo"]["templates"]));
            $this->fileCreator->copyFile("vendor/wfsneto/bootstrap-laravel-scaffold/src/Wfsneto/BootstrapLaravelScaffold/templates/".$shortTemplate, $template);
            $fileContents = \File::get($template);
        }

        $fileContents = $this->replaceNames($fileContents);
        $fileContents = $this->replaceModels($fileContents);
        $fileContents = $this->replaceProperties($fileContents);

        if($content)
            $fileContents = str_replace("[content]", $content, $fileContents);

        $namespace = $this->namespace ? "namespace ".$this->namespace. ";" : "";
        $fileContents = str_replace("[namespace]", $namespace, $fileContents);

        if(!$this->configSettings['useRepository'])
            $fileContents = str_replace($this->nameOf("repositoryInterface"), $this->nameOf("modelName"), $fileContents);

        $this->fileCreator->createFile($fileName, $fileContents);
    }

    /**
     *  Replace [model] tags in template with the model name
     *
     * @param $fileContents
     * @return mixed
     */
    private function replaceModels($fileContents)
    {
        $modelReplaces = array('[model]'=>$this->model->lower(), '[Model]'=>$this->model->upper(), '[models]'=>$this->model->plural(), '[Models]'=>$this->model->upperPlural());

        foreach($modelReplaces as $model => $name)
            $fileContents = str_replace($model, $name, $fileContents);

        return $fileContents;
    }

    /**
     *  Replace 'names' from the config file with their names
     *
     * @param $fileContents
     * @return mixed
     */
    public function replaceNames($fileContents)
    {
        foreach($this->configSettings['names'] as $name => $text)
            $fileContents = str_replace("[$name]", $text, $fileContents);

        return $fileContents;
    }

    /**
     *  Replace [property] with model's properties
     *
     * @param $fileContents
     * @return mixed
     */
    private function replaceProperties($fileContents)
    {
        $lastPos = 0;
        $needle = "[repeat]";
        $endRepeat = "[/repeat]";

        while (($lastPos = strpos($fileContents, $needle, $lastPos))!== false)
        {
            $beginning = $lastPos;
            $lastPos = $lastPos + strlen($needle);
            $endProp = strpos($fileContents, $endRepeat, $lastPos);
            $end = $endProp + strlen($endRepeat);
            $replaceThis = substr($fileContents, $beginning, $end-$beginning);
            $propertyTemplate = substr($fileContents, $lastPos, $endProp - $lastPos);
            $properties = "";

            foreach($this->model->getProperties() as $property => $type)
            {
                $temp = str_replace("[property]", $property, $propertyTemplate);
                $temp = str_replace("[Property]", ucfirst($property), $temp);
                $properties .= $temp;
            }

            $properties = trim($properties, ",");
            $fileContents = str_replace($replaceThis, $properties, $fileContents);
        }

        return $fileContents;
    }
}
