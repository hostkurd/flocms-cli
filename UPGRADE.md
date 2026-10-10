# Upgrading

## "Your flo launcher is outdated"

The `flo` file in your project root is copied once by
`composer create-project`. `composer update` never changes it, so a site
created with an older FloCMS keeps its old launcher. Old launchers send every
command to this package, which only knows `create:*` and `delete:*`, so newer
commands such as `php flo api:key:create` fail with:

    Command "api:key:create" not found.
    Your flo launcher is outdated ...

### Fix

1. In your project's `composer.json`, require `"hostkurd/flocms-cli": "2.0.0"`
   (and `"hostkurd/flocms-api": "1.2.0"` if you use the API), then run
   `composer update`.
2. Download the `flo` file of FloCMS 1.7 or later from
   <https://github.com/hostkurd/FloCMS/blob/main/flo> and replace the `flo`
   file in your project root with it. The new launcher needs flocms-cli 2.0.
3. See flocms-cli's UPGRADE.md section "1.x to 2.0" and the FloCMS 1.7
   changelog for the remaining steps.

From FloCMS 1.7 on, the root `flo` only boots the command kernel from
`vendor/`, so `composer update` delivers every new command and fix and the file
never needs replacing again.

### Until you upgrade

API commands from `hostkurd/flocms-api` can run directly:

    php vendor/bin/flo-api api:list
    php vendor/bin/flo-api api:key:create "Mobile app" --scopes=properties.read
