<?php
namespace FloCMS\CLI;

use FloCMS\CLI\Colors;

class Core{
    private $TextData;
    private $HelpData;
    private $AllCommands;
    private $Colors;
    protected $Command;
    protected $Data;

    /** @var array<string,string> */
    protected $Paths = [];

    public function __construct($command, $Data)
    {
        $this->Command = $command;
        $this->Data = $Data;

        $jsonData = $this->Data;
        $this->TextData = $jsonData['cli-data']['dummy-texts'];
        $this->HelpData = $jsonData['cli-data']['help-strings'];
        $this->AllCommands = $jsonData['cli-data']['all-commands'];

        $this->Colors = new Colors();

        // Centralized paths (relative to the directory where the CLI is executed).
        // Next step can be "project root detection" instead of getcwd().
        $root = getcwd();
        $this->Paths = [
            'root' => $root,
            'controllers' => $root . '/controllers',
            'models' => $root . '/models',
            'views' => $root . '/views',
        ];
    }

    // -------------------------
    // PATH + NAME HELPERS
    // -------------------------

    protected function controllerFilePath(string $name): string
    {
        $base = ucfirst($name);
        return $this->Paths['controllers'] . '/' . $base . 'Controller.php';
    }

    protected function modelFilePath(string $name): string
    {
        $base = ucfirst($name);
        return $this->Paths['models'] . '/' . $base . 'Model.php';
    }

    protected function viewFilePath(string $controller, string $view): string
    {
        // views/<Controller>/<view>.html
        return $this->Paths['views'] . '/' . $controller . '/' . $view . '.html';
    }

    protected function controllerClassName(string $name): string
    {
        return ucfirst($name) . 'Controller';
    }

    protected function modelClassName(string $name): string
    {
        return ucfirst($name) . 'Model';
    }

    // -------------------------
    // MODEL ↔ CONTROLLER WIRING
    // -------------------------

    /**
     * Ensures the controller imports and initializes its model.
     * - Adds: use FloCMS\Models\XModel;
     * - Adds in constructor: $this->model = new XModel();
     * Safe to call multiple times (won't duplicate lines).
     *
     * IMPORTANT: If the model file doesn't exist, it does nothing.
     */
    protected function LinkControllerToModel(string $name): void
    {
        $controllerFile = $this->controllerFilePath($name);
        $modelFile = $this->modelFilePath($name);

        if (!is_file($controllerFile)) {
            throw new \Exception("Controller not found: {$controllerFile}");
        }
        if (!is_file($modelFile)) {
            // As requested: do NOT add model wiring if model doesn't exist.
            return;
        }

        $content = file_get_contents($controllerFile);
        $modelClass = $this->modelClassName($name);
        $useLine = "use FloCMS\\Models\\{$modelClass};";

        // 1) Add use statement if missing
        if (strpos($content, $useLine) === false) {
            // Insert after the last existing "use" statement, or after namespace.
            if (preg_match_all('/^use\s+[^;]+;\s*$/m', $content, $m, PREG_OFFSET_CAPTURE) && !empty($m[0])) {
                $last = end($m[0]);
                $insertPos = $last[1] + strlen($last[0]);
                $content = substr($content, 0, $insertPos) . "\n" . $useLine . "\n" . substr($content, $insertPos);
            } elseif (preg_match('/^namespace\s+[^;]+;\s*$/m', $content, $m2, PREG_OFFSET_CAPTURE)) {
                $ns = $m2[0];
                $insertPos = $ns[1] + strlen($ns[0]);
                $content = substr($content, 0, $insertPos) . "\n\n" . $useLine . "\n" . substr($content, $insertPos);
            } else {
                // Fallback: prepend after <?php
                $content = preg_replace('/^<\?php\s*/', "<?php\n{$useLine}\n", $content, 1);
            }
        }

        // 2) Add constructor wiring if missing
        $initLine = "\t\t\$this->model = new {$modelClass}();";
        if (strpos($content, "\$this->model = new {$modelClass}()") === false) {
            // Try to insert right after parent::__construct(...);
            $pattern = '/(parent::__construct\([^;]*\);\s*)/';
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "$1\n{$initLine}\n", $content, 1);
            } else {
                // Fallback: put it just before the end of __construct
                $patternCtorEnd = '/(function\s+__construct\s*\([^)]*\)\s*\{)(.*?)(\n\s*\})/s';
                if (preg_match($patternCtorEnd, $content)) {
                    $content = preg_replace($patternCtorEnd, "$1$2\n{$initLine}$3", $content, 1);
                }
            }
        }

        file_put_contents($controllerFile, $content);
    }

    /**
     * Removes:
     * - use FloCMS\Models\XModel;
     * - $this->model = new XModel();
     * from controller, if present.
     * Safe to call multiple times.
     */
    protected function UnlinkControllerFromModel(string $name): void
    {
        $controllerFile = $this->controllerFilePath($name);
        if (!is_file($controllerFile)) return;

        $content = file_get_contents($controllerFile);
        $modelClass = $this->modelClassName($name);

        // Remove use line
        $useLinePattern = '/^\s*use\s+FloCMS\\\\Models\\\\' . preg_quote($modelClass, '/') . '\s*;\s*$/m';
        $content = preg_replace($useLinePattern, '', $content);

        // Remove init line
        $initLinePattern = '/^\s*\$this->model\s*=\s*new\s+' . preg_quote($modelClass, '/') . '\s*\(\s*\)\s*;\s*$/m';
        $content = preg_replace($initLinePattern, '', $content);

        // Cleanup blank lines
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        file_put_contents($controllerFile, $content);
    }

    // -------------------------
    // CREATE HELPERS
    // -------------------------

    protected function buildControllerContent(string $name, bool $withModelWiring): string
    {
        $base = ucfirst($name);
        $text = sprintf($this->TextData['controller-text'], $base, $base);

        if (!$withModelWiring) {
            // Remove model initialization line if model shouldn't be wired.
            $modelClass = $this->modelClassName($name);
            $text = preg_replace(
                '/^\s*\$this->model\s*=\s*new\s+' . preg_quote($modelClass, '/') . '\s*\(\s*\)\s*;\s*$/m',
                '',
                $text
            );
            $text = preg_replace("/\n{3,}/", "\n\n", $text);
        }

        return $text;
    }

    protected function CreateControllerFor(string $name, bool $forceModelWiring = false): string
    {
        $hasModelFile = is_file($this->modelFilePath($name));
        $withModel = $forceModelWiring || $hasModelFile;

        $text = $this->buildControllerContent($name, $withModel);
        $path = $this->controllerFilePath($name);

        if ($this->CreateFile($path, $text)) {
            if ($withModel) {
                $this->LinkControllerToModel($name);
            }
            return $this->Colors->getColoredString('Info:', 'white','blue')."Controller has been Created Successfully.";
        }

        // If controller exists, still ensure wiring if model exists/forced.
        if ($withModel) {
            $this->LinkControllerToModel($name);
        }

        return $this->Colors->getColoredString('Warning:', 'white','red')."Controller already Exist.";
    }

    protected function CreateModelFor(string $name): string
    {
        $text = sprintf($this->TextData['model-text'], ucfirst($name));
        $path = $this->modelFilePath($name);

        if ($this->CreateFile($path, $text)) {
            // If a controller already exists, wire it to this new model.
            try { $this->LinkControllerToModel($name); } catch (\Exception $e) {}
            return $this->Colors->getColoredString('Info:', 'white','blue')."Model has been Created Successfully.";
        }

        // If model exists and controller exists, ensure wiring is present.
        try { $this->LinkControllerToModel($name); } catch (\Exception $e) {}

        return $this->Colors->getColoredString('Warning:', 'white','red')."Model already Exist.";
    }

    protected function CreateViewFor(string $controller, string $view): string
    {
        $path = $this->viewFilePath($controller, $view);
        $text = sprintf($this->TextData['view-text'], ucfirst($view), ucfirst($controller));

        if (!$this->CreateFile($path, $text)) {
            return $this->Colors->getColoredString('Warning:', 'white','red')."View file already Exist.";
        }

        // Append controller method (best-effort).
        $controllerFile = $this->controllerFilePath($controller);
        $methodText = sprintf($this->TextData['method-text'], ucfirst($view));

        try {
            $this->AppendClassMethod($controllerFile, $methodText);
        } catch (\Exception $e) {
            return $this->Colors->getColoredString('Warning:', 'white','red')
                . "View created, but failed to update controller (" . $e->getMessage() . ")";
        }

        return $this->Colors->getColoredString('Info:', 'white','blue')."View file has been Created Successfully.";
    }

    // -------------------------
    // CREATE COMMANDS (PUBLIC)
    // -------------------------

    public function CreateController(){
        if(!isset($this->Command[2])){
            return $this->Colors->getColoredString('Warning:', 'white','red') . "Controller name is required, please enter valid controller name with the command.";
        }
        return $this->CreateControllerFor($this->Command[2]);
    }

    public function CreateModel(){
        if(!isset($this->Command[2])){
            return $this->Colors->getColoredString('Warning:', 'white','red') . "Model name is required, please enter valid Model name with the command.";
        }
        return $this->CreateModelFor($this->Command[2]);
    }

    public function CreateView($fileName = null){
        if(!isset($this->Command[2])){
            return $this->Colors->getColoredString('Warning:', 'white','red') . "View name and route is required, please enter valid View name with the command.";
        }

        if($this->Command[2] == '--help'){
            $title = $this->Colors->getColoredString('How to Use Create View command:', 'green','black');
            return sprintf($this->HelpData['create-view'], $title);
        }

        $controler = $this->Command[2];

        if(!isset($this->Command[3])){
            if ($fileName == null)
                return $this->Colors->getColoredString('Warning:', 'white','red') . "View route is required, please enter valid route for the View.";
        }else{
           $fileName =  $this->Command[3];
        }

        return $this->CreateViewFor($controler, $fileName);
    }

    public function CreateRoute(){
        if(!isset($this->Command[2])){
            return $this->Colors->getColoredString('Warning:', 'white','red')
                . "Route name is required. Usage: php flo create:route <Name> [view]";
        }

        $name = $this->Command[2];
        $view = $this->Command[3] ?? 'index';

        $out = [];
        // For routes, always ensure model wiring is present.
        $out[] = $this->CreateControllerFor($name, true);
        $out[] = $this->CreateModelFor($name);
        $out[] = $this->CreateViewFor($name, $view);
        return implode("\n", $out);
    }

    // -------------------------
    // FILE HELPERS
    // -------------------------

    protected function CreateFile($file, $content){
        if (file_exists($file)) {
            return false;
        }
        // if file dir not exist create it
        $fileDir = dirname($file);
        if (!is_dir($fileDir)) mkdir($fileDir, 0755, true);

        file_put_contents($file, $content);
        return true;
    }

    protected function AppendClassMethod($filePath, $methodCode){
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: $filePath");
        }
        // Read file
        $content = file_get_contents($filePath);

        // Find last closing bracket of the class
        $pos = strrpos($content, "}");

        if ($pos === false) {
            throw new \Exception("Invalid class file, missing closing }");
        }

        // Insert method before the last "}"
        $newContent =
            substr($content, 0, $pos) .
            "\n    " . trim($methodCode) . "\n" .
            "}\n";

        // Write updated file
        file_put_contents($filePath, $newContent);

        return true;
    }

    protected function DeleteFileIfExists(string $path): bool
    {
        if (!file_exists($path)) return false;
        if (is_dir($path)) return false; // guard
        return @unlink($path);
    }

    protected function DeleteEmptyDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = array_diff(scandir($dir), ['.', '..']);
        if (count($items) === 0) {
            @rmdir($dir);
        }
    }

    /**
     * Removes a controller action method (generated ones).
     * Safe: if it doesn't match, it does nothing.
     */
    protected function RemoveControllerMethod(string $controller, string $methodName): void
    {
        $controllerFile = $this->controllerFilePath($controller);
        if (!is_file($controllerFile)) return;

        $content = file_get_contents($controllerFile);

        // Best-effort: remove "public function X(){ ... }" or "public function X() { ... }"
        $pattern = '/\n\s*public\s+function\s+' . preg_quote($methodName, '/') . '\s*\(\s*\)\s*\{.*?\n\s*\}\s*/s';

        $new = preg_replace($pattern, "\n", $content, 1);
        if ($new === null) return;

        $new = preg_replace("/\n{3,}/", "\n\n", $new);
        file_put_contents($controllerFile, $new);
    }

    // -------------------------
    // DELETE HELPERS
    // -------------------------

    protected function DeleteControllerFor(string $name): string
    {
        $path = $this->controllerFilePath($name);

        if ($this->DeleteFileIfExists($path)) {
            return $this->Colors->getColoredString('Info:', 'white','blue')
                . "Controller deleted: {$path}";
        }

        return $this->Colors->getColoredString('Warning:', 'white','red')
            . "Controller not found: {$path}";
    }

    protected function DeleteModelFor(string $name): string
    {
        $path = $this->modelFilePath($name);

        // Vice versa: if controller exists, remove wiring lines first
        $this->UnlinkControllerFromModel($name);

        if ($this->DeleteFileIfExists($path)) {
            return $this->Colors->getColoredString('Info:', 'white','blue')
                . "Model deleted: {$path}";
        }

        return $this->Colors->getColoredString('Warning:', 'white','red')
            . "Model not found: {$path}";
    }

    protected function DeleteViewFor(string $controller, string $view): string
    {
        $viewPath = $this->viewFilePath($controller, $view);

        // Vice versa: remove method from controller
        $this->RemoveControllerMethod($controller, ucfirst($view));

        if ($this->DeleteFileIfExists($viewPath)) {
            // optional: delete empty folder views/<Controller> if it becomes empty
            $this->DeleteEmptyDir(dirname($viewPath));

            return $this->Colors->getColoredString('Info:', 'white','blue')
                . "View deleted: {$viewPath}";
        }

        return $this->Colors->getColoredString('Warning:', 'white','red')
            . "View not found: {$viewPath}";
    }

    // -------------------------
    // DELETE COMMANDS (PUBLIC)
    // -------------------------

    public function DeleteController(): string
    {
        if(!isset($this->Command[2])){
            return $this->Colors->getColoredString('Warning:', 'white','red')
                . "Usage: php flo delete:controller <Name>";
        }
        return $this->DeleteControllerFor($this->Command[2]);
    }

    public function DeleteModel(): string
    {
        if(!isset($this->Command[2])){
            return $this->Colors->getColoredString('Warning:', 'white','red')
                . "Usage: php flo delete:model <Name>";
        }
        return $this->DeleteModelFor($this->Command[2]);
    }

    public function DeleteView(): string
    {
        if(!isset($this->Command[2]) || !isset($this->Command[3])){
            return $this->Colors->getColoredString('Warning:', 'white','red')
                . "Usage: php flo delete:view <Controller> <View>";
        }
        return $this->DeleteViewFor($this->Command[2], $this->Command[3]);
    }

    public function DeleteRoute(): string
    {
        if(!isset($this->Command[2])){
            return $this->Colors->getColoredString('Warning:', 'white','red')
                . "Usage: php flo delete:route <Name> [view]";
        }

        $name = $this->Command[2];
        $view = $this->Command[3] ?? 'index';

        $out = [];
        // delete view+method first, then model+unlink, then controller
        $out[] = $this->DeleteViewFor($name, $view);
        $out[] = $this->DeleteModelFor($name);
        $out[] = $this->DeleteControllerFor($name);

        return implode("\n", $out);
    }

    // -------------------------
    // HELPERS
    // -------------------------

    public function ShowAllCommands(): string{
        $out = [];
        foreach($this->AllCommands as $key => $value){
            $out[] = $key ." :\t ". $value;
        }
        return implode("\n", $out) . "\n";
    }
}