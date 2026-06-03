# PHP
- Minimum PHP version: 8.4

Avoid using exceptions when ever possible.

## Tooling
- Tests: Pest, minimum version 4.7.0
- Static Analysis: PHPStan, minimum version 2.2.0
- Code Style: php-cs-fixer, minimum version 3.95.2
	- Config from https://github.com/josephscott/phpcsfixer-config 
		- composer package: josephscott/phpcsfixer-config
		- minimum version 0.0.6

## Libraries
- Workerman, minimum version 5.2.0
- Fastroute, minimum version 1.3.0
- laminas-escaper, minimum version 2.18.0

## Composer
- Use a class map
- Do not allow plugins
- Enable as many optimizations as possible

## Performance

The goal is to have Taalkic provide the best possible performance while
maintaining the designated developer experience.
