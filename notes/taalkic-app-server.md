# Taalkic App Server

Taalkic is a PHP app server built on top of the Workerman library.

## URL Routing

Routes are defined in the `url-routes.php` file.  Callbacks for URL routes
are done via mapping to a single file.

The URL routing code supports all of the possible HTTP methods.

## HEAD requests

HTTP HEAD requests should fall back to the GET route for that URL.

## Helper Functions

For the convenience of developers there are a number of helper functions
that Taalkic provides for routes and templates.  These are global functions
that are loaded via the Composer `files` autoload feature.

To make escaping output easier an instance of laminas-escaper is created
using the charset passed into the App constructor.  Those functions are:

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
direct output, like route callbacks do.

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

## Number of workers

For the number of workers provided it the App constructor, there are two
possible values:

- `half` ( string ): which counts the number of cores on the system and uses half of that as the number of workers
- `<INT>` ( int ): a specific number of workers to run

## The network

Only bind to the 127.0.0.1 interface.  In production it is expected that
taalkic will run behind a traditional web server like Nginx, which would also
take care of TLS termination.
