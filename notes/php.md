# PHP
- Minimum PHP version: 8.4

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

## Style

Avoid using exceptions when ever possible.  Prefer return values, or in the
case of object tracking the error condition internally.

Write code in a style so that it is easy for a human to tell what the code is
doing at a glance.

As a general rule, simple code is better than complex or clever code.  Keeping
code simple also helps it be more understandable and reliable.

Avoid ternary statements.  In general a simple if/else is the way to go for
conditional checks.

When you have a default condition, this style is preferred:

```php
$person = 'me'; // default
if ( $other_condition ) {
	$person = 'you';
}
```

Return values should be simple variables, they should not contain expressions.

```php
// Do this
return $count;

// DO NOT USE THIS STYLE
return $count === 1;
```

## Performance

The goal is to have Taalkic provide the best possible performance while
maintaining the designated developer experience.
