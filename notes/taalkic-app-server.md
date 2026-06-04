# Taalkic App Server

Taalkic is a PHP app server built on top of the Workerman library.


## App Constructor Args

When creating a new Taalkic\App the constructor supports the following args:

- `workers` ( string|int ) optional: default to the `half` value
- `port` ( int ) optional: default to port 4200
- `charset` ( string ) optional: defaul to `utf-8`
- `routes` ( string ) required: path to the file that registers the URL routes
- `template_dir` ( string ) required: base path for templates

If a required arg is not provided, exit with an error message and write the
same message to the error log.

## URL Routing

Routes are defined in the `url-routes.php` file.  Callbacks for URL routes
are done via mapping to a single file.

The App is given the *path* to the routes file via the `routes` arg, not a
pre-built router object.  The App loads that file inside each worker process
( see Reload ) and provides a `$router` in scope for it to register routes on.
So the routes file uses `$router` directly and is never required by the
developer's server file.

The URL routing code supports all of the possible HTTP methods:

- HEAD
- GET
- POST
- PUT
- DELETE
- OPTIONS
- PATCH

## HEAD requests

HTTP HEAD requests should fall back to the GET route for that URL.  When that
happens strip the body from the response to match the HTTP spec for
responding to HEAD requests.

## Error Handlers

If there are no 404 and 405 error handlers declared, return a plain error page.

## Helper Functions

For the convenience of developers there are a number of helper functions
that Taalkic provides for routes and templates.  These are global functions
that are loaded via the Composer `files` autoload feature.

To make escaping output easier an instance of laminas-escaper that is created
once using the charset passed into the App constructor.  Those functions are:

- `esc_html( string )`
- `esc_html_attr( string )`
- `esc_js( string ) `
- `esc_css( string )`
- `esc_url( string )`

The App object will keep the character set string in `Taalkic\App::charset`
so that the laminas-escaper constructor can pull it from there.

A very minimal templating feature is available via a single function:

- `template( file_path, data )`

The `template()` helper calls a template PHP file, which will also do
direct output, like route callbacks do.  The `file_path` is always relative
to the Taalkic\App::template_dir base path.

## Route Callbacks

The file for the route callback needs to be isolated from the rest of the
environment.  Meaning the only variable in scope when a route runs is the
`$here` variable.  A route can interact with the details and data of the request
via the `$here` object.  The `$here` object provides the following:

- `$here->request` ( object ) which is the `$request` from Workerman
- `$here->response` ( object ) which is the `$response` from Workerman
- `$here->params` ( array ) which contains the URL placeholders from FastRoute

A route callback is much like a traditional PHP file, so you will need to
capture the output.  You will also need to make sure to apply the
`$here->response` details, as the route can use that to apply changes to the
`$response` object for Workerman.

Routes can mutate the Workerman Response object via `$here->response`.

## Number of workers

For the number of workers provided it the App constructor, there are two
possible values:

- `half` ( string ): which counts the number of cores on the system and uses half of that as the number of workers - if that ends up being an odd number round down
- `<INT>` ( int ): a specific number of workers to run

The minimum number of workers is 2.

## The network

Only bind to the 127.0.0.1 interface.  In production it is expected that
taalkic will run behind a traditional web server like Nginx, which would also
take care of TLS termination.

## Reload

For production, `make reload` ( `php server.php reload -g` ) gracefully picks
up code changes without dropping requests.  Workerman finishes any in-flight
requests, then re-forks each worker one at a time.

Because the workers are recycled one at a time, there is a brief window where
old and new workers run side by side, so a just-added route can still return
404 from a worker that has not been recycled yet.  Workerman's reload command
returns before that finishes, so `make reload` waits until every old worker
has exited before it returns.  Once it returns, all workers are running the
new code.

A graceful reload waits for every connection on a worker to close before that
worker exits, and Workerman does not close idle ones itself.  An idle
keep-alive connection ( a browser, or Nginx's upstream pool ) would otherwise
pin the worker open until the keep-alive timeout ( around 90 seconds ) and,
because workers are recycled one at a time, stall the whole reload.  The App
closes idle connections itself when a worker stops ( via `onWorkerStop` ), so a
reload is not held up.  Connections that are part way through a request are
left to finish, and any buffered response is flushed before its connection is
closed, so no request is dropped.

The reload signal only re-forks the worker processes; the master process is
never re-executed.  Workers are re-forked from the master's memory, so
anything built in the master before the server starts would be frozen for the
life of the master and never reloaded.

To make a reload pick up changes correctly, the App loads the routes file
inside each worker ( in Workerman's `onWorkerStart` ), not in the master.
That covers all three kinds of change:

- the URL routes file is re-loaded when the worker re-forks
- the route callback files are included per request in the fresh worker
- the template files are included per request in the fresh worker

Leave the OPcache CLI ( `opcache.enable_cli` ) turned off, which is the
default.  The route callback and template files are `include`d per request, so
with CLI OPcache off PHP always reads the current file from disk.  If CLI
OPcache were enabled with `opcache.validate_timestamps=0`, those includes
would be served from a frozen opcode cache and a reload would not pick up the
changes.
