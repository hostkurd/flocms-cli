<?php
namespace FloCMS\CLI;

use FloCMS\CLI\Core;
use FloCMS\CLI\Help;

/**
 * Gate Class for FLO-CLI
 */
final class Gate
{
    private $Command;
    private $AllCommands;

    public function __construct($data){
        $this->Command = $data;
        $this->AllCommands = $jsonData['cli-data']['all-commands'];
    }

    public function Execute(): void{
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
                case '--v':
                    echo 'Version 1.8';
                    break;
                case '--help':
                    echo $Core -> ShowAllCommands();
                    break;
                case 'create:controller':
                    echo $Core -> CreateController();
                    break;
                case 'create:model':
                    echo $Core -> CreateModel();
                    break;
                case 'create:view':
                    echo $Core -> CreateView();
                    break;
                case 'create:route':
                    echo $Core -> CreateRoute();
                    break;
                default:
                    echo "Command not valid \r\nRun \"php flo --help\" to view available FloCMS Commands.";
                    break;
            }
        }else{
            echo "\r\n \r\n";
            echo $Core -> ShowAllCommands();
        }

    }
    
}
