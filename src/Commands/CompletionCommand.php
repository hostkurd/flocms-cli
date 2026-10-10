<?php
declare(strict_types=1);

namespace FloCMS\CLI\Commands;

use FloCMS\CLI\Console\Command;
use FloCMS\CLI\Console\Input\Argument;
use FloCMS\CLI\Console\Input\Definition;

final class CompletionCommand extends Command
{
    public const SHELLS = ['bash', 'zsh', 'fish', 'powershell'];

    protected string $name = 'completion';

    protected string $description = 'Print a shell completion script for command names and options';

    protected string $help = <<<'TXT'
Completes `flo` / `./flo` (and `php flo` in PowerShell), including your
application's and packages' commands.

  bash:        php flo completion bash >> ~/.bashrc
  zsh:         php flo completion zsh > ~/.zfunc/_flo   (with fpath+=~/.zfunc; autoload -U compinit; compinit)
  fish:        php flo completion fish > ~/.config/fish/completions/flo.fish
  PowerShell:  php flo completion powershell | Out-String | Invoke-Expression   (add it to $PROFILE)

For bash and zsh, make the launcher executable (chmod +x flo) or define
alias flo="php flo".
TXT;

    protected function configure(Definition $definition): void
    {
        $definition->argument('shell', Argument::REQUIRED, 'bash, zsh, fish or powershell');
    }

    protected function handle(): int
    {
        $shell = strtolower((string) $this->argument('shell'));
        if (!in_array($shell, self::SHELLS, true)) {
            $this->invalid('Unsupported shell "' . $shell . '". Use: ' . implode(', ', self::SHELLS) . '.');
        }
        $this->output->write(self::script($shell));

        return self::SUCCESS;
    }

    public static function script(string $shell): string
    {
        return match ($shell) {
            'bash' => <<<'BASH'
# flo completion for bash (FloCMS CLI)
_flo_complete() {
    local line="${COMP_LINE:0:$COMP_POINT}" launcher cur
    local -a words
    read -ra words <<< "$line"
    [[ "$line" == *" " ]] && words+=("")
    launcher="${words[0]}"
    [[ -f "$launcher" ]] || launcher="flo"
    cur="${words[${#words[@]}-1]}"
    local IFS=$'\n'
    COMPREPLY=($(php "$launcher" _complete --index=$(( ${#words[@]} - 1 )) -- "${words[@]:1}" 2>/dev/null))
    # bash splits words at ":", so drop the part of the match it already has
    if [[ "$cur" == *:* ]]; then
        local prefix="${cur%"${cur##*:}"}"
        COMPREPLY=("${COMPREPLY[@]#"$prefix"}")
    fi
}
complete -o default -F _flo_complete flo ./flo

BASH,
            'zsh' => <<<'ZSH'
#compdef flo ./flo
# flo completion for zsh (FloCMS CLI)
_flo() {
    local launcher="${words[1]}"
    [[ -f "$launcher" ]] || launcher="flo"
    local -a items
    items=("${(@f)$(php "$launcher" _complete --index=$((CURRENT - 1)) -- "${(@)words[2,CURRENT]}" 2>/dev/null)}")
    if (( ${#items} )) && [[ -n "${items[1]}" ]]; then
        compadd -Q -a items
    else
        _files
    fi
}
compdef _flo flo ./flo

ZSH,
            'fish' => <<<'FISH'
# flo completion for fish (FloCMS CLI)
function __flo_complete
    set -l tokens (commandline -opc)
    set -l launcher $tokens[1]
    test -f "$launcher"; or set launcher flo
    php $launcher _complete --index=(count $tokens) -- $tokens[2..-1] (commandline -ct) 2>/dev/null
end
complete -c flo -f -a '(__flo_complete)'
complete -c ./flo -f -a '(__flo_complete)'

FISH,
            default => <<<'PS1'
# flo completion for PowerShell (FloCMS CLI): php flo <Tab>
Register-ArgumentCompleter -Native -CommandName php -ScriptBlock {
    param($wordToComplete, $commandAst, $cursorPosition)
    $words = @($commandAst.CommandElements | ForEach-Object { $_.Extent.Text })
    if ($words.Count -lt 2 -or $words[1] -notmatch '(^|[\\/])flo$') { return }
    $rest = @($words | Select-Object -Skip 2)
    if ($wordToComplete -eq '') { $rest += '' }
    php $words[1] _complete "--index=$($rest.Count)" -- @rest 2>$null | ForEach-Object {
        [System.Management.Automation.CompletionResult]::new($_, $_, 'ParameterValue', $_)
    }
}

PS1,
        };
    }
}
