# Taalkic App Server

Taalkic is a PHP app server built on top of the Workerman library.


## App Constructor Args

When creating a new Taalkic\App the constructor supports the following args:

- `workers` ( string|int ) optional: default to the `half` value
- `port` ( int ) optional: default to port 4200
- `charset` ( string ) optional: defaul to `utf-8`
- `routes` ( string ) required: path to the file that registers the URL routes
- `template_dir` ( string ) required: base path for templates
- `watch` ( array ) optional: paths to watch for changes in development ( see Dev mode )

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

HTTP HEAD requests should fall back to the GET route for that URL.  FastRoute
already does this fallback when no HEAD route is declared, so taalkic relies on
it rather than repeating the logic.  FastRoute does not touch the body, so
taalkic strips the body from the response to match the HTTP spec for responding
to HEAD requests.

## Missing callback files

A route can be declared for a callback file that does not exist ( a typo, or a
route added before its file ).  This is a server-side misconfiguration, so a
request to that route returns a plain `500 Internal Server Error` rather than
the blank `200` an empty `include()` would produce.  When the routes are loaded
( on worker start, so also on every reload ) any declared route or error
handler file that does not exist is written to the error log, so the problem
shows up right away instead of only on the first matching request.

These messages go to the PHP error log via `error_log()`.  In daemon mode set
the `error_log` ini directive to a file, otherwise the messages are written to
stderr, which the daemon discards.

## Error Handlers

If there are no 404 and 405 error handlers declared, return a plain error page.
The same applies if a handler is declared but its file is missing: the request
still returns that handler's status ( e.g. 404 ) using the plain error page.

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

The resolved path is confined to `template_dir` ( via `realpath()` ): a path
that escapes the base ( `../` traversal ), a stream wrapper ( `php://`,
`phar://`, `data://` ), or a file that does not exist is rejected and the
request returns a 500.  This keeps `template()` safe even if a developer builds
the path from request data ( e.g. `template( $here->params['page'] . '.php' )` ),
which would otherwise be a local/remote file inclusion.

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

## Dev mode

For local development, `make dev` runs the server in the foreground and
reloads automatically on file changes, so there is no need to run `make reload`
or `make restart` by hand.

It works by passing a `watch` array of paths to the App constructor.  When that
is set, the App starts an extra monitor process ( a Workerman worker with no
socket, marked not reloadable so the reload it triggers does not restart it )
that scans those paths for changed PHP files once a second and, on any change,
reloads the workers by signalling the master.

The demo's `server.php` only sets `watch` when the `TAALKIC_DEV` environment
variable is `1`, which `make dev` sets.  `make start` leaves it off so
production does not scan files.

What a change picks up matches Reload below:

- route callback and template files are already live on the next request,
  because they are included per request, so the watcher's reload is not even
  needed for them
- the routes file and the per-worker classes are picked up by the reload
- `src/app.php`, the helper functions, and `server.php` load in the master, so
  a reload does not pick them up; editing those still needs a `make restart`

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

## OPcache and JIT

Workerman runs as a long-lived CLI process, but the CLI OPcache is off by
default.  With it off, PHP re-lexes, re-parses, and re-compiles every
per-request `include()` ( the routes file, route callbacks, and templates )
from disk on every request.  Turning the CLI OPcache on so those compiled
opcodes are cached is the single biggest throughput win, and tracing JIT then
compiles the hot path to native code once per worker.  The `make start`,
`make dev`, and `make restart` targets enable both ( see the `OPCACHE_PROD`
and `OPCACHE_DEV` variables in the Makefile ).

Production and development use different settings, matching how each picks up
changes.

Production ( `make start` ) freezes the cache with
`opcache.validate_timestamps=0`.  Nothing is re-checked per request, so there
is no per-request `stat()` — the fastest setting.  Production does not watch
for changes; `make reload` is what picks them up, and the App makes a reload
work with a frozen cache: on every worker start ( which a reload re-runs ) it
calls `opcache_invalidate( $file, true )` on the routes file, every route
callback, the error handlers, and every template under `template_dir`, so each
recompiles from disk on its next include.  taalkic's own `src/` files are left
cached; changing those needs `make restart`, which re-execs the master with a
fresh cache.  This matches the three kinds of update:

- `make reload` picks up the routes file, route callbacks, and templates
- `make restart` picks up `src/` ( and Workerman )

`opcache_reset()` is deliberately not used to force the recompile.  Inside a
long-lived worker it does not complete ( a reset only finishes when a fresh
process attaches to the cache ), so it disables caching for the rest of that
worker's life and silently drops throughput back to the no-OPcache level.
`opcache_invalidate()` is targeted: it drops a single script and lets the next
include recompile and re-cache it, leaving the rest of the cache warm.

Development ( `make dev` ) instead uses `opcache.validate_timestamps=1` with
`opcache.revalidate_freq=0`, so OPcache re-checks each included file's modified
time and an edited route or template shows up on the very next request with no
reload at all.  That costs one `stat()` per include ( a few percent on a
trivial route ), which is the right trade while iterating.  JIT is off in dev
so an edit is not followed by a re-trace pause.
