<?php
namespace FloCMS\CLI;

use FloCMS\CLI\Core;
use FloCMS\CLI\Help;

/**
 * Gate Class for FLO-CLI
 */
final class Gate
{
    public const UPGRADE_URL = 'https://github.com/hostkurd/flocms-cli/blob/main/UPGRADE.md';

    private $Command;
    private $AllCommands;

    public function __construct($data){
        $this->Command = $data;
        $jsdata = json_decode(file_get_contents(__DIR__."/data.json"), true);
        $this->AllCommands = $jsdata['cli-data']['all-commands'];
    }

    /**
     * Run the command and return the process exit code.
     */
    public function Execute(): int{
        //$colors = new Colors();
        $Help = new Help();

        $data = json_decode(file_get_contents(__DIR__."/data.json"), true);
        $Core = new Core($this->Command, $data);

        $num_params = count($this->Command);
        if($num_params<2){
            echo "Flo CLI Version 1.0\nFloCMS Command line interface.";
        }
        
        if (isset($this->Command[1])){
            switch($this->Command[1]){
                case '--v':               echo 'Version 1.8'; break;
                case '--help':            echo $Core -> ShowAllCommands(); break;
                case 'create:controller': echo $Core -> CreateController(); break;
                case 'create:model':      echo $Core -> CreateModel(); break;
                case 'create:view':       echo $Core -> CreateView(); break;
                case 'create:route':      echo $Core -> CreateRoute(); break;
                case 'delete:controller': echo $Core->DeleteController(); break;
                case 'delete:model':      echo $Core->DeleteModel(); break;
                case 'delete:view':       echo $Core->DeleteView(); break;
                case 'delete:route':      echo $Core->DeleteRoute(); break;
                default:
                    fwrite(STDERR, self::UnknownCommandMessage((string) $this->Command[1]));
                    return 1;
            }
        }else{
            echo "\r\n \r\n";
            echo $Core -> ShowAllCommands();
        }

        return 0;
    }

    /**
     * Commands such as api:* and key:generate are handled by newer flo launchers
     * (the "flo" file in the project root). That file is copied once by
     * composer create-project and never updated, so an old copy sends them here.
     */
    public static function UnknownCommandMessage(string $command): string
    {
        $lines = [
            'Command "' . $command . '" not found.',
            '',
            'Your flo launcher is outdated: the "flo" file in your project root comes from an',
            'older FloCMS version and does not know newer commands. Replace it with the "flo"',
            'file from FloCMS 1.7 or later, see:',
            '  ' . self::UPGRADE_URL,
        ];

        if (str_starts_with($command, 'api:')) {
            $lines[] = '';
            $lines[] = 'Until then, run API commands directly:';
            $lines[] = '  php vendor/bin/flo-api ' . $command;
        }

        $lines[] = '';
        $lines[] = 'Run "php flo --help" to view the commands this launcher supports.';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
    
}
